<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // The actual multi-tenant boundary: which properties a user may touch, and in what role on
    // each. A user with zero rows here (and is_super_admin=false) can access no property at all —
    // there is deliberately no "default" property. See TenantContext::propertyIdsFor().
    public function up(): void
    {
        Schema::create('property_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->enum('role', ['property_owner', 'property_manager', 'front_desk', 'restaurant_manager', 'staff']);
            $table->timestamps();

            $table->unique(['user_id', 'property_id']);
            $table->index('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_users');
    }
};
