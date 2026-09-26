<?php

namespace App\Services;

use App\Exceptions\BookingConflictException;
use App\Exceptions\PassException;
use App\Models\Customer;
use App\Models\Pass;
use App\Models\PassBooking;
use App\Models\PassLedger;
use App\Models\PassProduct;
use App\Models\PassUpgradeRate;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Everything about the 30-day annual pass: purchase (with the 150-pass race-protected limit),
 * activation, the pass ledger, and booking-with-a-pass (which reuses AvailabilityService,
 * HoldService, and BookingService verbatim — a pass booking is a REAL row in `bookings`, just
 * paid for in pass-days instead of cash). No pass-specific inventory exists anywhere.
 */
class PassService
{
    public function __construct(
        private AvailabilityService $availability,
        private HoldService $holds,
        private BookingService $bookings,
    ) {}

    public function grandOpeningActive(int $propertyId): bool
    {
        return (bool) (Setting::where('property_id', $propertyId)->where('key', 'pass_grand_opening_active')->value('value') ?? true);
    }

    public function grandOpeningLimit(int $propertyId): int
    {
        return (int) (Setting::where('property_id', $propertyId)->where('key', 'pass_grand_opening_limit')->value('value') ?? 150);
    }

    public function grandOpeningSoldCount(int $propertyId): int
    {
        return (int) (Setting::where('property_id', $propertyId)->where('key', 'pass_grand_opening_sold_count')->value('value') ?? 0);
    }

    /**
     * Creates the Pass row in `payment_pending` status and reserves its sequence number
     * atomically. This is the race-condition-protected step: the counter row is locked for the
     * whole transaction, so a second simultaneous call for the last pass sees the updated count
     * (post-commit of the first) and is correctly rejected — exactly the same pattern as bed
     * locking in BookingService, applied to a shared counter instead of a bed row.
     *
     * @throws PassException if the Grand Opening batch is sold out
     */
    public function initiatePurchase(int $propertyId, int $passProductId, int $customerId, bool $useGrandOpeningPrice): Pass
    {
        return DB::transaction(function () use ($propertyId, $passProductId, $customerId, $useGrandOpeningPrice) {
            $counterKey = 'pass_grand_opening_sold_count';
            $counter = Setting::where('property_id', $propertyId)->where('key', $counterKey)->lockForUpdate()->first();
            if (! $counter) {
                // First-ever purchase attempt — create the counter row now, still inside this
                // locked transaction, so a concurrent first call can't also "create" it.
                $counter = Setting::create(['property_id' => $propertyId, 'key' => $counterKey, 'value' => 0]);
                $counter = Setting::where('id', $counter->id)->lockForUpdate()->first();
            }

            $limit = $this->grandOpeningLimit($propertyId);
            $sold = (int) $counter->value;
            if ($sold >= $limit) {
                throw new PassException('GRAND OPENING PASSES SOLD OUT');
            }

            $nextNumber = $sold + 1;
            $counter->update(['value' => $nextNumber]);

            $product = PassProduct::findOrFail($passProductId);
            $price = $useGrandOpeningPrice ? $product->grand_opening_price_paise : $product->normal_price_paise;

            return Pass::create([
                'pass_number' => $nextNumber,
                'property_id' => $propertyId,
                'pass_product_id' => $product->id,
                'customer_id' => $customerId,
                'price_paid_paise' => $price,
                'was_grand_opening_price' => $useGrandOpeningPrice,
                'total_days' => $product->total_entitlement_days,
                'days_used' => 0,
                'status' => 'payment_pending',
            ]);
        });
    }

    /**
     * Called only by the payment confirmation path (PassPaymentController), never directly from
     * a "purchase succeeded" message the frontend sends on its own.
     */
    public function activateAfterPayment(Pass $pass): Pass
    {
        return DB::transaction(function () use ($pass) {
            $pass = Pass::lockForUpdate()->findOrFail($pass->id);
            if ($pass->status !== 'payment_pending') {
                throw new PassException("Pass {$pass->display_id} is not awaiting payment (status: {$pass->status}).");
            }

            $activatedAt = now()->toDateString();
            $expiresAt = now()->addYear()->subDay()->toDateString(); // 06 Sep 2026 -> 05 Sep 2027, via real calendar math

            $pass->update(['status' => 'active', 'activated_at' => $activatedAt, 'expires_at' => $expiresAt]);

            PassLedger::create([
                'pass_id' => $pass->id, 'event_type' => 'grant', 'change_days' => $pass->total_days,
                'balance_after' => $pass->total_days, 'reason' => 'Pass purchased and activated.',
            ]);

            return $pass->fresh();
        });
    }

    public function markPaymentFailed(Pass $pass): Pass
    {
        $pass->update(['status' => 'cancelled']);

        return $pass;
    }

    private function nights(string $checkIn, string $checkOut): int
    {
        // Hotel-style nights, identical rule to bed pricing: checkout date is never consumed.
        return (new \DateTime($checkOut))->diff(new \DateTime($checkIn))->days;
    }

