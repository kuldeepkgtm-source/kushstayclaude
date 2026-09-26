<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;
use App\Services\TenantContext;

class PropertyPolicy
{
    public function __construct(private TenantContext $tenant) {}

    public function view(User $user, Property $property): bool
    {
        return $this->tenant->hasAccess($user, $property->id);
    }

    public function update(User $user, Property $property): bool
    {
        return $this->tenant->hasRoleAtLeast($user, $property->id, ['property_owner', 'property_manager']);
    }

    public function manageBookings(User $user, Property $property): bool
    {
        return $this->tenant->hasRoleAtLeast($user, $property->id, ['property_owner', 'property_manager', 'front_desk']);
    }

    /** Payment destination changes — deliberately excludes front_desk/restaurant_manager/staff (spec §14). */
    public function managePaymentConfig(User $user, Property $property): bool
    {
        return $this->tenant->hasRoleAtLeast($user, $property->id, ['property_owner']);
    }

    public function manageWhatsAppConfig(User $user, Property $property): bool
    {
        return $this->tenant->hasRoleAtLeast($user, $property->id, ['property_owner', 'property_manager']);
    }

    public function manageRestaurant(User $user, Property $property): bool
    {
        return $this->tenant->hasRoleAtLeast($user, $property->id, ['property_owner', 'property_manager', 'restaurant_manager']);
    }

    public function activate(User $user, Property $property): bool
    {
        return $this->tenant->hasRoleAtLeast($user, $property->id, ['property_owner']);
    }
}
