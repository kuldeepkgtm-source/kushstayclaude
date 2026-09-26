<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassOtpVerification extends Model
{
    protected $fillable = ['property_id', 'email', 'purpose', 'otp_hash', 'attempts', 'max_attempts', 'expires_at', 'last_sent_at', 'verified_at', 'session_token', 'session_consumed'];

    protected $hidden = ['otp_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_sent_at' => 'datetime', 'verified_at' => 'datetime', 'session_consumed' => 'boolean'];
    }
}
