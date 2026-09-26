<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Idempotency ledger for gateway webhooks (spec §80): the unique constraint on
    // (gateway, event_id) is what makes "the same webhook arrives five times" a no-op after the
    // first — see PhonePeWebhookController, which inserts here BEFORE acting on a webhook and
    // simply returns 200-already-processed on a duplicate-key hit instead of re-applying effects.
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->enum('gateway', ['phonepe', 'dummy']);
            $table->string('event_id'); // gateway's transaction/order id — the de-dup key
            $table->string('event_type')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
