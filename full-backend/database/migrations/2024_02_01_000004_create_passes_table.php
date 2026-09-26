<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('passes', function (Blueprint $table) {
            $table->id();
            $table->string('pass_ref', 20)->unique(); // KS-PASS-001
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('pass_product_id')->constrained('pass_products')->restrictOnDelete();
            $table->boolean('grand_opening')->default(true); // whether this pass counted against the 150 cap
            $table->unsignedInteger('price_paid_paise');
            $table->unsignedInteger('total_days')->default(30);
            $table->unsignedInteger('used_days')->default(0);
            $table->unsignedInteger('remaining_days')->default(30); // cached; pass_ledger is the source of truth, reconciled on write
            $table->enum('status', [
                'pending', 'payment_pending', 'paid', 'active', 'expired', 'suspended', 'cancelled', 'refunded',
            ])->default('pending');
            $table->timestamp('reserved_until')->nullable(); // payment_pending expiry — same pattern as holds.expires_at
            $table->date('activated_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('reserved_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passes');
    }
};
