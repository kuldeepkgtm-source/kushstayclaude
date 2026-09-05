<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the prototype's hardcoded admin/admin123 check. Token-based (Sanctum) so the existing
 * React frontend can keep working as an SPA calling a JSON API — no server-rendered login page.
 */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        $key = 'login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in a minute.']);
        }

        if (! Auth::attempt($data)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }
        RateLimiter::clear($key);

        /** @var User $user */
        $user = Auth::user();
        if (! $user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'This account is disabled.']);
        }

        $token = $user->createToken('kush-stay-admin', ['*'], now()->addDay())->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user->only('id', 'name', 'email'), 'role' => $user->role?->name]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'logged_out']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('role'));
    }
}
