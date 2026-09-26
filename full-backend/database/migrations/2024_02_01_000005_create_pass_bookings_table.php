<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Thin extension of a REAL row in the existing `bookings` table — pass bookings share the
    // exact same bookings/booking_beds inventory as WhatsApp/Direct/OTA bookings. This table
    // only carries the pass-specific facts: which category the pass was bought at vs. which
    // bed category was actually used, and the resulting upgrade fee.
    public function up(): void
    {
        Schema::create('pass_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pass_id')->constrained('passes')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->enum('base_category', ['AC-Upper', 'AC-Lower', 'NAC-Upper', 'NAC-Lower']);
            $table->enum('used_category', ['AC-Upper', 'AC-Lower', 'NAC-Upper', 'NAC-Lower']);
            $table->unsignedInteger('nights');
            $table->unsignedInteger('days_consumed')->default(0); // 0 until upgrade payment (if any) clears
            $table->unsignedInteger('upgrade_fee_paise')->default(0);
            $table->enum('upgrade_payment_status', ['not_required', 'pending', 'paid', 'failed', 'refunded'])->default('not_required');
            $table->timestamps();

            $table->unique('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_bookings');
    }
};
