<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyPaymentConfig extends Model
{
    protected $fillable = ['property_id', 'method_type', 'upi_id', 'upi_display_name', 'qr_image_path', 'api_credentials', 'status'];

    protected $hidden = ['api_credentials'];

    protected function casts(): array
    {
        return ['api_credentials' => 'encrypted:array'];
    }

    public function property() { return $this->belongsTo(Property::class); }

    /** Whether this method can give the platform an authoritative, real-time confirmation. */
    public function isApiConfirmable(): bool
    {
        return $this->method_type === 'api_gateway';
    }
}
