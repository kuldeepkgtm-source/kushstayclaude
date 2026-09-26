<?php

namespace App\Services;

use App\Models\PassOtpVerification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Email OTP for pass purchase / "My Pass" access. The 6-digit code itself is never stored —
 * only an HMAC of it, keyed with the app's own secret (APP_KEY) as a pepper — so reading the
 * database alone is not enough to recover a valid code. All limits below are enforced here,
 * server-side, in addition to the RateLimiter calls the controller makes per-IP.
 */
class PassOtpService
{
    private const EXPIRY_MINUTES = 10;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    public function requestOtp(string $email, string $purpose = 'pass_purchase'): array
    {
        $key = "otp-request:{$email}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new \RuntimeException('Too many OTP requests. Please wait a while and try again.');
        }

        $existing = PassOtpVerification::where('email', $email)->where('purpose', $purpose)
            ->whereNull('verified_at')->latest()->first();

        if ($existing && $existing->last_sent_at && $existing->last_sent_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            $wait = self::RESEND_COOLDOWN_SECONDS - $existing->last_sent_at->diffInSeconds(now());

            throw new \RuntimeException("Please wait {$wait}s before requesting another code.");
        }

        RateLimiter::hit($key, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); // cryptographically secure (random_int)
        $record = PassOtpVerification::create([
            'email' => $email,
            'purpose' => $purpose,
            'otp_hash' => $this->hash($code),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'last_sent_at' => now(),
        ]);

        // Real send would go here (Mail::to($email)->send(new PassOtpMail($code))). Logged, not
        // faked as "sent" — see PassOtpController for the honest dev-mode response.
        \Illuminate\Support\Facades\Log::info('Pass OTP generated (email send not configured)', ['email' => $email, 'otp_id' => $record->id]);

        return ['otp_id' => $record->id, 'expires_in_seconds' => self::EXPIRY_MINUTES * 60, 'code_for_dev_only' => app()->environment(['local', 'testing']) ? $code : null];
    }

    /** @throws \RuntimeException on invalid/expired/exhausted OTP */
    public function verify(string $email, string $code, string $purpose = 'pass_purchase'): string
    {
        $key = "otp-verify:{$email}";
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new \RuntimeException('Too many verification attempts. Please request a new code.');
        }
        RateLimiter::hit($key, 600);

        $record = PassOtpVerification::where('email', $email)->where('purpose', $purpose)
            ->whereNull('verified_at')->latest()->first();

        if (! $record) {
            throw new \RuntimeException('No pending verification for this email. Request a new code.');
        }
        if ($record->expires_at->isPast()) {
            throw new \RuntimeException('This code has expired. Request a new one.');
        }
        if ($record->attempts >= $record->max_attempts) {
            throw new \RuntimeException('Too many incorrect attempts. Request a new code.');
        }

        if (! hash_equals($record->otp_hash, $this->hash($code))) {
            $record->increment('attempts');

            throw new \RuntimeException('Incorrect code.');
        }

        $sessionToken = Str::random(40);
        $record->update(['verified_at' => now(), 'session_token' => $sessionToken, 'session_consumed' => false]);

        return $sessionToken; // single-use, redeemed by consumeSession() during the actual purchase/login call
    }

    /** Redeems a verified OTP session exactly once — prevents replaying the same verification. */
    public function consumeSession(string $sessionToken, string $expectedEmail, string $purpose = 'pass_purchase'): void
    {
        $record = PassOtpVerification::where('session_token', $sessionToken)->where('purpose', $purpose)->first();

        if (! $record || $record->session_consumed || ! $record->verified_at || $record->email !== $expectedEmail) {
            throw new \RuntimeException('Email verification is missing or has already been used — please verify again.');
        }
        if ($record->verified_at->diffInMinutes(now()) > 30) {
            throw new \RuntimeException('Verification has expired — please verify your email again.');
        }

        $record->update(['session_consumed' => true]);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }
}
