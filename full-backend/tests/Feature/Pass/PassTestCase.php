<?php

namespace Tests\Feature\Pass;

use App\Models\PassAccessToken;
use App\Models\PassOtpVerification;
use App\Models\PassProduct;
use App\Models\PassSetting;
use App\Services\PassOtpService;
use Illuminate\Support\Str;
use Tests\Feature\TestCase;

abstract class PassTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PassSeeder::class);
    }

    protected function verifiedOtpSession(string $email, string $purpose = 'pass_purchase'): string
    {
        $otp = app(PassOtpService::class);
        $result = $otp->requestOtp($email, $purpose);
        // requestOtp() only returns the plaintext code because APP_ENV=testing under phpunit —
        // see PassOtpService::requestOtp(). Everything after this line is the real verify() path.
        return $otp->verify($email, $result['code_for_dev_only'], $purpose);
    }

    protected function purchaseActivePass(string $category = 'NAC-Upper', string $email = 'buyer@example.com', string $phone = '9800000001'): \App\Models\Pass
    {
        $product = PassProduct::where('category', $category)->firstOrFail();
        $otpToken = $this->verifiedOtpSession($email);

        $reserveResponse = $this->postJson('/api/pass/purchase/reserve', [
            'property_id' => $this->property->id, 'pass_product_id' => $product->id,
            'email' => $email, 'name' => 'Test Buyer', 'phone' => $phone, 'otp_session_token' => $otpToken,
        ]);
        $reserveResponse->assertStatus(201);
        $passId = $reserveResponse->json('pass_id');

        $this->postJson("/api/pass/purchase/{$passId}/confirm-payment", ['method' => 'UPI', 'simulated_success' => true])
            ->assertStatus(200);

        return \App\Models\Pass::findOrFail($passId);
    }

    protected function accessTokenFor(\App\Models\Pass $pass): string
    {
        $token = PassAccessToken::create(['pass_id' => $pass->id, 'token' => Str::random(48), 'expires_at' => now()->addDay()]);

        return $token->token;
    }
}
