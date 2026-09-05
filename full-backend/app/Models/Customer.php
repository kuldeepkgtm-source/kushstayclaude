<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'gender', 'id_type', 'id_number', 'address', 'notes'];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
