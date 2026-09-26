<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassPayment extends Model
{
    protected $fillable = ['pass_id', 'pass_booking_id', 'purpose', 'amount_paise', 'method', 'status', 'gateway_reference', 'confirmed_at'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function pass()
    {
        return $this->belongsTo(Pass::class);
    }

    public function passBooking()
    {
        return $this->belongsTo(PassBooking::class);
    }
}
