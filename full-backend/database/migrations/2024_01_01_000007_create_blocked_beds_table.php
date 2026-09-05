<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Admin "maintenance block" on a bed — either indefinite (dates null) or for a specific range.
    public function up(): void
    {
        Schema::create('blocked_beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bed_id')->constrained('beds')->cascadeOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bed_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_beds');
    }
};
