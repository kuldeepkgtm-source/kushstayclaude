<?php

use App\Http\Controllers\Api\IcalController;
use Illuminate\Support\Facades\Route;

// GET /ical/{token}.ics — public calendar feed, fetched by OTAs/calendar apps (not the SPA).
// Kept in web.php (not api.php) since it returns text/calendar, not JSON, and needs no CORS/API middleware.
Route::get('/ical/{token}', [IcalController::class, 'feed'])->where('token', '.*\.ics$');
