<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Append-only audit trail of every balance change. passes.remaining_days is a cache;
    // this table is the ledger of record — see PassLedgerService.
    public function up(): void
    {
        Schema::create('pass_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pass_id')->constrained('passes')->cascadeOnDelete();
            $table->enum('event_type', ['grant', 'booking', 'cancellation', 'refund', 'adjustment', 'expiration']);
            $table->integer('day_change'); // signed: +30 grant, -2 booking, +2 cancellation reversal, etc.
            $table->unsignedInteger('balance_after');
            $table->foreignId('pass_booking_id')->nullable()->constrained('pass_bookings')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // null = system/customer action
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_ledger');
    }
};
