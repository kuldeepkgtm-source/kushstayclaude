<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassSetting extends Model
{
    protected $fillable = ['property_id', 'grand_opening_active', 'grand_opening_limit', 'grand_opening_sold'];

    protected function casts(): array
    {
        return ['grand_opening_active' => 'boolean'];
    }
}
