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
            $table->enum('base_pass_category', ['NAC-Upper', 'NAC-Lower', 'AC-Upper', 'AC-Lower']);
            $table->enum('target_bed_category', ['NAC-Upper', 'NAC-Lower', 'AC-Upper', 'AC-Lower']);
            $table->unsignedInteger('fee_per_night_paise')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'base_pass_category', 'target_bed_category'], 'pass_upgrade_rate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_upgrade_rates');
    }
};
