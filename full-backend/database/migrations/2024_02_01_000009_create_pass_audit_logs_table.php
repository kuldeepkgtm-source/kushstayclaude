<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pass_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pass_id')->constrained('passes')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // suspend, reactivate, cancel, extend_expiry, adjust_balance
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_audit_logs');
    }
};
