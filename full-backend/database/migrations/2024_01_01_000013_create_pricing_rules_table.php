<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Generalizes the prototype's flat DEFAULT_PRICES + weekendSurchargePct into date-scoped rules,
    // so weekday/weekend/seasonal/special-date pricing can all be expressed as rows instead of code.
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->enum('bed_type', ['AC-Upper', 'AC-Lower', 'NAC-Upper', 'NAC-Lower', 'AC-Private', 'NAC-Private']);
            $table->enum('rule_type', ['base', 'weekend', 'seasonal', 'special_date'])->default('base');
            $table->decimal('price', 10, 2)->nullable(); // absolute price (used by base/seasonal/special_date)
            $table->decimal('surcharge_pct', 5, 2)->nullable(); // relative surcharge (used by weekend)
            $table->date('valid_from')->nullable(); // null = always, for 'base' rows
            $table->date('valid_to')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0=Sun..6=Sat, used by 'weekend' rows
            $table->timestamps();

            $table->index(['property_id', 'bed_type', 'rule_type']);
            $table->index(['valid_from', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