    public function upgradeFeePaise(int $propertyId, string $baseCategory, string $targetCategory, int $nights): int
    {
        $rate = PassUpgradeRate::where('property_id', $propertyId)
            ->where('base_pass_category', $baseCategory)->where('target_bed_category', $targetCategory)->first();

        return ($rate->fee_per_night_paise ?? 0) * $nights;
    }

    /** Which bed categories are actually available for these dates, with the resulting upgrade fee for this pass. */
    public function availableCategoriesForPass(Pass $pass, string $checkIn, string $checkOut): array
    {
        $nights = $this->nights($checkIn, $checkOut);
        $rooms = ['NAC-Upper' => 'NAC', 'NAC-Lower' => 'NAC', 'AC-Upper' => 'AC', 'AC-Lower' => 'AC'];
        $result = [];
        foreach ($rooms as $category => $roomCode) {
            $room = \App\Models\Room::where('property_id', $pass->property_id)->where('code', $roomCode)->first();
            if (! $room) {
                continue;
            }
            $position = str_contains($category, 'Upper') ? 'Upper' : 'Lower';
            $free = $this->availability->availableBeds($room->id, $checkIn, $checkOut, $position);
            $result[$category] = [
                'available' => $free->count() > 0,
                'upgrade_fee_paise' => $this->upgradeFeePaise($pass->property_id, $pass->product->bed_category, $category, $nights),
            ];
        }

        return ['nights' => $nights, 'categories' => $result];
    }

    /**
     * Books a stay against a pass. If there's no upgrade fee, this confirms immediately (still
     * inside one transaction with the bed lock from BookingService::createBooking). If there IS
     * an upgrade fee, this only creates a HOLD + a pending PassBooking — pass days are NOT
     * deducted and no `bookings` row exists yet until payment is confirmed (see
     * confirmPassBookingPayment). This mirrors the spec's "UPGRADE PAYMENT PENDING" state exactly.
     *
     * @throws PassException|BookingConflictException
     */
    public function bookWithPass(Pass $pass, string $bedCategory, string $checkIn, string $checkOut, int $roomId): array
    {
        return DB::transaction(function () use ($pass, $bedCategory, $checkIn, $checkOut, $roomId) {
            $lockedPass = Pass::lockForUpdate()->findOrFail($pass->id);
            $today = now()->toDateString();

            if ($lockedPass->status !== 'active') {
                throw new PassException("This pass is {$lockedPass->status}, not active.");
            }
            if (! $lockedPass->expires_at || $checkIn > $lockedPass->expires_at->toDateString()) {
                throw new PassException('This pass has expired for the requested dates.');
            }

            $nights = $this->nights($checkIn, $checkOut);
            $remaining = $lockedPass->total_days - $lockedPass->days_used;
            if ($nights > $remaining) {
                throw new PassException("You have only {$remaining} pass day(s) remaining, this stay needs {$nights}.");
            }

            $position = str_contains($bedCategory, 'Upper') ? 'Upper' : 'Lower';
            $freeBeds = $this->availability->availableBeds($roomId, $checkIn, $checkOut, $position);
            if ($freeBeds->isEmpty()) {
                throw new PassException("{$bedCategory} is no longer available for these dates.");
            }
            $bedId = $freeBeds->first()->id;

            $upgradeFee = $this->upgradeFeePaise($pass->property_id, $lockedPass->product->bed_category, $bedCategory, $nights);

            if ($upgradeFee > 0) {
                $hold = $this->holds->createHold($pass->property_id, [$bedId], $checkIn, $checkOut, 'pass-'.$pass->id.'-'.now()->timestamp, 15);
                $passBooking = PassBooking::create([
                    'pass_id' => $pass->id, 'hold_id' => $hold['holds'][0]->id, 'bed_category' => $bedCategory,
                    'nights' => $nights, 'days_consumed' => $nights, 'upgrade_fee_paise' => $upgradeFee,
                    'status' => 'upgrade_payment_pending',
                ]);

                return ['status' => 'upgrade_payment_pending', 'pass_booking_id' => $passBooking->id, 'upgrade_fee_paise' => $upgradeFee, 'hold_expires_at' => $hold['expires_at']];
            }

            // No upgrade fee — confirm immediately, same transaction.
            $booking = $this->bookings->createBooking([
                'property_id' => $pass->property_id, 'customer_name' => $pass->customer->name, 'customer_phone' => $pass->customer->phone,
                'source' => 'Pass', 'check_in' => $checkIn, 'check_out' => $checkOut, 'guest_count' => 1,
                'booking_type' => 'individual', 'room_id' => $roomId, 'bed_ids' => [$bedId],
            ]);

            $passBooking = PassBooking::create([
                'pass_id' => $pass->id, 'booking_id' => $booking->id, 'bed_category' => $bedCategory,
                'nights' => $nights, 'days_consumed' => $nights, 'upgrade_fee_paise' => 0, 'status' => 'confirmed',
            ]);

            $this->deductDays($lockedPass, $nights, $passBooking->id, "Booking for {$checkIn} to {$checkOut}.");

            return ['status' => 'confirmed', 'pass_booking_id' => $passBooking->id, 'booking_id' => $booking->id, 'upgrade_fee_paise' => 0];
        });
    }

