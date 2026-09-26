<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    // Adds 'Pass' to bookings.source so pass-funded stays are ordinary rows in the SAME bookings
    // table (and therefore the SAME availability engine) as every other channel — no parallel
    // inventory for pass customers, per the spec's "Inventory Sharing" requirement.
    public function up(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY source ENUM(
            'WhatsApp AI','Direct','Website','Walk-in','Phone',
            'Booking.com','Airbnb','MakeMyTrip','Goibibo','Other OTA',
            'iCal Import','Manual/Admin','Pass'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY source ENUM(
            'WhatsApp AI','Direct','Website','Walk-in','Phone',
            'Booking.com','Airbnb','MakeMyTrip','Goibibo','Other OTA',
            'iCal Import','Manual/Admin'
        ) NOT NULL");
    }
};
