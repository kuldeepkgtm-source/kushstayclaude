<?php

namespace App\Services;

use App\Exceptions\PassSoldOutException;
use App\Models\Customer;
use App\Models\Pass;
use App\Models\PassPayment;
use App\Models\PassProduct;
use App\Models\PassSetting;
use Illuminate\Support\Facades\DB;

/**
 * Owns the 150-pass Grand Opening cap and the pending->paid->active lifecycle.
 *
 * Race-condition design: pass_settings has exactly one row per property, and every reservation
 * locks that single row with SELECT ... FOR UPDATE before checking-and-incrementing the sold
 * counter, all inside one transaction. This is the standard, reliable pattern for a hard cap
 * under concurrency in MySQL/InnoDB — two simultaneous requests for the last slot serialize on
 * that row lock, and the second one sees the incremented count and is rejected. See
 * tests/Feature/PassGrandOpeningLimitTest.php for a concurrency test of exactly this.
 */
class PassPurchaseService
{
    public function __construct(
        private PassOtpService $otp,
        private PassLedgerService $ledger,
    ) {}

    /**
     * Reserves a slot (counts against the 150 cap immediately) and creates a payment_pending
     * pass. Does NOT grant any days yet — see confirmPayment(). Requires a verified, unconsumed
     * OTP session for the given email (proves the purchaser controls that email).
     */
    public function reserve(int $propertyId, int $passProductId, string $email, array $customerData, string $otpSessionToken, int $reservationMinutes = 15): Pass
    {
        return DB::transaction(function () use ($propertyId, $passProductId, $email, $customerData, $otpSessionToken, $reservationMinutes) {
            $settings = PassSetting::where('property_id', $propertyId)->lockForUpdate()->firstOrFail();
            $product = PassProduct::where('property_id', $propertyId)->where('id', $passProductId)->where('active', true)->firstOrFail();

            $property = \App\Models\Property::findOrFail($propertyId);
            if (! $property->isLive()) {
                throw new \RuntimeException("Property '{$property->name}' is not currently selling passes (status: {$property->status}).");
            }

            $grandOpening = $settings->grand_opening_active;
            if ($grandOpening && $settings->grand_opening_sold >= $settings->grand_opening_limit) {
                throw new PassSoldOutException('Grand Opening passes sold out.');
            }

            // Proves the customer controls $email; throws if missing/expired/already used.
            $this->otp->consumeSession($otpSessionToken, $email, 'pass_purchase');

            $customer = Customer::firstOrCreate(
                ['phone' => $customerData['phone']],
                ['name' => $customerData['name'], 'email' => $email]
            );
            if (! $customer->email) {
                $customer->update(['email' => $email]);
            }

            $pricePaise = $product->currentPricePaise($settings);

            $pass = Pass::create([
                'pass_ref' => $this->nextPassRef($propertyId),
                'property_id' => $propertyId,
                'customer_id' => $customer->id,
                'pass_product_id' => $product->id,
                'grand_opening' => $grandOpening,
                'price_paid_paise' => $pricePaise,
                'total_days' => $product->total_days,
                'used_days' => 0,
                'remaining_days' => 0, // granted only once payment is confirmed — see confirmPayment()
                'status' => 'payment_pending',
                'reserved_until' => now()->addMinutes($reservationMinutes),
            ]);

            if ($grandOpening) {
                $settings->increment('grand_opening_sold');
            }

            return $pass;
        });
    }

    /**
     * Server-side payment confirmation is authoritative — never called just because the
     * frontend claims success. Activates the pass and grants its 30 days via the ledger only now.
     */
    public function confirmPayment(int $passId, array $paymentPayload): Pass
    {
        return DB::transaction(function () use ($passId, $paymentPayload) {
            $pass = Pass::lockForUpdate()->findOrFail($passId);

            if ($pass->status !== 'payment_pending') {
                throw new \RuntimeException("Pass {$pass->pass_ref} is not awaiting payment (status: {$pass->status}).");
            }
            if ($pass->reserved_until && $pass->reserved_until->isPast()) {
                throw new \RuntimeException('This reservation has expired — please start the purchase again.');
            }

            PassPayment::create([
                'pass_id' => $pass->id,
                'purpose' => 'pass_purchase',
                'amount_paise' => $pass->price_paid_paise,
                'method' => $paymentPayload['method'] ?? null,
                'status' => 'successful',
                'gateway_reference' => $paymentPayload['gateway_reference'] ?? null,
                'confirmed_at' => now(),
            ]);

            $activatedAt = now()->toDateString();
            $expiresAt = now()->addYear()->subDay()->toDateString();

            $pass->update(['status' => 'active', 'activated_at' => $activatedAt, 'expires_at' => $expiresAt]);

            $this->ledger->record($pass->fresh(), 'grant', $pass->total_days, null, 'Pass activated');

            return $pass->fresh();
        });
    }

    /** Failed/abandoned payment: release the reserved slot back to the pool. */
    public function failPayment(int $passId, string $reason = 'Payment failed'): Pass
    {
        return DB::transaction(function () use ($passId, $reason) {
            $pass = Pass::lockForUpdate()->findOrFail($passId);
            if (! in_array($pass->status, ['payment_pending', 'pending'])) {
                return $pass;
            }

            if ($pass->grand_opening) {
                PassSetting::where('property_id', $pass->property_id)->lockForUpdate()->decrement('grand_opening_sold');
            }
            $pass->update(['status' => 'cancelled']);

            return $pass;
        });
    }

    /** Scheduled sweep (pass:release-expired-reservations) — abandoned checkouts free their slot. */
    public function releaseExpiredReservations(): int
    {
        $expired = Pass::where('status', 'payment_pending')->where('reserved_until', '<', now())->get();
        foreach ($expired as $pass) {
            $this->failPayment($pass->id, 'Reservation expired');
        }

        return $expired->count();
    }

    private function nextPassRef(int $propertyId): string
    {
        $n = Pass::where('property_id', $propertyId)->count() + 1;

        return sprintf('KS-PASS-%03d', $n);
    }
}
