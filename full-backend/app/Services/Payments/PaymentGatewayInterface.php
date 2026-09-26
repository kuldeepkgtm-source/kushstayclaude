<?php

namespace App\Services\Payments;

/**
 * Swap the bound implementation (see AppServiceProvider) to add Razorpay/Stripe/etc. later
 * without touching PassService or any controller — that's the whole point of this interface.
 */
interface PaymentGatewayInterface
{
    /** Kick off a charge; returns a gateway reference the frontend can act on (e.g. an order id). */
    public function charge(int $amountPaise, string $currency, array $meta): array;

    /** Verify an inbound webhook/callback payload really came from the gateway and says success. */
    public function verifyWebhookSignature(array $payload, string $signature): bool;
}
