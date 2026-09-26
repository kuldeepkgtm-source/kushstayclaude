<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassLedger extends Model
{
    protected $fillable = ['pass_id', 'event_type', 'change_days', 'balance_after', 'pass_booking_id', 'reason', 'created_by'];

    public function pass()
    {
        return $this->belongsTo(Pass::class);
    }

    public function passBooking()
    {
        return $this->belongsTo(PassBooking::class);
    }
}
