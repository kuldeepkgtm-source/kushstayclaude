<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // One row per property, strictly isolated (spec §10-11). `provider` stays generic since the
    // final WhatsApp API decision hasn't been made — credentials shape is provider-agnostic JSON.
    public function up(): void
    {
        Schema::create('property_whatsapp_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained('properties')->cascadeOnDelete();
            $table->string('phone_number')->nullable();
            $table->string('display_name')->nullable();
            $table->enum('provider', ['not_configured', 'meta_cloud_api', 'other'])->default('not_configured');
            $table->text('credentials')->nullable(); // encrypted:array — provider-specific tokens/verify_token/etc.
            $table->enum('status', ['not_configured', 'pending_verification', 'connected', 'error'])->default('not_configured');
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_connection_test_at')->nullable();
            $table->string('last_connection_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_whatsapp_configs');
    }
};
