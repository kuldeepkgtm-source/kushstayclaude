<?php

namespace App\Http\Controllers\Api\Pass;

use App\Exceptions\PassSoldOutException;
use App\Http\Controllers\Controller;
use App\Models\Pass;
use App\Models\PassAccessToken;
use App\Services\PassPurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PassPurchaseController extends Controller
{
    public function __construct(private PassPurchaseService $purchases) {}

    // POST /api/pass/purchase/reserve — call after OTP verify, before showing the payment step.
    public function reserve(Request $request)
    {
        $data = $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'pass_product_id' => 'required|integer|exists:pass_products,id',
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'phone' => 'required|digits:10',
            'otp_session_token' => 'required|string',
        ]);

        try {
            $pass = $this->purchases->reserve(
                $data['property_id'], $data['pass_product_id'], $data['email'],
                ['name' => $data['name'], 'phone' => $data['phone']], $data['otp_session_token']
            );
        } catch (PassSoldOutException $e) {
            return response()->json(['error' => 'sold_out', 'message' => 'Grand Opening passes sold out.'], 409);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['pass_id' => $pass->id, 'pass_ref' => $pass->pass_ref, 'price_paid_paise' => $pass->price_paid_paise, 'reserved_until' => $pass->reserved_until], 201);
    }

    // POST /api/pass/purchase/{pass}/confirm-payment — server-side confirmation is authoritative
    public function confirmPayment(Request $request, Pass $pass)
    {
        $data = $request->validate(['method' => 'required|string', 'gateway_reference' => 'nullable|string', 'simulated_success' => 'required|boolean']);

        if (! $data['simulated_success']) {
            $this->purchases->failPayment($pass->id, 'Payment failed');

            return response()->json(['status' => 'failed'], 402);
        }

        try {
            $activated = $this->purchases->confirmPayment($pass->id, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $token = PassAccessToken::create(['pass_id' => $activated->id, 'token' => Str::random(48), 'expires_at' => now()->addDays(30)]);

        return response()->json(['pass' => $activated, 'access_token' => $token->token]);
    }
}
