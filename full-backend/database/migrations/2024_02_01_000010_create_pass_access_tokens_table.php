<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Scoped, customer-facing auth for "My Pass" — deliberately separate from the admin `users`/
    // Sanctum tokens, since pass-holders are customers, not staff accounts. Issued after an
    // email-OTP verification (PassOtpService) proves control of the email on the pass.
    public function up(): void
    {
        Schema::create('pass_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pass_id')->constrained('passes')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_access_tokens');
    }
};
