<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** Test 14: admin-only endpoints reject unauthenticated requests and accept authenticated ones. */
class ApiAuthorizationTest extends TestCase
{
    public function test_admin_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->getJson('/api/bookings')->assertStatus(401);
        $this->getJson('/api/customers')->assertStatus(401);
        $this->postJson('/api/ical/import', [])->assertStatus(401);
    }

    public function test_admin_endpoint_accepts_authenticated_requests(): void
    {
        Sanctum::actingAs(User::first());
        $this->getJson('/api/bookings')->assertStatus(200);
    }

    public function test_public_endpoints_do_not_require_authentication(): void
    {
        $this->getJson('/api/rooms')->assertStatus(200);
        $this->getJson('/api/beds')->assertStatus(200);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->postJson('/api/login', ['email' => 'admin@kushstay.example', 'password' => 'wrong-password'])
            ->assertStatus(422);
    }
}
