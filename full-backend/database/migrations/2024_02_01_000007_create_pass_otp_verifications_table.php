<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // otp_hash = hash_hmac('sha256', $code, config('app.key')) — never the plaintext code.
    public function up(): void
    {
        Schema::create('pass_otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('purpose', 40)->default('pass_purchase');
            $table->string('otp_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('session_token', 64)->nullable()->unique(); // issued only after successful verify
            $table->boolean('session_consumed')->default(false); // single-use — see PassOtpService::consumeSession()
            $table->timestamps();

            $table->index(['email', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_otp_verifications');
    }
};
