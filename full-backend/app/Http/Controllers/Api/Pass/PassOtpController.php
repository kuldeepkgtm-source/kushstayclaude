<?php

namespace App\Http\Controllers\Api\Pass;

use App\Http\Controllers\Controller;
use App\Services\PassOtpService;
use Illuminate\Http\Request;

class PassOtpController extends Controller
{
    public function __construct(private PassOtpService $otp) {}

    // POST /api/pass/otp/request
    public function request(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'purpose' => 'nullable|string']);
        try {
            $result = $this->otp->requestOtp($data['email'], $data['purpose'] ?? 'pass_purchase');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        // code_for_dev_only is only ever populated in local/testing environments — never in production.
        return response()->json(['message' => 'Verification code sent.', 'expires_in_seconds' => $result['expires_in_seconds'], 'dev_code' => $result['code_for_dev_only']]);
    }

    // POST /api/pass/otp/verify
    public function verify(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'code' => 'required|digits:6', 'purpose' => 'nullable|string']);
        try {
            $sessionToken = $this->otp->verify($data['email'], $data['code'], $data['purpose'] ?? 'pass_purchase');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['session_token' => $sessionToken]);
    }
}
