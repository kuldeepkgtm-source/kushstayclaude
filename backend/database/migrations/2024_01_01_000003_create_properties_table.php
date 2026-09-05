<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('email')->nullable();
            $table->string('check_in_time')->default('12:00 PM');
            $table->string('check_out_time')->default('11:00 AM');
            $table->string('currency', 8)->default('INR');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->boolean('tax_enabled')->default(false);
            $table->decimal('tax_rate_pct', 5, 2)->default(0);
            $table->text('cancellation_policy')->nullable();
            $table->text('payment_policy')->nullable();
            $table->string('human_handoff_number')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
