<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassBooking extends Model
{
    protected $fillable = ['pass_id', 'booking_id', 'base_category', 'used_category', 'nights', 'days_consumed', 'upgrade_fee_paise', 'upgrade_payment_status'];

    public function pass()
    {
        return $this->belongsTo(Pass::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function payments()
    {
        return $this->hasMany(PassPayment::class);
    }
}
