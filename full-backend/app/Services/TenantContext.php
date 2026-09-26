<?php

namespace App\Services;

use App\Models\Property;
use App\Models\User;

/**
 * The single place that answers "may this user touch this property?" — every policy and
 * controller in the platform is expected to route through here rather than re-deriving the
 * answer itself. A super admin passes every check; everyone else needs an explicit
 * property_users row. A client-supplied property_id is NEVER, by itself, sufficient — it must
 * always be checked against this.
 */
class TenantContext
{
    public function isSuperAdmin(User $user): bool
    {
        return (bool) $user->is_super_admin;
    }

    public function hasAccess(User $user, int $propertyId): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->propertyUsers()->where('property_id', $propertyId)->exists();
    }

    /** Null if the user has no explicit membership (even if they're a super admin — super admins act on any property without "having a role" on it). */
    public function roleFor(User $user, int $propertyId): ?string
    {
        return $user->roleForProperty($propertyId);
    }

    public function hasRoleAtLeast(User $user, int $propertyId, array $allowedRoles): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        $role = $this->roleFor($user, $propertyId);

        return $role !== null && in_array($role, $allowedRoles, true);
    }

    /** All property IDs this user may act on — every property for a super admin, else only memberships. */
    public function accessiblePropertyIds(User $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return Property::query()->pluck('id')->all();
        }

        return $user->propertyUsers()->pluck('property_id')->all();
    }

    /**
     * Resolves and authorizes a property from a request-supplied ID. Throws (via abort) rather
     * than returning null on failure, so callers can't accidentally continue past a denied check.
     */
    public function authorize(User $user, int $propertyId, array $allowedRoles = []): Property
    {
        $property = Property::findOrFail($propertyId);
        $ok = $allowedRoles ? $this->hasRoleAtLeast($user, $propertyId, $allowedRoles) : $this->hasAccess($user, $propertyId);
        abort_unless($ok, 403, 'You do not have access to this property.');

        return $property;
    }
}
