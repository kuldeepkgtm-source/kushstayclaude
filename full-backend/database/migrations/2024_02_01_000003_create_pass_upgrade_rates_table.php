<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pass_upgrade_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->enum('base_category', ['AC-Upper', 'AC-Lower', 'NAC-Upper', 'NAC-Lower']);
            $table->enum('target_category', ['AC-Upper', 'AC-Lower', 'NAC-Upper', 'NAC-Lower']);
            $table->unsignedInteger('fee_per_night_paise');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'base_category', 'target_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_upgrade_rates');
    }
};
