<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyWhatsAppConfig extends Model
{
    protected $fillable = ['property_id', 'phone_number', 'display_name', 'provider', 'credentials', 'status', 'enabled', 'last_connection_test_at', 'last_connection_status'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'enabled' => 'boolean', 'last_connection_test_at' => 'datetime'];
    }

    public function property() { return $this->belongsTo(Property::class); }
}
