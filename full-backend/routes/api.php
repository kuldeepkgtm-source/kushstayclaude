<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BedController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\IcalController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Api\Pass\PassAdminController;
use App\Http\Controllers\Api\Pass\PassBookingController;
use App\Http\Controllers\Api\Pass\PassOtpController;
use App\Http\Controllers\Api\Pass\PassProductController;
use App\Http\Controllers\Api\Pass\PassPurchaseController;
use Illuminate\Support\Facades\Route;

// Public — no auth: guest-facing availability/booking (mirrors the prototype's open WhatsApp AI tab)
Route::get('/availability', AvailabilityController::class);
Route::post('/bookings', [BookingController::class, 'store']);
Route::get('/bookings/{booking}', [BookingController::class, 'show']);
Route::get('/beds', [BedController::class, 'index']);
Route::get('/rooms', [RoomController::class, 'index']);
Route::post('/holds', [HoldController::class, 'store']);
Route::delete('/holds/{hold}', [HoldController::class, 'destroy']);

// WhatsApp Cloud API webhook — Meta calls these directly, no session auth
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive']);

// iCal import (manual/admin-triggered) — auth-gated below; the public .ics feed itself lives
// outside /api in routes/web.php since it's fetched by external calendar apps, not the SPA.
Route::post('/login', [AuthController::class, 'login']);

// ---------------- 30-Day Pass system ----------------
// Public: OTP + product catalog (no auth — anyone can look up prices or start a purchase)
Route::post('/pass/otp/request', [\App\Http\Controllers\Api\Pass\PassOtpController::class, 'request']);
Route::post('/pass/otp/verify', [\App\Http\Controllers\Api\Pass\PassOtpController::class, 'verify']);
Route::get('/pass/products', [\App\Http\Controllers\Api\Pass\PassProductController::class, 'index']);
Route::get('/pass/upgrade-rates', [\App\Http\Controllers\Api\Pass\PassProductController::class, 'upgradeRates']);
Route::post('/pass/purchase/reserve', [\App\Http\Controllers\Api\Pass\PassPurchaseController::class, 'reserve']);
Route::post('/pass/purchase/{pass}/confirm-payment', [\App\Http\Controllers\Api\Pass\PassPurchaseController::class, 'confirmPayment']);
Route::post('/pass/login', [\App\Http\Controllers\Api\Pass\PassAuthController::class, 'login']);

// Customer "My Pass" — gated by a pass-scoped bearer token (NOT Sanctum/admin auth), so a
// customer can only ever reach their OWN pass. See EnsurePassToken.
Route::middleware('pass.token')->prefix('pass')->group(function () {
    Route::get('/me', [\App\Http\Controllers\Api\Pass\PassCustomerController::class, 'me']);
    Route::get('/me/ledger', [\App\Http\Controllers\Api\Pass\PassCustomerController::class, 'ledger']);
    Route::get('/me/bookings', [\App\Http\Controllers\Api\Pass\PassCustomerController::class, 'bookings']);
    Route::get('/availability', [\App\Http\Controllers\Api\Pass\PassBookingController::class, 'availability']);
    Route::post('/book', [\App\Http\Controllers\Api\Pass\PassBookingController::class, 'book']);
    Route::post('/bookings/{passBooking}/confirm-upgrade-payment', [\App\Http\Controllers\Api\Pass\PassBookingController::class, 'confirmUpgradePayment']);
    Route::post('/bookings/{passBooking}/cancel', [\App\Http\Controllers\Api\Pass\PassBookingController::class, 'cancel']);
});

