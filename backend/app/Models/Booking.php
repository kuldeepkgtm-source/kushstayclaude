<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'booking_ref', 'property_id', 'customer_id', 'customer_name', 'customer_phone',
        'source', 'check_in', 'check_out', 'guest_count', 'booking_type', 'room_id',
        'subtotal', 'discount', 'tax', 'total', 'amount_paid', 'balance',
        'payment_status', 'payment_method', 'booking_status', 'external_booking_id', 'special_request',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date', 'check_out' => 'date',
            'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2',
            'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'balance' => 'decimal:2',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function bookingBeds()
    {
        return $this->hasMany(BookingBed::class);
    }

    public function beds()
    {
        return $this->belongsToMany(Bed::class, 'booking_beds');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('booking_status', '!=', 'Cancelled');
    }

    // The exact overlap rule from the prototype's overlap(): existing_check_in < requested_check_out
    // AND existing_check_out > requested_check_in. Checkout night is never occupied.
    public function scopeOverlapping($query, string $checkIn, string $checkOut)
    {
        return $query->where('check_in', '<', $checkOut)->where('check_out', '>', $checkIn);
    }
}
