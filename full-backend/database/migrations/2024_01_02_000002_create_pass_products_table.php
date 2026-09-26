<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // One row per bed category the pass can be bought for. Grand-opening on/off and the 150-pass
    // limit are NOT columns here (they're shared, property-wide toggles) — they live in the
    // existing `settings` table as pass_grand_opening_active / pass_grand_opening_limit /
    // pass_grand_opening_sold_count, reusing the existing key-value settings mechanism.
    public function up(): void
    {
        Schema::create('pass_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->enum('bed_category', ['NAC-Upper', 'NAC-Lower', 'AC-Upper', 'AC-Lower'])->unique();
            $table->string('display_name');
            $table->unsignedInteger('normal_price_paise');
            $table->unsignedInteger('grand_opening_price_paise');
            $table->unsignedTinyInteger('total_entitlement_days')->default(30);
            $table->unsignedTinyInteger('validity_years')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_products');
    }
};
