<?php

namespace Tests\Feature;

use App\Services\AvailabilityService;
use App\Services\BookingService;

/** Tests 7 (cancellation) and 8 (rebooking after cancellation). */
class CancellationTest extends TestCase
{
    public function test_cancelling_a_booking_releases_the_bed_for_rebooking(): void
    {
        $bookings = app(BookingService::class);
        $availability = app(AvailabilityService::class);
        $bed = $this->bed('NAC-L3');

        $booking = $bookings->createBooking([
            'property_id' => $this->property->id, 'customer_name' => 'Cancel Me', 'customer_phone' => '9876500030',
            'source' => 'Manual/Admin', 'check_in' => '2026-12-01', 'check_out' => '2026-12-03',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->nacRoom->id, 'bed_ids' => [$bed->id],
        ]);

        $this->assertFalse($availability->isBedFree($bed->id, '2026-12-01', '2026-12-03'));

        $bookings->cancel($booking);
        $this->assertEquals('Cancelled', $booking->fresh()->booking_status);
        $this->assertTrue($availability->isBedFree($bed->id, '2026-12-01', '2026-12-03'), 'bed must be rebookable after cancellation');

        // And it really can be rebooked, not just theoretically "free":
        $rebooked = $bookings->createBooking([
            'property_id' => $this->property->id, 'customer_name' => 'New Guest', 'customer_phone' => '9876500031',
            'source' => 'Manual/Admin', 'check_in' => '2026-12-01', 'check_out' => '2026-12-03',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->nacRoom->id, 'bed_ids' => [$bed->id],
        ]);
        $this->assertNotNull($rebooked->id);
    }
}
