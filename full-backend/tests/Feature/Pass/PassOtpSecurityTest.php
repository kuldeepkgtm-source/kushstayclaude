<?php

namespace Tests\Feature\Pass;

use App\Models\PassOtpVerification;

class PassOtpSecurityTest extends PassTestCase
{
    public function test_otp_is_never_stored_in_plaintext(): void
    {
        $this->postJson('/api/pass/otp/request', ['email' => 'plain@example.com'])->assertStatus(200);
        $record = PassOtpVerification::where('email', 'plain@example.com')->first();

        $this->assertNotNull($record->otp_hash);
        $this->assertEquals(64, strlen($record->otp_hash)); // sha256 hex digest length — a hash, not a 6-digit code
    }

    public function test_wrong_code_is_rejected_and_counted_as_an_attempt(): void
    {
        $this->postJson('/api/pass/otp/request', ['email' => 'wrong@example.com'])->assertStatus(200);
        $this->postJson('/api/pass/otp/verify', ['email' => 'wrong@example.com', 'code' => '000000'])->assertStatus(422);

        $this->assertEquals(1, PassOtpVerification::where('email', 'wrong@example.com')->first()->attempts);
    }

    public function test_exceeding_max_attempts_locks_out_further_tries(): void
    {
        $this->postJson('/api/pass/otp/request', ['email' => 'bruteforce@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/pass/otp/verify', ['email' => 'bruteforce@example.com', 'code' => '111111']);
        }
        $response = $this->postJson('/api/pass/otp/verify', ['email' => 'bruteforce@example.com', 'code' => '111111']);
        $response->assertStatus(422);
        $this->assertStringContainsString('Too many', $response->json('message'));
    }

    public function test_resend_cooldown_is_enforced(): void
    {
        $this->postJson('/api/pass/otp/request', ['email' => 'cooldown@example.com'])->assertStatus(200);
        $this->postJson('/api/pass/otp/request', ['email' => 'cooldown@example.com'])->assertStatus(429);
    }

    public function test_verified_session_cannot_be_replayed(): void
    {
        $token = $this->verifiedOtpSession('replay@example.com');

        $product = \App\Models\PassProduct::where('category', 'NAC-Upper')->first();
        $this->postJson('/api/pass/purchase/reserve', [
            'property_id' => $this->property->id, 'pass_product_id' => $product->id,
            'email' => 'replay@example.com', 'name' => 'Replay', 'phone' => '9800000020', 'otp_session_token' => $token,
        ])->assertStatus(201);

        // Same token, second purchase attempt — must fail, the session was single-use.
        $this->postJson('/api/pass/purchase/reserve', [
            'property_id' => $this->property->id, 'pass_product_id' => $product->id,
            'email' => 'replay@example.com', 'name' => 'Replay', 'phone' => '9800000020', 'otp_session_token' => $token,
        ])->assertStatus(422);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $this->postJson('/api/pass/otp/request', ['email' => 'expired@example.com']);
        PassOtpVerification::where('email', 'expired@example.com')->first()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/pass/otp/verify', ['email' => 'expired@example.com', 'code' => '123456'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'This code has expired. Request a new one.']);
    }
}
