<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // A booking can have several payment attempts/installments; bookings.amount_paid is the running total.
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('method', 40); // UPI, Card, Cash, Cash at property, Pay later
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'success', 'failed', 'refunded'])->default('pending');
            $table->string('gateway_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
