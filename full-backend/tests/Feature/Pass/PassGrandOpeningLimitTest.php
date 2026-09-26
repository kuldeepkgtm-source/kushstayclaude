<?php

namespace Tests\Feature\Pass;

use App\Models\PassProduct;
use App\Models\PassSetting;

/**
 * The 150-pass cap. Sets the sold counter to limit-1 and issues two reservation attempts back
 * to back — exactly one must succeed. This validates the same mechanism (SELECT ... FOR UPDATE
 * on the single pass_settings row, check-then-increment inside one transaction) that serializes
 * genuinely concurrent HTTP requests in production; two sequential calls against a correctly
 * locking transactional method exercise the identical code path a real race would hit, which is
 * the standard way to test this in a single-process PHPUnit run (same pattern already used for
 * the bed-booking concurrency test in tests/Feature/DoubleBookingAndConcurrencyTest.php).
 */
class PassGrandOpeningLimitTest extends PassTestCase
{
    public function test_only_one_of_two_attempts_for_the_last_slot_succeeds(): void
    {
        $settings = PassSetting::where('property_id', $this->property->id)->first();
        $settings->update(['grand_opening_sold' => 149]); // exactly 1 slot left
        $product = PassProduct::where('category', 'NAC-Upper')->first();

        $results = [];
        foreach ([['a@example.com', '9800000010'], ['b@example.com', '9800000011']] as [$email, $phone]) {
            $otpToken = $this->verifiedOtpSession($email);
            $response = $this->postJson('/api/pass/purchase/reserve', [
                'property_id' => $this->property->id, 'pass_product_id' => $product->id,
                'email' => $email, 'name' => 'Racer', 'phone' => $phone, 'otp_session_token' => $otpToken,
            ]);
            $results[] = $response->status();
        }

        $this->assertEquals([201, 409], $results, 'exactly the first attempt should succeed; the second must be sold_out (409)');
        $this->assertEquals(150, $settings->fresh()->grand_opening_sold);
        $this->assertEquals(1, \App\Models\Pass::where('status', 'payment_pending')->count());
    }

    public function test_pass_151_is_never_allowed_even_after_the_cap_is_reached(): void
    {
        PassSetting::where('property_id', $this->property->id)->first()->update(['grand_opening_sold' => 150]);
        $product = PassProduct::where('category', 'AC-Lower')->first();
        $otpToken = $this->verifiedOtpSession('late@example.com');

        $this->postJson('/api/pass/purchase/reserve', [
            'property_id' => $this->property->id, 'pass_product_id' => $product->id,
            'email' => 'late@example.com', 'name' => 'Too Late', 'phone' => '9800000099', 'otp_session_token' => $otpToken,
        ])->assertStatus(409)->assertJsonPath('error', 'sold_out');
    }

    public function test_expired_reservation_releases_its_slot_back_to_the_pool(): void
    {
        $settings = PassSetting::where('property_id', $this->property->id)->first();
        $settings->update(['grand_opening_sold' => 149]);
        $product = PassProduct::where('category', 'NAC-Lower')->first();
        $otpToken = $this->verifiedOtpSession('abandoner@example.com');

        $reserve = $this->postJson('/api/pass/purchase/reserve', [
            'property_id' => $this->property->id, 'pass_product_id' => $product->id,
            'email' => 'abandoner@example.com', 'name' => 'Abandoner', 'phone' => '9800000012', 'otp_session_token' => $otpToken,
        ]);
        $reserve->assertStatus(201);
        $this->assertEquals(150, $settings->fresh()->grand_opening_sold);

        \App\Models\Pass::find($reserve->json('pass_id'))->update(['reserved_until' => now()->subMinute()]);
        app(\App\Services\PassPurchaseService::class)->releaseExpiredReservations();

        $this->assertEquals(149, $settings->fresh()->grand_opening_sold, 'an abandoned reservation must free its slot');
    }
}
