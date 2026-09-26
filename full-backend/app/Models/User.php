<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['role_id', 'name', 'email', 'password', 'is_active', 'is_super_admin'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'is_super_admin' => 'boolean'];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function hasRole(string $name): bool
    {
        return $this->role && $this->role->name === $name;
    }

    /** Every property this user has an explicit property_users row for (super admins bypass this — see TenantContext). */
    public function properties()
    {
        return $this->belongsToMany(Property::class, 'property_users')->withPivot('role')->withTimestamps();
    }

    public function propertyUsers()
    {
        return $this->hasMany(PropertyUser::class);
    }

    public function roleForProperty(int $propertyId): ?string
    {
        return $this->propertyUsers->firstWhere('property_id', $propertyId)?->role;
    }
}
