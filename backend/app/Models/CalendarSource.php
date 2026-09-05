<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarSource extends Model
{
    protected $fillable = [
        'property_id', 'name', 'type', 'ical_url', 'export_token',
        'room_id', 'bed_id', 'sync_frequency_minutes', 'status', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function bed()
    {
        return $this->belongsTo(Bed::class);
    }

    public function events()
    {
        return $this->hasMany(CalendarEvent::class);
    }
}
