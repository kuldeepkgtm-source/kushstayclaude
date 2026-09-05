<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = ['property_id', 'room_type_id', 'code', 'name', 'is_ac'];

    protected function casts(): array
    {
        return ['is_ac' => 'boolean'];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function beds()
    {
        return $this->hasMany(Bed::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
