<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Raw imported events, deduped by (calendar_source_id, external_uid) so re-importing the same
    // .ics never creates a duplicate — see IcalService::importEvents().
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_source_id')->constrained('calendar_sources')->cascadeOnDelete();
            $table->string('external_uid');
            $table->date('check_in');
            $table->date('check_out');
            $table->string('summary')->nullable();
            $table->enum('status', ['active', 'cancelled'])->default('active');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestamp('last_seen_at');
            $table->text('raw')->nullable(); // original VEVENT block, for debugging/audit
            $table->timestamps();

            $table->unique(['calendar_source_id', 'external_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
