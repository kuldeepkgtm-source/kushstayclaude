<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('name'); // Booking.com, Airbnb, ...
            $table->enum('type', ['ota', 'ical'])->default('ical');
            $table->string('ical_url')->nullable(); // import source (pulled by cron)
            $table->string('export_token', 64)->nullable()->unique(); // this property's own secure export token
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete(); // null = whole property
            $table->foreignId('bed_id')->nullable()->constrained('beds')->nullOnDelete(); // most specific mapping wins
            $table->unsignedSmallInteger('sync_frequency_minutes')->default(60);
            $table->enum('status', ['Active', 'Paused'])->default('Active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_sources');
    }
};
