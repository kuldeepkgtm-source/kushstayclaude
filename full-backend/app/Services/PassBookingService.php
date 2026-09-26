<?php

namespace App\Services;

use App\Exceptions\BookingConflictException;
use App\Exceptions\InsufficientPassBalanceException;
use App\Models\Bed;
use App\Models\Booking;
use App\Models\Pass;
use App\Models\PassBooking;
use App\Models\PassPayment;
use App\Models\PassUpgradeRate;
use App\Models\Room;
use Illuminate\Support\Facades\DB;

/**
 * "Book with pass": the same bed inventory as every other booking source (WhatsApp, Direct,
 * OTA, iCal) — there is deliberately no separate pass inventory anywhere in this class. Every
 * category check below calls AvailabilityService, and every created booking is a normal row in
 * the shared `bookings`/`booking_beds` tables (source = 'Pass'), so a bed occupied by a pass
 * guest is exactly as unavailable to a WhatsApp guest as any other booking.
 */
class PassBookingService
{
    private const CATEGORIES = ['NAC-Upper', 'NAC-Lower', 'AC-Upper', 'AC-Lower'];

    public function __construct(
        private AvailabilityService $availability,
        private PassLedgerService $ledger,
    ) {}

    /**
     * Read-only preview: for each of the 4 categories, is it available for these dates, and
     * what would the upgrade fee be relative to this pass's base category? The frontend must
     * show exactly this — never silently move the customer into a different category.
     */
    public function previewAvailability(Pass $pass, string $checkIn, string $checkOut): array
    {
        $nights = (new \DateTime($checkOut))->diff(new \DateTime($checkIn))->days;
        $base = $pass->product->category;
        $rates = PassUpgradeRate::where('property_id', $pass->property_id)->where('base_category', $base)->get()->keyBy('target_category');

        $result = [];
        foreach (self::CATEGORIES as $category) {
            [$roomId, $position] = $this->roomAndPositionFor($pass->property_id, $category);
            $free = $this->availability->availableBeds($roomId, $checkIn, $checkOut, $position);
            $feePerNight = (int) ($rates[$category]->fee_per_night_paise ?? 0);

            $result[$category] = [
                'available' => $free->isNotEmpty(),
                'fee_per_night_paise' => $feePerNight,
                'upgrade_total_paise' => $feePerNight * $nights,
            ];
        }

        return ['nights' => $nights, 'remaining_days' => $pass->remaining_days, 'options' => $result];
    }

    /**
     * Creates the real booking. If the upgrade fee is 0, confirms and deducts pass days
     * immediately. If > 0, the booking is created but left Pending with no days deducted yet —
     * confirmUpgradePayment() below is what actually grants the stay, exactly mirroring
     * PassPurchaseService's "don't grant until payment is verified" rule.
     */
    public function book(Pass $lockedContext, string $checkIn, string $checkOut, string $targetCategory): array
    {
        return DB::transaction(function () use ($lockedContext, $checkIn, $checkOut, $targetCategory) {
            $pass = Pass::lockForUpdate()->findOrFail($lockedContext->id);

            if ($pass->status !== 'active') {
                throw new \RuntimeException("Pass {$pass->pass_ref} is not active (status: {$pass->status}).");
            }
            if ($pass->expires_at && $checkIn > $pass->expires_at->toDateString()) {
                throw new \RuntimeException('This pass has expired for the requested dates.');
            }

            $nights = (new \DateTime($checkOut))->diff(new \DateTime($checkIn))->days;
            if ($nights > $pass->remaining_days) {
                throw new InsufficientPassBalanceException($pass->remaining_days, $nights);
            }

            [$roomId, $position] = $this->roomAndPositionFor($pass->property_id, $targetCategory);
            $property = \App\Models\Property::findOrFail($pass->property_id);
            if (! $property->isLive()) {
                throw new \RuntimeException("Property '{$property->name}' is not currently accepting bookings (status: {$property->status}).");
            }
            $bedIds = Bed::where('room_id', $roomId)->where('position', $position)->where('active', true)->lockForUpdate()->pluck('id');

            $freeBedId = null;
            foreach ($bedIds as $bedId) {
                if ($this->availability->isBedFree($bedId, $checkIn, $checkOut)) {
                    $freeBedId = $bedId;
                    break;
                }
            }
            if (! $freeBedId) {
                throw new BookingConflictException("No available {$targetCategory} bed for these dates.");
            }

            $feePerNight = (int) (PassUpgradeRate::where('property_id', $pass->property_id)
                ->where('base_category', $pass->product->category)->where('target_category', $targetCategory)
                ->value('fee_per_night_paise') ?? 0);
            $upgradeTotalPaise = $feePerNight * $nights;
            $requiresPayment = $upgradeTotalPaise > 0;

            $booking = Booking::create([
                'booking_ref' => $this->nextBookingRef($pass->property_id),
                'property_id' => $pass->property_id,
                'customer_id' => $pass->customer_id,
                'customer_name' => $pass->customer->name,
                'customer_phone' => $pass->customer->phone,
                'source' => 'Pass',
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'guest_count' => 1,
                'booking_type' => 'individual',
                'room_id' => $roomId,
                'subtotal' => $upgradeTotalPaise / 100,
                'total' => $upgradeTotalPaise / 100,
                'amount_paid' => 0,
                'balance' => $upgradeTotalPaise / 100,
                'payment_status' => $requiresPayment ? 'Unpaid' : 'Paid',
                'booking_status' => $requiresPayment ? 'Pending' : 'Confirmed',
                'special_request' => "Pass {$pass->pass_ref} ({$pass->product->category} base, {$targetCategory} used)",
            ]);
            $booking->beds()->attach([$freeBedId]);

            $passBooking = PassBooking::create([
                'pass_id' => $pass->id,
                'booking_id' => $booking->id,
                'base_category' => $pass->product->category,
                'used_category' => $targetCategory,
                'nights' => $nights,
                'days_consumed' => $requiresPayment ? 0 : $nights,
                'upgrade_fee_paise' => $upgradeTotalPaise,
                'upgrade_payment_status' => $requiresPayment ? 'pending' : 'not_required',
            ]);

            if (! $requiresPayment) {
                $this->ledger->record($pass, 'booking', -$nights, $passBooking->id, "Booking {$booking->booking_ref}");
            }
            // else: days are deducted only in confirmUpgradePayment(), matching the purchase flow's rule.

            return ['booking' => $booking->fresh('beds'), 'pass_booking' => $passBooking, 'requires_payment' => $requiresPayment, 'upgrade_total_paise' => $upgradeTotalPaise];
        });
    }

