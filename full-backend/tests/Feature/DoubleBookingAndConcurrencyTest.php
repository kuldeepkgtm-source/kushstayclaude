<?php

namespace Tests\Feature;

use App\Exceptions\BookingConflictException;
use App\Services\BookingService;
use Illuminate\Support\Facades\DB;

/** Tests 5 (double booking) and 6 (concurrent attempts). */
class DoubleBookingAndConcurrencyTest extends TestCase
{
    public function test_second_booking_of_same_bed_and_dates_is_rejected(): void
    {
        $bookings = app(BookingService::class);
        $bed = $this->bed('NAC-U1');
        $payload = [
            'property_id' => $this->property->id, 'customer_name' => 'Guest A', 'customer_phone' => '9876500010',
            'source' => 'Manual/Admin', 'check_in' => '2026-11-01', 'check_out' => '2026-11-03',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->nacRoom->id, 'bed_ids' => [$bed->id],
        ];

        $bookings->createBooking($payload);

        $this->expectException(BookingConflictException::class);
        $bookings->createBooking(array_merge($payload, ['customer_name' => 'Guest B', 'customer_phone' => '9876500011']));
    }

    /**
     * Simulates two "simultaneous" requests for the last free bed by running both inside the test
     * process back-to-back against the same transactional method — the second must fail because
     * the first's transaction already committed the booking (in real concurrent HTTP requests, the
     * SELECT ... FOR UPDATE row lock in BookingService serializes them the same way).
     */
    public function test_concurrent_attempts_on_the_last_bed_only_one_wins(): void
    {
        $bookings = app(BookingService::class);
        $bed = $this->bed('AC-L4');
        $payload = [
            'property_id' => $this->property->id, 'source' => 'Manual/Admin',
            'check_in' => '2026-11-10', 'check_out' => '2026-11-11', 'guest_count' => 1,
            'booking_type' => 'individual', 'room_id' => $this->acRoom->id, 'bed_ids' => [$bed->id],
        ];

        $results = [];
        foreach (['Guest X' => '9876500020', 'Guest Y' => '9876500021'] as $name => $phone) {
            try {
                $bookings->createBooking($payload + ['customer_name' => $name, 'customer_phone' => $phone]);
                $results[] = 'ok';
            } catch (BookingConflictException) {
                $results[] = 'conflict';
            }
        }

        $this->assertEquals(['ok', 'conflict'], $results);
        $this->assertEquals(1, DB::table('booking_beds')->where('bed_id', $bed->id)->count());
    }
}
