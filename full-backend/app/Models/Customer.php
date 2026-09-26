<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Model
{
    use HasApiTokens; // lets a customer hold a scoped Sanctum token after OTP verification, for
                       // /api/pass/me* — kept separate from the admin User/role auth entirely.

    protected $fillable = ['property_id', 'name', 'phone', 'email', 'gender', 'id_type', 'id_number', 'address', 'notes'];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
