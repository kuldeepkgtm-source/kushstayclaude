<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use App\Services\TenantContext;

class BookingPolicy
{
    public function __construct(private TenantContext $tenant) {}

    public function view(User $user, Booking $booking): bool
    {
        return $this->tenant->hasAccess($user, $booking->property_id);
    }

    public function update(User $user, Booking $booking): bool
    {
        return $this->tenant->hasRoleAtLeast($user, $booking->property_id, ['property_owner', 'property_manager', 'front_desk']);
    }
}
