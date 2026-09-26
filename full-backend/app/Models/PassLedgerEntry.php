<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassLedgerEntry extends Model
{
    protected $table = 'pass_ledger';

    protected $fillable = ['pass_id', 'event_type', 'day_change', 'balance_after', 'pass_booking_id', 'created_by', 'reason'];

    public function pass()
    {
        return $this->belongsTo(Pass::class);
    }
}
