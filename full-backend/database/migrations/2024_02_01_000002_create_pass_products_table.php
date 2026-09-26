<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // category reuses the same 'AC-Upper'/'AC-Lower'/'NAC-Upper'/'NAC-Lower' vocabulary already
    // used by pricing_rules.bed_type, instead of inventing new category strings.
    public function up(): void
    {
        Schema::create('pass_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->enum('category', ['AC-Upper', 'AC-Lower', 'NAC-Upper', 'NAC-Lower']);
            $table->string('display_name');
            $table->unsignedInteger('normal_price_paise');
            $table->unsignedInteger('grand_opening_price_paise');
            $table->unsignedInteger('total_days')->default(30);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_products');
    }
};
