<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bed extends Model
{
    protected $fillable = ['room_id', 'code', 'position', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function bookingBeds()
    {
        return $this->hasMany(BookingBed::class);
    }

    public function holds()
    {
        return $this->hasMany(Hold::class);
    }

    public function blocks()
    {
        return $this->hasMany(BlockedBed::class);
    }
}
