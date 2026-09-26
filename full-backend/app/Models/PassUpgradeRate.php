<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassUpgradeRate extends Model
{
    protected $fillable = ['property_id', 'base_category', 'target_category', 'fee_per_night_paise', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
