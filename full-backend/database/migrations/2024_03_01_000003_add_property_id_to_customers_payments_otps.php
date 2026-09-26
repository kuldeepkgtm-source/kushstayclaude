<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Denormalizing property_id onto these three tables — rather than requiring a join through
    // bookings/passes every time — is what makes "never trust a client-supplied property_id,
    // always check against the resource's own property" a cheap, direct query instead of an
    // expensive one. See TenantContext and the *Policy classes.
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('id')->constrained('properties')->cascadeOnDelete();
        });
        // A phone number is unique per-property now, not globally — the same guest can exist as
        // separate customer records at two different properties on the platform (see spec §69's
        // "avoid global uniqueness where it should be property-scoped").
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->unique(['property_id', 'phone']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('id')->constrained('properties')->cascadeOnDelete();
        });

        Schema::table('pass_otp_verifications', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('id')->constrained('properties')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['property_id', 'phone']);
            $table->dropConstrainedForeignId('property_id');
            $table->unique('phone');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
        Schema::table('pass_otp_verifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
    }
};
