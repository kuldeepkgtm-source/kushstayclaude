<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // otp_hash stores hash('sha256', code.pepper) — never the plaintext code. See OtpService.
    public function up(): void
    {
        Schema::create('pass_otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('mobile', 20)->nullable();
            $table->string('otp_hash', 64);
            $table->string('purpose', 40)->default('purchase');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_otp_verifications');
    }
};
