<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Corrected business model (spec §5): guest money goes to the PROPERTY's own destination,
    // not a pooled Kush Stay account. Two method_type shapes:
    //   - upi_manual: a UPI ID/QR the guest pays to directly. This is NOT API-confirmable — the
    //     platform cannot know payment succeeded in real time. Bookings paid this way sit in a
    //     manual-verification state until staff confirm (see PaymentConfigService doc-block).
    //   - api_gateway: reserved for a future API-enabled provider behind PaymentGatewayInterface,
    //     which CAN give authoritative confirmation via webhook. No provider is implemented yet —
    //     api_credentials stays null until one is, per "do not implement PhonePe prematurely."
    // upi_id/upi_display_name are NOT secret (guests must see them to pay) so they're plain
    // columns; only api_credentials (a real future provider's API secrets) is encrypted.
    public function up(): void
    {
        Schema::create('property_payment_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained('properties')->cascadeOnDelete();
            $table->enum('method_type', ['upi_manual', 'api_gateway'])->default('upi_manual');
            $table->string('upi_id')->nullable();
            $table->string('upi_display_name')->nullable();
            $table->string('qr_image_path')->nullable();
            $table->text('api_credentials')->nullable(); // encrypted:array cast — see PropertyPaymentConfig model
            $table->enum('status', ['active', 'pending_change', 'inactive'])->default('inactive');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_payment_configs');
    }
};
