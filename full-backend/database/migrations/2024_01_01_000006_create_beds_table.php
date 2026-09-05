<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('code', 16)->unique(); // e.g. 'AC-U1'
            $table->enum('position', ['Upper', 'Lower']);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['room_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};
