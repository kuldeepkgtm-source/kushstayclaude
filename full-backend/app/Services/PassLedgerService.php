<?php

namespace App\Services;

use App\Models\Pass;
use App\Models\PassLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes pass_ledger rows or touches passes.used_days/remaining_days.
 * Every call MUST run inside a transaction that already holds a row lock on the Pass (callers
 * are expected to have done `Pass::lockForUpdate()` first) — this service does not lock itself,
 * so it can be composed inside a larger locked transaction without a second, conflicting lock.
 */
class PassLedgerService
{
    public function record(Pass $pass, string $eventType, int $dayChange, ?int $passBookingId = null, ?string $reason = null, ?int $createdBy = null): PassLedgerEntry
    {
        $newBalance = $pass->remaining_days + $dayChange;
        if ($newBalance < 0) {
            throw new \RuntimeException('Pass balance cannot go negative.');
        }

        $entry = PassLedgerEntry::create([
            'pass_id' => $pass->id,
            'event_type' => $eventType,
            'day_change' => $dayChange,
            'balance_after' => $newBalance,
            'pass_booking_id' => $passBookingId,
            'created_by' => $createdBy,
            'reason' => $reason,
        ]);

        $pass->update([
            'remaining_days' => $newBalance,
            'used_days' => $pass->total_days - $newBalance,
        ]);

        return $entry;
    }
}
