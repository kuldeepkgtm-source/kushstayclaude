<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $fillable = [
        'calendar_source_id', 'external_uid', 'check_in', 'check_out',
        'summary', 'status', 'booking_id', 'last_seen_at', 'raw',
    ];

    protected function casts(): array
    {
        return ['check_in' => 'date', 'check_out' => 'date', 'last_seen_at' => 'datetime'];
    }

    public function source()
    {
        return $this->belongsTo(CalendarSource::class, 'calendar_source_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
