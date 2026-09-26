<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pass extends Model
{
    protected $fillable = [
        'pass_ref', 'property_id', 'customer_id', 'pass_product_id', 'grand_opening',
        'price_paid_paise', 'total_days', 'used_days', 'remaining_days', 'status',
        'reserved_until', 'activated_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['grand_opening' => 'boolean', 'reserved_until' => 'datetime', 'activated_at' => 'date', 'expires_at' => 'date'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function product()
    {
        return $this->belongsTo(PassProduct::class, 'pass_product_id');
    }

    public function ledger()
    {
        return $this->hasMany(PassLedgerEntry::class)->orderByDesc('id');
    }

    public function bookings()
    {
        return $this->hasMany(PassBooking::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(PassAuditLog::class);
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->remaining_days > 0;
    }
}
