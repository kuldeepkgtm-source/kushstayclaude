<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // One row per bed in a booking. A private-room booking has 8 rows (one per bed in that room).
    // This table is the join AvailabilityService queries for the overlap check — see AvailabilityService::isBedFree().
    public function up(): void
    {
        Schema::create('booking_beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('bed_id')->constrained('beds')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['booking_id', 'bed_id']);
            $table->index('bed_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_beds');
    }
};
