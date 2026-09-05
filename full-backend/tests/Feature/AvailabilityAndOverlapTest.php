<?php

namespace Tests\Feature;

use App\Services\AvailabilityService;
use App\Services\BookingService;

/** Tests 1, 2, 3, 4, 9: bed availability, date overlap, the exact checkout-date case, private-room rule. */
class AvailabilityAndOverlapTest extends TestCase
{
    public function test_bed_is_available_with_no_bookings(): void
    {
        $availability = app(AvailabilityService::class);
        $this->assertTrue($availability->isBedFree($this->bed('AC-U1')->id, '2026-09-05', '2026-09-07'));
    }

    public function test_date_overlap_is_detected(): void
    {
        $bookings = app(BookingService::class);
        $bed = $this->bed('AC-U1');

        $bookings->createBooking([
            'property_id' => $this->property->id, 'customer_name' => 'Test Guest', 'customer_phone' => '9876500001',
            'source' => 'Manual/Admin', 'check_in' => '2026-09-05', 'check_out' => '2026-09-07',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->acRoom->id, 'bed_ids' => [$bed->id],
        ]);

        $availability = app(AvailabilityService::class);
        // Overlapping attempt (06 -> 08) must show the bed as unavailable
        $this->assertFalse($availability->isBedFree($bed->id, '2026-09-06', '2026-09-08'));
    }

    /**
     * The exact critical case: AC-U1 booked 05 Sep 2026 -> 07 Sep 2026.
     * 05 and 06 unavailable, 07 available (checkout night is never occupied).
     * 06->08 must fail (overlaps); 07->09 must succeed (starts exactly on the checkout night).
     */
    public function test_exact_checkout_date_case(): void
    {
        $bookings = app(BookingService::class);
        $availability = app(AvailabilityService::class);
        $bed = $this->bed('AC-U1');

        $bookings->createBooking([
            'property_id' => $this->property->id, 'customer_name' => 'Critical Case Guest', 'customer_phone' => '9876500002',
            'source' => 'Manual/Admin', 'check_in' => '2026-09-05', 'check_out' => '2026-09-07',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->acRoom->id, 'bed_ids' => [$bed->id],
        ]);

        $this->assertFalse($availability->isBedFree($bed->id, '2026-09-05', '2026-09-06'), '05 Sep must be unavailable');
        $this->assertFalse($availability->isBedFree($bed->id, '2026-09-06', '2026-09-07'), '06 Sep must be unavailable');
        $this->assertTrue($availability->isBedFree($bed->id, '2026-09-07', '2026-09-08'), '07 Sep must be available');

        $this->assertFalse($availability->isBedFree($bed->id, '2026-09-06', '2026-09-08'), '06->08 MUST be rejected (overlaps)');
        $this->assertTrue($availability->isBedFree($bed->id, '2026-09-07', '2026-09-09'), '07->09 MUST be allowed');

        // And the same case through the real HTTP API, end to end:
        $response = $this->postJson('/api/bookings', [
            'property_id' => $this->property->id, 'customer_name' => 'API Guest', 'customer_phone' => '9876500003',
            'source' => 'Direct', 'check_in' => '2026-09-06', 'check_out' => '2026-09-08',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->acRoom->id, 'bed_ids' => [$bed->id],
        ]);
        $response->assertStatus(409); // conflict — bed already booked for an overlapping range

        $response2 = $this->postJson('/api/bookings', [
            'property_id' => $this->property->id, 'customer_name' => 'API Guest 2', 'customer_phone' => '9876500004',
            'source' => 'Direct', 'check_in' => '2026-09-07', 'check_out' => '2026-09-09',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->acRoom->id, 'bed_ids' => [$bed->id],
        ]);
        $response2->assertStatus(201);
    }

    public function test_private_room_requires_all_eight_beds_free(): void
    {
        $bookings = app(BookingService::class);
        $availability = app(AvailabilityService::class);

        $priv = $availability->isPrivateAvailable($this->acRoom->id, '2026-10-01', '2026-10-03');
        $this->assertTrue($priv['available']);

        $bookings->createBooking([
            'property_id' => $this->property->id, 'customer_name' => 'One Bed Guest', 'customer_phone' => '9876500005',
            'source' => 'Manual/Admin', 'check_in' => '2026-10-01', 'check_out' => '2026-10-03',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->acRoom->id, 'bed_ids' => [$this->bed('AC-L2')->id],
        ]);

        $priv2 = $availability->isPrivateAvailable($this->acRoom->id, '2026-10-01', '2026-10-03');
        $this->assertFalse($priv2['available']);
        $this->assertStringContainsString('1 of 8', $priv2['reason']);
    }
}
