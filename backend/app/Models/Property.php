<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    protected $fillable = [
        'name', 'address', 'phone', 'whatsapp_number', 'email',
        'check_in_time', 'check_out_time', 'currency', 'timezone',
        'tax_enabled', 'tax_rate_pct', 'cancellation_policy', 'payment_policy',
        'human_handoff_number',
    ];

    protected function casts(): array
    {
        return ['tax_enabled' => 'boolean', 'tax_rate_pct' => 'decimal:2'];
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function pricingRules()
    {
        return $this->hasMany(PricingRule::class);
    }

    public function calendarSources()
    {
        return $this->hasMany(CalendarSource::class);
    }
}
