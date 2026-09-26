<?php

namespace App\Services;

use App\Exceptions\OtpException;
use App\Models\PassOtpVerification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Cryptographically-random 6-digit OTP, stored only as a hash, expiring in 10 minutes, capped
 * at 5 attempts, with a 60-second resend cooldown and IP+email rate limiting. Verification is
 * single-use (verified_at set) so a captured/replayed code can't be reused once consumed.
 */
class OtpService
{
    private const EXPIRY_MINUTES = 10;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    public function request(string $email, ?string $mobile, string $purpose, string $ip): PassOtpVerification
    {
        $limiterKey = "otp-request:{$email}:{$ip}";
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            throw new OtpException('Too many OTP requests. Please try again later.');
        }
        RateLimiter::hit($limiterKey, 3600);

        $existing = PassOtpVerification::where('email', $email)->where('purpose', $purpose)
            ->whereNull('verified_at')->latest()->first();
        if ($existing && $existing->last_sent_at && $existing->last_sent_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            $wait = self::RESEND_COOLDOWN_SECONDS - $existing->last_sent_at->diffInSeconds(now());
            throw new OtpException("Please wait {$wait}s before requesting another code.");
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $record = PassOtpVerification::create([
            'email' => $email,
            'mobile' => $mobile,
            'otp_hash' => $this->hash($code, $email),
            'purpose' => $purpose,
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'last_sent_at' => now(),
        ]);

        $this->sendEmail($email, $code);

        return $record;
    }

    public function verify(string $email, string $purpose, string $code): bool
    {
        $record = PassOtpVerification::where('email', $email)->where('purpose', $purpose)
            ->whereNull('verified_at')->latest()->first();

        if (! $record) {
            throw new OtpException('No pending verification found for this email.');
        }
        if ($record->expires_at->isPast()) {
            throw new OtpException('This code has expired. Please request a new one.');
        }
        if ($record->attempts >= $record->max_attempts) {
            throw new OtpException('Too many incorrect attempts. Please request a new code.');
        }

        if (! hash_equals($record->otp_hash, $this->hash($code, $email))) {
            $record->increment('attempts');

            throw new OtpException('Incorrect code.');
        }

        $record->update(['verified_at' => now()]);

        return true;
    }

    public function isVerified(string $email, string $purpose): bool
    {
        return PassOtpVerification::where('email', $email)->where('purpose', $purpose)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>', now()->subHour()) // a verification is only good for this purchase session, not forever
            ->exists();
    }

    private function hash(string $code, string $email): string
    {
        // Peppered with APP_KEY so a stolen DB dump alone can't be brute-forced offline.
        return hash_hmac('sha256', $code.'|'.$email, config('app.key'));
    }

    private function sendEmail(string $email, string $code): void
    {
        if (! config('mail.mailers.smtp.host')) {
            // No SMTP configured yet (fresh install) — log instead of pretending an email sent.
            \Illuminate\Support\Facades\Log::info("OTP for {$email}: {$code} (SMTP not configured — logged instead of emailed)");

            return;
        }
        Mail::raw("Your Kush Stay verification code is {$code}. It expires in ".self::EXPIRY_MINUTES." minutes.", function ($m) use ($email) {
            $m->to($email)->subject('Your Kush Stay verification code');
        });
    }
}
