<?php

namespace App\Services\Payments;

use Illuminate\Support\Str;

/**
 * Stand-in until a real gateway is connected. It does NOT claim success — it returns a
 * "pending" reference and requires an explicit confirm step (see PassPaymentController),
 * mirroring how a real gateway's redirect-then-webhook flow works. No amount, status, or
 * ownership fact here is ever taken from the frontend as authoritative.
 */
class DummyPaymentGateway implements PaymentGatewayInterface
{
    public function charge(int $amountPaise, string $currency, array $meta): array
    {
        return ['reference' => 'DUMMY-'.Str::upper(Str::random(10)), 'status' => 'pending', 'amount_paise' => $amountPaise, 'currency' => $currency];
    }

    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        // A real gateway integration checks an HMAC here against a shared secret. The dummy
        // gateway has no external caller, so this always fails closed unless explicitly testing.
        return $signature === 'dummy-test-signature';
    }
}
