<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Every change to a pass's day balance is a row here FIRST; passes.days_used is only ever
    // updated in the same transaction as the ledger row that explains the change. The balance
    // must never go negative — enforced in PassService, not just at the DB layer.
    public function up(): void
    {
        Schema::create('pass_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pass_id')->constrained('passes')->cascadeOnDelete();
            $table->enum('event_type', ['grant', 'booking', 'cancellation', 'refund', 'adjustment', 'expiration']);
            $table->integer('change_days'); // signed: +30 grant, -2 booking, +2 cancellation reversal, ...
            $table->unsignedTinyInteger('balance_after');
            $table->foreignId('pass_booking_id')->nullable()->constrained('pass_bookings')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_ledger');
    }
};
