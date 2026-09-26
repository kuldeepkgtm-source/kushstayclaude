<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Deliberately a single-row table so PassPurchaseService can SELECT ... FOR UPDATE this one
    // row to serialize concurrent purchases against the 150-pass limit (the standard, reliable
    // way to enforce a hard cap under concurrency — see PassPurchaseService::reserve()).
    public function up(): void
    {
        Schema::create('pass_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->boolean('grand_opening_active')->default(true);
            $table->unsignedInteger('grand_opening_limit')->default(150);
            $table->unsignedInteger('grand_opening_sold')->default(0); // includes payment_pending reservations
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_settings');
    }
};
