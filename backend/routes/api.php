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

// Admin-only — Sanctum token required (matches production-mode calls from the React Settings/
// Bookings/Beds/etc. screens; prototype mode never calls these, it uses window.storage)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
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
