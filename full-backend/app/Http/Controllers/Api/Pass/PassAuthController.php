<?php

namespace App\Http\Controllers\Api\Pass;

use App\Http\Controllers\Controller;
use App\Models\Pass;
use App\Models\PassAccessToken;
use App\Services\PassOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Lets an existing pass-holder regain "My Pass" access on a new device via email OTP. */
class PassAuthController extends Controller
{
    public function __construct(private PassOtpService $otp) {}

    // POST /api/pass/login — exchanges a verified OTP session for a pass access token
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'otp_session_token' => 'required|string']);

        try {
            $this->otp->consumeSession($data['otp_session_token'], $data['email'], 'pass_login');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $pass = Pass::whereHas('customer', fn ($q) => $q->where('email', $data['email']))
            ->whereIn('status', ['active', 'expired', 'suspended'])->latest()->first();

        if (! $pass) {
            return response()->json(['message' => 'No pass found for this email.'], 404);
        }

        $token = PassAccessToken::create(['pass_id' => $pass->id, 'token' => Str::random(48), 'expires_at' => now()->addDays(30)]);

        return response()->json(['access_token' => $token->token, 'pass_ref' => $pass->pass_ref]);
    }
}
