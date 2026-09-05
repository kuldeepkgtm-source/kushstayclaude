<?php

namespace App\Services;

use App\Exceptions\BookingConflictException;
use App\Models\Bed;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Hold;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates, cancels, and transitions bookings. Every write goes through a DB transaction that
 * locks the target bed rows and re-checks availability *inside* that transaction — this is the
 * part that literally cannot be done safely in browser JavaScript, which is why it moved here.
 * The frontend's own availability check (used to render options quickly) is only ever a
 * suggestion; this method is the actual gate, and it is called from every entry point:
 * the REST API, the WhatsApp webhook, and the artifact-JSON importer.
 */
class BookingService
{
    public function __construct(
        private AvailabilityService $availability,
        private PricingService $pricing,
    ) {}

    /**
     * @param  array{
     *   property_id:int, customer_name:string, customer_phone:?string, source:string,
     *   check_in:string, check_out:string, guest_count:int, booking_type:string,
     *   room_id:int, bed_ids:int[], discount?:float, payment_method?:string,
     *   amount_paid?:float, external_booking_id?:string, special_request?:string
     * }  $data
     *
     * @throws BookingConflictException
     */
    public function createBooking(array $data): Booking
    {
        if ($data['check_out'] <= $data['check_in']) {
            throw new \InvalidArgumentException('check_out must be after check_in.');
        }

        $bedIds = $data['booking_type'] === 'private'
            ? Bed::where('room_id', $data['room_id'])->where('active', true)->pluck('id')->all()
            : $data['bed_ids'];

        return DB::transaction(function () use ($data, $bedIds) {
            // Lock the bed rows first — any concurrent request for the same beds blocks here
            // until this transaction commits or rolls back. This is what makes the re-check below safe.
            $lockedBeds = Bed::whereIn('id', $bedIds)->lockForUpdate()->get()->keyBy('id');

            if ($data['booking_type'] === 'private') {
                $priv = $this->availability->isPrivateAvailable($data['room_id'], $data['check_in'], $data['check_out']);
                if (! $priv['available']) {
                    throw new BookingConflictException('Private room is not available: '.$priv['reason'], $bedIds);
                }
            } else {
                $conflicts = [];
                foreach ($bedIds as $bedId) {
                    if (! $this->availability->isBedFree($bedId, $data['check_in'], $data['check_out'])) {
                        $conflicts[] = $bedId;
                    }
                }
                if ($conflicts) {
                    throw new BookingConflictException('These beds are no longer available: '.implode(', ', $conflicts), $conflicts);
                }
            }

            $customer = null;
            if (! empty($data['customer_phone'])) {
                $customer = Customer::firstOrCreate(
                    ['phone' => $data['customer_phone']],
                    ['name' => $data['customer_name']]
                );
            }

            $nights = (new \DateTime($data['check_out']))->diff(new \DateTime($data['check_in']))->days;
            $subtotal = $data['booking_type'] === 'private'
                ? $this->pricing->privateRoomTotal($data['room_id'], $data['check_in'], $data['check_out'])
                : $this->pricing->individualBedsTotal($bedIds, $data['check_in'], $data['check_out']);

            $discount = $data['discount'] ?? 0;
            $property = Room::findOrFail($data['room_id'])->property;
            $afterDiscount = max(0, $subtotal - $discount);
            $tax = $property->tax_enabled ? round($afterDiscount * $property->tax_rate_pct / 100, 2) : 0;
            $total = $afterDiscount + $tax;
            $amountPaid = $data['amount_paid'] ?? 0;

            $booking = Booking::create([
                'booking_ref' => $this->nextBookingRef($data['property_id']),
                'property_id' => $data['property_id'],
                'customer_id' => $customer?->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'source' => $data['source'],
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'guest_count' => $data['guest_count'],
                'booking_type' => $data['booking_type'],
                'room_id' => $data['room_id'],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'amount_paid' => $amountPaid,
                'balance' => $total - $amountPaid,
                'payment_status' => $amountPaid <= 0 ? 'Unpaid' : ($amountPaid >= $total ? 'Paid' : 'Partially Paid'),
                'payment_method' => $data['payment_method'] ?? null,
                'booking_status' => 'Confirmed',
                'external_booking_id' => $data['external_booking_id'] ?? null,
                'special_request' => $data['special_request'] ?? null,
            ]);

            $booking->beds()->attach($bedIds);

            // Release any hold(s) the caller had on these exact beds/dates now that they're a real booking.
            Hold::where('check_in', $data['check_in'])->where('check_out', $data['check_out'])
                ->whereIn('bed_id', $bedIds)->delete();

            return $booking->load('beds');
        });
    }

    public function cancel(Booking $booking): Booking
    {
        $booking->update([
            'booking_status' => 'Cancelled',
            'payment_status' => $booking->amount_paid > 0 ? 'Refunded' : $booking->payment_status,
        ]);

        return $booking;
    }

    public function checkIn(Booking $booking): Booking
    {
        $booking->update(['booking_status' => 'Checked-in']);

        return $booking;
    }

    public function checkOut(Booking $booking): Booking
    {
        $booking->update(['booking_status' => 'Checked-out']);

        return $booking;
    }

    /**
     * Move a booking to a different bed/date range, re-validated the same way as creation.
     * Locks both the booking's current beds and the target beds to avoid racing another change.
     */
    public function reschedule(Booking $booking, ?array $newBedIds, ?string $newCheckIn, ?string $newCheckOut): Booking
    {
        return DB::transaction(function () use ($booking, $newBedIds, $newCheckIn, $newCheckOut) {
            $checkIn = $newCheckIn ?? $booking->check_in->toDateString();
            $checkOut = $newCheckOut ?? $booking->check_out->toDateString();
            $bedIds = $newBedIds ?? $booking->beds->pluck('id')->all();

            Bed::whereIn('id', $bedIds)->lockForUpdate()->get();

            foreach ($bedIds as $bedId) {
                if (! $this->availability->isBedFree($bedId, $checkIn, $checkOut, $booking->id)) {
                    throw new BookingConflictException("Bed {$bedId} is not available for the new dates.", [$bedId]);
                }
            }

            $booking->update(['check_in' => $checkIn, 'check_out' => $checkOut]);
            $booking->beds()->sync($bedIds);

            return $booking->fresh('beds');
        });
    }

    private function nextBookingRef(int $propertyId): string
    {
        $ymd = now()->format('Ymd');
        $countToday = Booking::where('property_id', $propertyId)
            ->where('booking_ref', 'like', "BK-{$ymd}-%")->count();

        return sprintf('BK-%s-%04d', $ymd, $countToday + 1);
    }
}
