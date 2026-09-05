<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $fillable = ['property_id', 'bed_id', 'check_in', 'check_out', 'session_token', 'expires_at'];

    protected function casts(): array
    {
        return ['check_in' => 'date', 'check_out' => 'date', 'expires_at' => 'datetime'];
    }

    public function bed()
    {
        return $this->belongsTo(Bed::class);
    }

    public function scopeActive($query)
    {
        // Server time only — this is the whole point of moving holds server-side.
        return $query->where('expires_at', '>', now());
    }

    public function scopeOverlapping($query, string $checkIn, string $checkOut)
    {
        return $query->where('check_in', '<', $checkOut)->where('check_out', '>', $checkIn);
    }
}
