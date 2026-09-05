<?php

namespace App\Services;

use App\Exceptions\BookingConflictException;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 10-minute holds, timed by the database server — never the browser. A frozen or
 * fast-forwarded client clock cannot extend or fake a hold; only now() on the DB
 * connection is ever consulted (see Hold::scopeActive()).
 */
class HoldService
{
    public function __construct(private AvailabilityService $availability) {}

    public function createHold(int $propertyId, array $bedIds, string $checkIn, string $checkOut, ?string $sessionToken = null, int $minutes = 10): array
    {
        $sessionToken ??= (string) Str::uuid();

        return DB::transaction(function () use ($propertyId, $bedIds, $checkIn, $checkOut, $sessionToken, $minutes) {
            \App\Models\Bed::whereIn('id', $bedIds)->lockForUpdate()->get();

            foreach ($bedIds as $bedId) {
                if (! $this->availability->isBedFree($bedId, $checkIn, $checkOut)) {
                    throw new BookingConflictException("Bed {$bedId} is no longer available.", [$bedId]);
                }
            }

            $expiresAt = now()->addMinutes($minutes);
            $holds = [];
            foreach ($bedIds as $bedId) {
                $holds[] = Hold::create([
                    'property_id' => $propertyId,
                    'bed_id' => $bedId,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'session_token' => $sessionToken,
                    'expires_at' => $expiresAt,
                ]);
            }

            return ['session_token' => $sessionToken, 'expires_at' => $expiresAt->toIso8601String(), 'holds' => $holds];
        });
    }

    public function release(int $holdId): void
    {
        Hold::whereKey($holdId)->delete();
    }

    public function releaseBySessionToken(string $sessionToken): void
    {
        Hold::where('session_token', $sessionToken)->delete();
    }

    /** Called by the scheduled command below, but also safe to call inline — either way, always server time. */
    public function purgeExpired(): int
    {
        return Hold::where('expires_at', '<=', now())->delete();
    }
}
