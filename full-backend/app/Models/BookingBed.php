<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingBed extends Model
{
    protected $fillable = ['booking_id', 'bed_id'];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function bed()
    {
        return $this->belongsTo(Bed::class);
    }
}
