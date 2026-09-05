<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BookingService;
use Laravel\Sanctum\Sanctum;

/** Test 10: payment balance = total - amount_paid, kept correct as payments accrue. */
class PaymentBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // POST /api/payments records an admin-confirmed payment (e.g. cash at property, or an
        // admin reconciling a gateway payout) — the same trust boundary as the prototype's
        // Payments-screen "Mark paid" button. A real gateway (UPI/card) posts to its own signed
        // webhook route instead of this one; that integration is intentionally not built yet
        // (see the gap analysis). Sanctum::actingAs simulates the logged-in admin for this test.
        Sanctum::actingAs(User::first());
    }

    public function test_balance_updates_as_payments_are_recorded(): void
    {
        $bookings = app(BookingService::class);
        $bed = $this->bed('AC-U1');

        $booking = $bookings->createBooking([
            'property_id' => $this->property->id, 'customer_name' => 'Payer', 'customer_phone' => '9876500040',
            'source' => 'Direct', 'check_in' => '2026-09-25', 'check_out' => '2026-09-27',
            'guest_count' => 1, 'booking_type' => 'individual', 'room_id' => $this->acRoom->id, 'bed_ids' => [$bed->id],
        ]);

        $this->assertEquals($booking->total, $booking->balance);
        $this->assertEquals('Unpaid', $booking->payment_status);

        $half = round($booking->total / 2, 2);

        $response = $this->postJson('/api/payments', [
            'booking_id' => $booking->id, 'method' => 'UPI', 'amount' => $half, 'status' => 'success',
        ]);
        $response->assertStatus(201);

        $booking->refresh();
        $this->assertEquals($half, $booking->amount_paid);
        $this->assertEquals($booking->total - $half, $booking->balance);
        $this->assertEquals('Partially Paid', $booking->payment_status);

        $this->postJson('/api/payments', ['booking_id' => $booking->id, 'method' => 'UPI', 'amount' => $half, 'status' => 'success']);
        $booking->refresh();
        $this->assertEquals(0, (float) $booking->balance);
        $this->assertEquals('Paid', $booking->payment_status);
    }
}
