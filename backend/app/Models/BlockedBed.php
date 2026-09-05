<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedBed extends Model
{
    protected $fillable = ['bed_id', 'starts_on', 'ends_on', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function bed()
    {
        return $this->belongsTo(Bed::class);
    }
}
