<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // One reusable table for both property-approval submissions and payment-destination change
    // requests (spec §1 + §7), instead of two near-identical bespoke workflows. `payload` is the
    // proposed new data; `previous_snapshot` is what was active before (so "old config remains
    // active until approved" is provable, not just asserted). Nothing here ever flips a resource
    // live on its own — PropertyApprovalService/PaymentConfigService apply the effect only after
    // status becomes 'approved', inside a transaction, with an audit_logs row.
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->enum('type', ['property_approval', 'payment_config_change']);
            $table->enum('status', ['pending', 'changes_requested', 'approved', 'rejected'])->default('pending');
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->json('payload')->nullable();
            $table->json('previous_snapshot')->nullable();
            $table->json('submitted_documents')->nullable(); // file references only — no binary storage here
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
