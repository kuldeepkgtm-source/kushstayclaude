<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('passes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('pass_number')->unique(); // 1..150 for the Grand Opening batch -> "KS-PASS-001"
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('pass_product_id')->constrained('pass_products');
            $table->foreignId('customer_id')->constrained('customers');
            $table->unsignedInteger('price_paid_paise');
            $table->boolean('was_grand_opening_price')->default(true);
            $table->unsignedTinyInteger('total_days')->default(30);
            $table->unsignedTinyInteger('days_used')->default(0); // maintained cache; pass_ledger is the audit trail of how it got there
            $table->date('activated_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->enum('status', [
                'pending', 'payment_pending', 'paid', 'active', 'expired', 'suspended', 'cancelled', 'refunded',
            ])->default('pending');
            $table->timestamps();

            $table->index('status');
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passes');
    }
};
