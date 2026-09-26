<?php

namespace App\Http\Middleware;

use App\Models\PassAccessToken;
use Closure;
use Illuminate\Http\Request;

/**
 * Customer-facing "My Pass" auth — a bearer token scoped to exactly one pass, issued after
 * email-OTP verification (see PassAuthController::exchangeOtpForToken). Deliberately not
 * Sanctum: pass-holders are Customers, not Users, and must never be able to reach admin routes.
 * Resolving the token attaches the owning Pass to the request so controllers can enforce
 * "Customer A can never see Customer B's pass" just by scoping every query to it.
 */
class EnsurePassToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $record = $token ? PassAccessToken::valid()->where('token', $token)->first() : null;

        if (! $record) {
            return response()->json(['message' => 'Invalid or expired pass access token.'], 401);
        }

        $request->attributes->set('pass', $record->pass);

        return $next($request);
    }
}