    public function confirmUpgradePayment(int $passBookingId, array $paymentPayload): PassBooking
    {
        return DB::transaction(function () use ($passBookingId, $paymentPayload) {
            $pb = PassBooking::lockForUpdate()->findOrFail($passBookingId);
            if ($pb->upgrade_payment_status !== 'pending') {
                throw new \RuntimeException('This upgrade payment is not pending.');
            }

            PassPayment::create([
                'pass_booking_id' => $pb->id, 'purpose' => 'upgrade_fee', 'amount_paise' => $pb->upgrade_fee_paise,
                'method' => $paymentPayload['method'] ?? null, 'status' => 'successful',
                'gateway_reference' => $paymentPayload['gateway_reference'] ?? null, 'confirmed_at' => now(),
            ]);

            $pass = Pass::lockForUpdate()->findOrFail($pb->pass_id);
            if ($pb->nights > $pass->remaining_days) {
                throw new InsufficientPassBalanceException($pass->remaining_days, $pb->nights);
            }

            $this->ledger->record($pass, 'booking', -$pb->nights, $pb->id, "Booking {$pb->booking->booking_ref} (upgrade)");
            $pb->update(['upgrade_payment_status' => 'paid', 'days_consumed' => $pb->nights]);
            $pb->booking->update(['booking_status' => 'Confirmed', 'payment_status' => 'Paid', 'amount_paid' => $pb->upgrade_fee_paise / 100, 'balance' => 0]);

            return $pb->fresh();
        });
    }

    /** Failed/abandoned upgrade payment: release the tentative bed, no days were ever deducted. */
    public function failUpgradePayment(int $passBookingId): PassBooking
    {
        $pb = PassBooking::findOrFail($passBookingId);
        $pb->booking->update(['booking_status' => 'Cancelled', 'payment_status' => 'Refunded']);
        $pb->update(['upgrade_payment_status' => 'failed']);

        return $pb;
    }

    /** Restores ledger days on eligible cancellation. Upgrade-fee refund is tracked separately per policy. */
    public function cancel(PassBooking $pb, string $reason = 'Cancelled'): PassBooking
    {
        return DB::transaction(function () use ($pb, $reason) {
            $pass = Pass::lockForUpdate()->findOrFail($pb->pass_id);
            $pb->booking->update(['booking_status' => 'Cancelled']);

            if ($pb->days_consumed > 0) {
                $this->ledger->record($pass, 'cancellation', $pb->days_consumed, $pb->id, $reason);
                $pb->update(['days_consumed' => 0]);
            }
            if ($pb->upgrade_payment_status === 'paid') {
                // Cash refund execution is out of scope (same simulated boundary as the rest of the
                // payment system) — recorded here so the ledger/UI reflect it accurately.
                $pb->update(['upgrade_payment_status' => 'refunded']);
            }

            return $pb->fresh();
        });
    }

    private function roomAndPositionFor(int $propertyId, string $category): array
    {
        [$acPart, $position] = explode('-', $category);
        $room = Room::where('property_id', $propertyId)->where('is_ac', $acPart === 'AC')->firstOrFail();

        return [$room->id, $position];
    }

    private function nextBookingRef(int $propertyId): string
    {
        $ymd = now()->format('Ymd');
        $countToday = Booking::where('property_id', $propertyId)->where('booking_ref', 'like', "BK-{$ymd}-%")->count();

        return sprintf('BK-%s-%04d', $ymd, $countToday + 1);
    }
}
