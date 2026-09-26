<?php

namespace Tests\Feature\Pass;

use App\Services\PassBookingService;

/**
 * The exact case from the spec: 05 Sep -> 07 Sep must consume exactly 2 pass days, never 3.
 * Also covers the "upgrade example" (Upper Non-AC pass, Lower AC used, 3 nights = ₹240) and the
 * cheaper-bed rule (no refund/no extra day for using a lower category).
 */
class PassNightsAccountingTest extends PassTestCase
{
    public function test_exact_two_night_stay_consumes_exactly_two_days_not_three(): void
    {
        $pass = $this->purchaseActivePass('NAC-Upper');
        $token = $this->accessTokenFor($pass);

        $response = $this->withToken($token, 'Bearer')->postJson('/api/pass/book', [
            'check_in' => '2026-09-05', 'check_out' => '2026-09-07', 'category' => 'NAC-Upper',
        ]);
        $response->assertStatus(201);

        $this->assertEquals(28, $pass->fresh()->remaining_days, 'a 05->07 Sep stay must consume exactly 2 days, not 3');
        $this->assertDatabaseHas('pass_ledger', ['pass_id' => $pass->id, 'event_type' => 'booking', 'day_change' => -2, 'balance_after' => 28]);
    }

    public function test_upgrade_fee_example_from_the_spec(): void
    {
        // Upper Non-AC pass, books Lower AC, 3 nights -> upgrade = 80 x 3 = 240 rupees (24000 paise)
        $pass = $this->purchaseActivePass('NAC-Upper');
        $token = $this->accessTokenFor($pass);

        $response = $this->withToken($token, 'Bearer')->postJson('/api/pass/book', [
            'check_in' => '2026-10-01', 'check_out' => '2026-10-04', 'category' => 'AC-Lower',
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('requires_payment', true);
        $response->assertJsonPath('upgrade_total_paise', 24000); // 8000 paise/night x 3

        // Not yet deducted — payment pending, per "do not deduct permanent pass days" until paid.
        $this->assertEquals(30, $pass->fresh()->remaining_days);

        $passBookingId = $response->json('pass_booking.id');
        $this->withToken($token, 'Bearer')->postJson("/api/pass/bookings/{$passBookingId}/confirm-upgrade-payment", ['method' => 'UPI', 'simulated_success' => true])
            ->assertStatus(200);

        $this->assertEquals(27, $pass->fresh()->remaining_days, '30 -> 27 after the 3-night upgraded stay is paid');
    }

    public function test_cheaper_bed_gives_no_refund_and_no_extra_day(): void
    {
        // Lower AC pass (most expensive base) using Upper Non-AC (cheapest) — 1 night, ₹0 upgrade, still -1 day.
        $pass = $this->purchaseActivePass('AC-Lower');
        $token = $this->accessTokenFor($pass);

        $response = $this->withToken($token, 'Bearer')->postJson('/api/pass/book', [
            'check_in' => '2026-11-01', 'check_out' => '2026-11-02', 'category' => 'NAC-Upper',
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('requires_payment', false);
        $response->assertJsonPath('upgrade_total_paise', 0);

        $this->assertEquals(29, $pass->fresh()->remaining_days, 'exactly 1 day consumed, no refund/credit for the cheaper bed');
    }

    public function test_booking_more_nights_than_remaining_balance_is_rejected(): void
    {
        $pass = $this->purchaseActivePass('NAC-Upper');
        $pass->update(['remaining_days' => 2]);
        $token = $this->accessTokenFor($pass);

        $this->withToken($token, 'Bearer')->postJson('/api/pass/book', [
            'check_in' => '2026-12-01', 'check_out' => '2026-12-04', 'category' => 'NAC-Upper', // 3 nights > 2 remaining
        ])->assertStatus(422)->assertJsonPath('error', 'insufficient_balance');

        $this->assertEquals(2, $pass->fresh()->remaining_days, 'a rejected booking must not touch the balance');
    }

    public function test_cancellation_restores_consumed_days(): void
    {
        $pass = $this->purchaseActivePass('NAC-Upper');
        $token = $this->accessTokenFor($pass);

        $book = $this->withToken($token, 'Bearer')->postJson('/api/pass/book', [
            'check_in' => '2027-01-01', 'check_out' => '2027-01-04', 'category' => 'NAC-Upper',
        ]);
        $this->assertEquals(27, $pass->fresh()->remaining_days);

        $passBookingId = $book->json('pass_booking.id');
        $this->withToken($token, 'Bearer')->postJson("/api/pass/bookings/{$passBookingId}/cancel")->assertStatus(200);

        $this->assertEquals(30, $pass->fresh()->remaining_days, 'cancellation must restore all 3 consumed days');
        $this->assertDatabaseHas('pass_ledger', ['pass_id' => $pass->id, 'event_type' => 'cancellation', 'day_change' => 3]);
    }
}
