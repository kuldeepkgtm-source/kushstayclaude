<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained('room_types')->nullOnDelete();
            $table->string('code', 8); // 'AC' or 'NAC' today
            $table->string('name'); // "AC Dormitory", "Non-AC Dormitory"
            $table->boolean('is_ac')->default(false);
            $table->timestamps();

            $table->unique(['property_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
