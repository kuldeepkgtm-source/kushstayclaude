<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Server-timed hold — expires_at is set from the DB server's NOW(), never the browser clock.
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('bed_id')->constrained('beds')->cascadeOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->string('session_token', 64); // ties multiple bed-holds from one chat/browser session together
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['bed_id', 'check_in', 'check_out']);
            $table->index('expires_at');
            $table->index('session_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};