// Admin-only — Sanctum token required (matches production-mode calls from the React Settings/
// Bookings/Beds/etc. screens; prototype mode never calls these, it uses window.storage)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Property lifecycle (owner-facing) — policy checks happen inside each controller method
    Route::get('/properties', [PropertyController::class, 'index']);
    Route::post('/properties', [PropertyController::class, 'store']);
    Route::get('/properties/{property}', [PropertyController::class, 'show']);
    Route::put('/properties/{property}', [PropertyController::class, 'update']);
    Route::post('/properties/{property}/submit', [PropertyController::class, 'submit']);
    Route::post('/properties/{property}/activate', [PropertyController::class, 'activate']);

    Route::get('/properties/{property}/payment-config', [PropertyPaymentConfigController::class, 'show']);
    Route::post('/properties/{property}/payment-config', [PropertyPaymentConfigController::class, 'store']);
    Route::post('/properties/{property}/payment-config/change-request', [PropertyPaymentConfigController::class, 'requestChange']);

    Route::get('/properties/{property}/whatsapp-config', [PropertyWhatsAppConfigController::class, 'show']);
    Route::put('/properties/{property}/whatsapp-config', [PropertyWhatsAppConfigController::class, 'update']);
    Route::post('/properties/{property}/whatsapp-config/test', [PropertyWhatsAppConfigController::class, 'testConnection']);

    // Kush Stay Super Admin only
    Route::middleware('super_admin')->prefix('admin')->group(function () {
        Route::get('/properties', [SuperAdminPropertyController::class, 'index']);
        Route::get('/properties/{property}', [SuperAdminPropertyController::class, 'show']);
        Route::post('/properties/{property}/review', [SuperAdminPropertyController::class, 'moveToReview']);
        Route::post('/properties/{property}/approve', [SuperAdminPropertyController::class, 'approve']);
        Route::post('/properties/{property}/reject', [SuperAdminPropertyController::class, 'reject']);
        Route::post('/properties/{property}/request-changes', [SuperAdminPropertyController::class, 'requestChanges']);
        Route::post('/properties/{property}/suspend', [SuperAdminPropertyController::class, 'suspend']);
        Route::post('/properties/{property}/reactivate', [SuperAdminPropertyController::class, 'reactivate']);

        Route::get('/payment-change-requests', [SuperAdminPaymentConfigController::class, 'index']);
        Route::post('/payment-change-requests/{approvalRequest}/approve', [SuperAdminPaymentConfigController::class, 'approve']);
        Route::post('/payment-change-requests/{approvalRequest}/reject', [SuperAdminPaymentConfigController::class, 'reject']);

        Route::get('/passes', [\App\Http\Controllers\Api\Pass\PassAdminController::class, 'index']);
        Route::get('/passes/stats', [\App\Http\Controllers\Api\Pass\PassAdminController::class, 'stats']);
        Route::get('/passes/{pass}', [\App\Http\Controllers\Api\Pass\PassAdminController::class, 'show']);
        Route::get('/passes/{pass}/ledger', [\App\Http\Controllers\Api\Pass\PassAdminController::class, 'ledger']);
        Route::post('/passes/{pass}/suspend', [\App\Http\Controllers\Api\Pass\PassAdminController::class, 'suspend']);
        Route::post('/passes/{pass}/reactivate', [\App\Http\Controllers\Api\Pass\PassAdminController::class, 'reactivate']);
        Route::post('/passes/{pass}/cancel', [\App\Http\Controllers\Api\Pass\PassAdminController::class, 'cancel']);
        Route::post('/passes/{pass}/extend', [\App\Http\Controllers\Api\Pass\PassAdminController::class, 'extend']);
        Route::post('/passes/{pass}/adjust', [\App\Http\Controllers\Api\Pass\PassAdminController::class, 'adjust']);
    });
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::put('/bookings/{booking}', [BookingController::class, 'update']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::post('/bookings/{booking}/check-in', [BookingController::class, 'checkIn']);
    Route::post('/bookings/{booking}/check-out', [BookingController::class, 'checkOut']);

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);

    Route::post('/ical/sources', [IcalController::class, 'storeSource']);
    Route::post('/ical/import', [IcalController::class, 'import']);
});
