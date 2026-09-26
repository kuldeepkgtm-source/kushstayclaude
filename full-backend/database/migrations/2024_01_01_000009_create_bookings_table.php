<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_ref', 24)->unique(); // BK-YYYYMMDD-NNNN, same format as the prototype
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name'); // denormalized snapshot at booking time
            $table->string('customer_phone', 20)->nullable();
            $table->enum('source', [
                'WhatsApp AI', 'Direct', 'Website', 'Walk-in', 'Phone',
                'Booking.com', 'Airbnb', 'MakeMyTrip', 'Goibibo', 'Other OTA',
                'iCal Import', 'Manual/Admin', 'Pass',
            ]);
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedTinyInteger('guest_count');
            $table->enum('booking_type', ['individual', 'private']);
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->decimal('balance', 10, 2)->default(0);
            $table->enum('payment_status', ['Unpaid', 'Partially Paid', 'Paid', 'Refunded'])->default('Unpaid');
            $table->string('payment_method', 40)->nullable();
            $table->enum('booking_status', ['Pending', 'Confirmed', 'Checked-in', 'Checked-out', 'Cancelled', 'No-show'])->default('Pending');
            $table->string('external_booking_id')->nullable();
            $table->text('special_request')->nullable();
            $table->timestamps();

            $table->index(['check_in', 'check_out']);
            $table->index('booking_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
