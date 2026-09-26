<?php

namespace App\Policies;

use App\Models\Pass;
use App\Models\User;
use App\Services\TenantContext;

class PassPolicy
{
    public function __construct(private TenantContext $tenant) {}

    public function view(User $user, Pass $pass): bool
    {
        return $this->tenant->hasAccess($user, $pass->property_id);
    }

    public function manage(User $user, Pass $pass): bool
    {
        return $this->tenant->hasRoleAtLeast($user, $pass->property_id, ['property_owner', 'property_manager', 'front_desk']);
    }
}
