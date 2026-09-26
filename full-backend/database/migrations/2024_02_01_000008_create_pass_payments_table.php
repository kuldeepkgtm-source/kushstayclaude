<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Covers both the pass purchase price and per-booking upgrade fees — exactly one of
    // pass_id / pass_booking_id is set, enforced in PassPaymentService rather than the schema
    // (keeping this a plain nullable pair is simpler than a polymorphic column for two cases).
    public function up(): void
    {
        Schema::create('pass_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pass_id')->nullable()->constrained('passes')->cascadeOnDelete();
            $table->foreignId('pass_booking_id')->nullable()->constrained('pass_bookings')->cascadeOnDelete();
            $table->enum('purpose', ['pass_purchase', 'upgrade_fee']);
            $table->unsignedInteger('amount_paise');
            $table->string('method', 40)->nullable();
            $table->enum('status', ['pending', 'successful', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->string('gateway_reference')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_payments');
    }
};
