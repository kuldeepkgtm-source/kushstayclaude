<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Server-controlled lifecycle (spec §1/§3). Nothing in the frontend can set this directly —
    // only PropertyApprovalService writes it, and only via a validated transition. Default DRAFT:
    // a property is invisible/unbookable the instant it's created, with no implicit "on" state.
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->enum('status', [
                'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'CHANGES_REQUIRED',
                'APPROVED', 'LIVE', 'SUSPENDED', 'REJECTED', 'CLOSED',
            ])->default('DRAFT')->after('id');
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('status');
        });
    }
};
