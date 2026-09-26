<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Property;
use App\Models\PropertyStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code path allowed to change Property::status. Every transition is checked against an
 * explicit whitelist below — there is no "set status to whatever the request says." Every
 * transition writes a property_status_history row and an audit_logs row in the same transaction,
 * recording who, when, from what, to what, and why (spec §3).
 *
 * Interpretation note (stated plainly, since the spec described this as a sequence rather than
 * two fully-specified independent actions): APPROVED is the Kush Stay admin's sign-off that a
 * property has been reviewed and is allowed to proceed; LIVE is a separate, owner-triggered
 * activation once they've actually finished configuring rooms/pricing/payment — matching §1's
 * "...wait for approval... become LIVE only after approval" (LIVE happens *after*, not *as*,
 * approval). An admin can still suspend a LIVE property directly.
 */
class PropertyApprovalService
{
    private const TRANSITIONS = [
        'DRAFT' => ['SUBMITTED'],
        'SUBMITTED' => ['UNDER_REVIEW', 'CHANGES_REQUIRED', 'REJECTED'],
        'UNDER_REVIEW' => ['APPROVED', 'CHANGES_REQUIRED', 'REJECTED'],
        'CHANGES_REQUIRED' => ['SUBMITTED'],
        'APPROVED' => ['LIVE', 'SUSPENDED'],
        'LIVE' => ['SUSPENDED', 'CLOSED'],
        'SUSPENDED' => ['LIVE', 'CLOSED'],
        'REJECTED' => [], // terminal — a rejected owner must create a new property to try again
        'CLOSED' => [],   // terminal
    ];

    public function submit(Property $property, User $actor): Property
    {
        return $this->transition($property, $actor, 'SUBMITTED', null);
    }

    public function moveToReview(Property $property, User $admin): Property
    {
        $this->requireSuperAdmin($admin);

        return $this->transition($property, $admin, 'UNDER_REVIEW', null);
    }

    public function approve(Property $property, User $admin, ?string $notes = null): Property
    {
        $this->requireSuperAdmin($admin);

        return $this->transition($property, $admin, 'APPROVED', $notes);
    }

    public function reject(Property $property, User $admin, string $reason): Property
    {
        $this->requireSuperAdmin($admin);

        return $this->transition($property, $admin, 'REJECTED', $reason);
    }

    public function requestChanges(Property $property, User $admin, string $reason): Property
    {
        $this->requireSuperAdmin($admin);

        return $this->transition($property, $admin, 'CHANGES_REQUIRED', $reason);
    }

    /** Owner-triggered — only once an admin has already approved. */
    public function activate(Property $property, User $owner): Property
    {
        return $this->transition($property, $owner, 'LIVE', null);
    }

    public function suspend(Property $property, User $admin, string $reason): Property
    {
        $this->requireSuperAdmin($admin);

        return $this->transition($property, $admin, 'SUSPENDED', $reason);
    }

    public function reactivate(Property $property, User $admin, ?string $notes = null): Property
    {
        $this->requireSuperAdmin($admin);

        return $this->transition($property, $admin, 'LIVE', $notes);
    }

    private function transition(Property $property, User $actor, string $to, ?string $reason): Property
    {
        return DB::transaction(function () use ($property, $actor, $to, $reason) {
            $locked = Property::lockForUpdate()->findOrFail($property->id);
            $from = $locked->status;

            $allowed = self::TRANSITIONS[$from] ?? [];
            if (! in_array($to, $allowed, true)) {
                throw new \RuntimeException("Cannot move property from {$from} to {$to}.");
            }

            $locked->update(['status' => $to]);

            PropertyStatusHistory::create([
                'property_id' => $locked->id, 'from_status' => $from, 'to_status' => $to,
                'changed_by' => $actor->id, 'reason' => $reason,
            ]);

            AuditLog::create([
                'user_id' => $actor->id, 'property_id' => $locked->id, 'action' => 'property.status_changed',
                'entity_type' => 'Property', 'entity_id' => $locked->id,
                'before' => ['status' => $from], 'after' => ['status' => $to], 
            ]);

            return $locked->fresh();
        });
    }

    private function requireSuperAdmin(User $user): void
    {
        abort_unless($user->is_super_admin, 403, 'Only a Kush Stay Super Admin may perform this action.');
    }
}
