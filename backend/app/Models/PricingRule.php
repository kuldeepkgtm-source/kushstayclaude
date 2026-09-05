<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingRule extends Model
{
    protected $fillable = [
        'property_id', 'bed_type', 'rule_type', 'price', 'surcharge_pct',
        'valid_from', 'valid_to', 'day_of_week',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2', 'surcharge_pct' => 'decimal:2',
            'valid_from' => 'date', 'valid_to' => 'date',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