    /** Called once the upgrade-fee payment is confirmed — converts the hold into a real booking and deducts days. */
    public function confirmPassBookingPayment(PassBooking $passBooking): PassBooking
    {
        return DB::transaction(function () use ($passBooking) {
            $passBooking = PassBooking::lockForUpdate()->findOrFail($passBooking->id);
            if ($passBooking->status !== 'upgrade_payment_pending') {
                throw new PassException('This pass booking is not awaiting payment.');
            }

            $hold = \App\Models\Hold::findOrFail($passBooking->hold_id);
            $pass = Pass::lockForUpdate()->findOrFail($passBooking->pass_id);

            $booking = $this->bookings->createBooking([
                'property_id' => $pass->property_id, 'customer_name' => $pass->customer->name, 'customer_phone' => $pass->customer->phone,
                'source' => 'Pass', 'check_in' => $hold->check_in->toDateString(), 'check_out' => $hold->check_out->toDateString(),
                'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $hold->bed->room_id, 'bed_ids' => [$hold->bed_id],
            ]);

            $passBooking->update(['booking_id' => $booking->id, 'status' => 'confirmed']);
            $this->deductDays($pass, $passBooking->days_consumed, $passBooking->id, 'Upgrade payment confirmed, booking finalized.');
            $this->holds->release($hold->id);

            return $passBooking->fresh();
        });
    }

    /** Payment failed/cancelled: release the hold, touch nothing else. Pass days were never deducted for this attempt. */
    public function releaseFailedPassBooking(PassBooking $passBooking): void
    {
        if ($passBooking->hold_id) {
            $this->holds->release($passBooking->hold_id);
        }
        $passBooking->update(['status' => 'cancelled']);
    }

    public function cancelPassBooking(PassBooking $passBooking, bool $refundUpgradeFee): PassBooking
    {
        return DB::transaction(function () use ($passBooking, $refundUpgradeFee) {
            $passBooking = PassBooking::lockForUpdate()->findOrFail($passBooking->id);
            if ($passBooking->booking_id) {
                $this->bookings->cancel($passBooking->booking->fresh());
            }

            $pass = Pass::lockForUpdate()->findOrFail($passBooking->pass_id);
            $newUsed = max(0, $pass->days_used - $passBooking->days_consumed);
            $newBalance = $pass->total_days - $newUsed;
            $pass->update(['days_used' => $newUsed]);

            PassLedger::create([
                'pass_id' => $pass->id, 'event_type' => 'cancellation', 'change_days' => $passBooking->days_consumed,
                'balance_after' => $newBalance, 'pass_booking_id' => $passBooking->id,
                'reason' => "Cancellation reversal for pass booking #{$passBooking->id}.",
            ]);

            $passBooking->update(['status' => 'cancelled']);

            // Upgrade-fee refund is tracked separately from pass-day accounting, per spec — this
            // only flags intent; actually issuing money back is the gateway's refund call.
            if ($refundUpgradeFee) {
                \App\Models\PassPayment::where('payable_type', PassBooking::class)->where('payable_id', $passBooking->id)
                    ->where('status', 'successful')->update(['status' => 'refunded']);
            }

            return $passBooking->fresh();
        });
    }

    private function deductDays(Pass $pass, int $days, int $passBookingId, string $reason): void
    {
        $newUsed = $pass->days_used + $days;
        if ($newUsed > $pass->total_days) {
            // Defense in depth — bookWithPass already checked remaining balance before this point.
            throw new PassException('Insufficient pass days remaining.');
        }
        $pass->update(['days_used' => $newUsed]);
        PassLedger::create([
            'pass_id' => $pass->id, 'event_type' => 'booking', 'change_days' => -$days,
            'balance_after' => $pass->total_days - $newUsed, 'pass_booking_id' => $passBookingId, 'reason' => $reason,
        ]);
    }

    public function adjustBalance(Pass $pass, int $deltaDays, string $reason, ?int $adminUserId): Pass
    {
        return DB::transaction(function () use ($pass, $deltaDays, $reason, $adminUserId) {
            $pass = Pass::lockForUpdate()->findOrFail($pass->id);
            $newUsed = max(0, $pass->days_used - $deltaDays);
            if ($pass->total_days - $newUsed < 0) {
                throw new PassException('Adjustment would make the balance negative.');
            }
            $pass->update(['days_used' => $newUsed]);
            PassLedger::create([
                'pass_id' => $pass->id, 'event_type' => 'adjustment', 'change_days' => $deltaDays,
                'balance_after' => $pass->total_days - $newUsed, 'reason' => $reason, 'created_by' => $adminUserId,
            ]);

            return $pass->fresh();
        });
    }
}
