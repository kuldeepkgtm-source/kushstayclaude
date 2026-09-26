<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Polymorphic so one table covers both a pass PURCHASE payment and a pass-BOOKING upgrade-fee
    // payment, without touching the existing (booking_id-scoped) `payments` table used by regular
    // stays — keeps this feature additive and zero-risk to what already works.
    public function up(): void
    {
        Schema::create('pass_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payable_type'); // App\Models\Pass or App\Models\PassBooking
            $table->unsignedBigInteger('payable_id');
            $table->unsignedInteger('amount_paise');
            $table->string('currency', 8)->default('INR');
            $table->enum('status', ['pending', 'successful', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->string('gateway', 40)->default('dummy'); // 'dummy' today; 'razorpay' etc. later via the same interface
            $table->string('gateway_reference')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_payments');
    }
};
