<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassProduct extends Model
{
    protected $fillable = ['property_id', 'category', 'display_name', 'normal_price_paise', 'grand_opening_price_paise', 'total_days', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function currentPricePaise(PassSetting $settings): int
    {
        return $settings->grand_opening_active ? $this->grand_opening_price_paise : $this->normal_price_paise;
    }

    public function passes()
    {
        return $this->hasMany(Pass::class);
    }
}
