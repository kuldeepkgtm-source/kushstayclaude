<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\Booking;
use App\Models\Hold;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for bed availability. Every caller — the admin UI, the WhatsApp
 * webhook, iCal import, and OTA sync — goes through this class. None of them may keep a
 * separate availability list (this is the direct server-side port of the prototype's
 * isBedFree() / getAvailableBeds() / isPrivateAvailable() functions).
 */
class AvailabilityService
{
    /**
     * Is a single bed free for [checkIn, checkOut)?
     * Overlap rule: existing_check_in < requested_check_out AND existing_check_out > requested_check_in.
     * The checkout date itself is never an occupied night.
     */
    public function isBedFree(int $bedId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): bool
    {
        $bed = Bed::findOrFail($bedId);

        if (! $bed->active) {
            return false;
        }

        $blocked = $bed->blocks()
            ->where(function ($q) use ($checkIn, $checkOut) {
                $q->whereNull('starts_on')
                    ->orWhere(function ($q2) use ($checkIn, $checkOut) {
                        $q2->where('starts_on', '<', $checkOut)->where('ends_on', '>', $checkIn);
                    });
            })->exists();
        if ($blocked) {
            return false;
        }

        $bookedConflict = Booking::query()
            ->active()
            ->overlapping($checkIn, $checkOut)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->whereHas('beds', fn ($q) => $q->where('beds.id', $bedId))
            ->exists();
        if ($bookedConflict) {
            return false;
        }

        $heldConflict = Hold::query()
            ->active() // expires_at > now(), server time
            ->overlapping($checkIn, $checkOut)
            ->where('bed_id', $bedId)
            ->exists();

        return ! $heldConflict;
    }

    /** All active beds of a room (optionally filtered to Upper/Lower) free for the given range. */
    public function availableBeds(int $roomId, string $checkIn, string $checkOut, ?string $position = null): Collection
    {
        $beds = Bed::where('room_id', $roomId)->where('active', true)
            ->when($position, fn ($q) => $q->where('position', $position))
            ->get();

        return $beds->filter(fn (Bed $bed) => $this->isBedFree($bed->id, $checkIn, $checkOut));
    }

    /**
     * A room may be sold PRIVATE only when ALL of its beds are free for the entire stay.
     * Returns ['available' => bool, 'freeCount' => int, 'totalBeds' => int, 'reason' => ?string].
     */
    public function isPrivateAvailable(int $roomId, string $checkIn, string $checkOut): array
    {
        $total = Bed::where('room_id', $roomId)->where('active', true)->count();
        $free = $this->availableBeds($roomId, $checkIn, $checkOut)->count();

        return [
            'available' => $total > 0 && $free === $total,
            'freeCount' => $free,
            'totalBeds' => $total,
            'reason' => $free === $total ? null : ($total - $free).' of '.$total.' beds already booked/held/blocked for these dates',
        ];
    }

    /** Convenience: private availability for both rooms of a property, keyed by room code. */
    public function privateAvailabilityForProperty(int $propertyId, string $checkIn, string $checkOut): array
    {
        $rooms = Room::where('property_id', $propertyId)->get();
        $result = [];
        foreach ($rooms as $room) {
            $result[$room->code] = $this->isPrivateAvailable($room->id, $checkIn, $checkOut);
        }

        return $result;
    }
}
