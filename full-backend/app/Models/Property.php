<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    protected $fillable = [
        'status', 'created_by', 'name', 'address', 'phone', 'whatsapp_number', 'email',
        'check_in_time', 'check_out_time', 'currency', 'timezone',
        'tax_enabled', 'tax_rate_pct', 'cancellation_policy', 'payment_policy',
        'human_handoff_number',
    ];

    protected function casts(): array
    {
        return ['tax_enabled' => 'boolean', 'tax_rate_pct' => 'decimal:2'];
    }

    // Server-controlled lifecycle — see PropertyApprovalService. Nothing outside that service
    // should ever do Property::update(['status' => ...]) directly.
    public const STATUSES = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'CHANGES_REQUIRED', 'APPROVED', 'LIVE', 'SUSPENDED', 'REJECTED', 'CLOSED'];

    public function isLive(): bool
    {
        return $this->status === 'LIVE';
    }

    public function statusHistory()
    {
        return $this->hasMany(PropertyStatusHistory::class)->orderByDesc('id');
    }

    public function approvalRequests()
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function paymentConfig()
    {
        return $this->hasOne(PropertyPaymentConfig::class);
    }

    public function whatsappConfig()
    {
        return $this->hasOne(PropertyWhatsAppConfig::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    /** Users with explicit access to this property (via property_users), not including super admins. */
    public function users()
    {
        return $this->belongsToMany(User::class, 'property_users')->withPivot('role')->withTimestamps();
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function pricingRules()
    {
        return $this->hasMany(PricingRule::class);
    }

    public function calendarSources()
    {
        return $this->hasMany(CalendarSource::class);
    }
}
