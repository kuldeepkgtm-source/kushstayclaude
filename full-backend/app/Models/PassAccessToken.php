<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassAccessToken extends Model
{
    protected $fillable = ['pass_id', 'token', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function pass()
    {
        return $this->belongsTo(Pass::class);
    }

    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
