<?php

namespace Tests\Feature\Pass;

/** IDOR protection: a pass access token must only ever unlock its own pass, never another's. */
class PassAuthorizationTest extends PassTestCase
{
    public function test_customer_cannot_view_another_customers_pass(): void
    {
        $passA = $this->purchaseActivePass('NAC-Upper', 'a@example.com', '9800000030');
        $passB = $this->purchaseActivePass('AC-Lower', 'b@example.com', '9800000031');
        $tokenA = $this->accessTokenFor($passA);

        $me = $this->withToken($tokenA)->getJson('/api/pass/me');
        $me->assertStatus(200)->assertJsonPath('id', $passA->id);
        $this->assertNotEquals($passB->id, $me->json('id'));
    }

    public function test_customer_cannot_cancel_another_customers_booking(): void
    {
        $passA = $this->purchaseActivePass('NAC-Upper', 'c@example.com', '9800000032');
        $passB = $this->purchaseActivePass('NAC-Upper', 'd@example.com', '9800000033');
        $tokenA = $this->accessTokenFor($passA);
        $tokenB = $this->accessTokenFor($passB);

        $booking = $this->withToken($tokenA)->postJson('/api/pass/book', [
            'check_in' => '2027-02-01', 'check_out' => '2027-02-02', 'category' => 'NAC-Upper',
        ]);
        $passBookingId = $booking->json('pass_booking.id');

        $this->withToken($tokenB)->postJson("/api/pass/bookings/{$passBookingId}/cancel")->assertStatus(403);
    }

    public function test_missing_or_invalid_token_is_rejected(): void
    {
        $this->getJson('/api/pass/me')->assertStatus(401);
        $this->withToken('not-a-real-token')->getJson('/api/pass/me')->assertStatus(401);
    }

    public function test_admin_pass_routes_require_admin_auth(): void
    {
        $this->getJson('/api/admin/passes')->assertStatus(401);
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::first());
        $this->getJson('/api/admin/passes')->assertStatus(200);
    }
}
