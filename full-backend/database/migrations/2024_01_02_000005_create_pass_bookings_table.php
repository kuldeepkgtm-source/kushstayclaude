<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Deliberately thin: the actual stay is a normal row in `bookings`/`booking_beds` (source =
    // 'Pass'), created through the SAME BookingService/AvailabilityService as every other
    // channel. This table only adds the pass-specific accounting on top of a real booking.
    public function up(): void
    {
        Schema::create('pass_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pass_id')->constrained('passes');
            $table->foreignId('booking_id')->nullable()->unique()->constrained('bookings')->nullOnDelete();
            $table->foreignId('hold_id')->nullable()->constrained('holds')->nullOnDelete(); // set while awaiting upgrade payment
            $table->enum('bed_category', ['NAC-Upper', 'NAC-Lower', 'AC-Upper', 'AC-Lower']);
            $table->unsignedTinyInteger('nights');
            $table->unsignedTinyInteger('days_consumed'); // == nights, kept explicit per the spec's accounting model
            $table->unsignedInteger('upgrade_fee_paise')->default(0);
            $table->enum('status', ['upgrade_payment_pending', 'confirmed', 'cancelled'])->default('confirmed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_bookings');
    }
};
