# Kush Stay Booking API (Laravel)

Production backend for the existing `kush-stay-booking-system.jsx` frontend. This skeleton was
hand-written and syntax-checked (`php -l`, PHP 8.3) file by file, but **not** run against a real
Laravel bootstrap or MySQL here — the sandbox that built it has no network access to Packagist, so
`composer install` couldn't be executed in this environment. Do that on your own machine or the
cPanel host first. Everything below is standard Laravel 11 + Sanctum; nothing exotic.

## Install

```bash
composer create-project laravel/laravel:^11.0 kush-stay-api-tmp   # get a real skeleton with bootstrap files
# then copy this repo's app/, database/, routes/, config/, tests/, composer.json over it
cd kush-stay-api-tmp
composer install
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
cp .env.example .env
php artisan key:generate
# fill in DB_* and (optionally) WHATSAPP_* in .env
php artisan migrate --seed        # runs all 17 migrations + KushStaySeeder
php artisan test                  # runs the 14 test cases in tests/Feature, incl. the exact
                                   # 05->07 Sep checkout-date case
php artisan serve                 # local dev server
```

To set the seeded admin's password (the seeder deliberately does not print one):
```bash
php artisan tinker
>>> $u = App\Models\User::first(); $u->password = Hash::make('choose-a-real-password'); $u->save();
```

## What's here

| Path | Purpose |
|---|---|
| `database/migrations/` | All 17 tables from the spec — `roles, users(+sanctum tokens), properties, room_types, rooms, beds, blocked_beds, customers, bookings, booking_beds, payments, holds, pricing_rules, calendar_sources, calendar_events, settings, audit_logs` |
| `app/Models/` | One Eloquent model per table, with the relationships between them |
| `app/Services/AvailabilityService.php` | The overlap rule + private-room rule — read this first, it's the whole point |
| `app/Services/BookingService.php` | Transaction + `lockForUpdate()` double-booking protection; create/cancel/check-in/check-out/reschedule |
| `app/Services/PricingService.php` | Reads `pricing_rules` (base/weekend/seasonal/special_date) instead of a hard-coded price map |
| `app/Services/HoldService.php` | Server-timed 10-minute holds (`now()`, never request input) |
| `app/Services/IcalService.php` | PHP port of the prototype's `generateICS()`/`parseICS()`, plus UID-based dedup on import |
| `app/Http/Controllers/Api/` | One controller per resource; `routes/api.php` wires them up |
| `app/Console/Commands/IcalSync.php` | `php artisan ical:sync` — the real replacement for the prototype's manual "Sync Now" button |
| `app/Console/Commands/ImportArtifactJson.php` | `php artisan import:artifact-json export.json --dry-run` — migrates a prototype JSON export into MySQL, skipping duplicates by `booking_ref` |
| `routes/console.php` | The scheduler config — the *only* cPanel cron line needed is `* * * * * php artisan schedule:run` |
| `tests/Feature/` | 14 test cases, including the exact critical checkout-date scenario, run end-to-end through both the services and the real HTTP routes |

## What's intentionally not fully built yet (see the gap analysis / architecture docs)

- **WhatsApp dialogue parity.** `WhatsAppWebhookController` has real Meta verification and a working availability-query slice, but the full Hindi/Hinglish/English slot-filling conversation from the prototype's `processMessage()` is not ported line-by-line yet — it's a mechanical but sizable follow-up, deliberately called out rather than silently skipped.
- **Real payment gateway.** `POST /api/payments` records a payment result (matches the prototype's admin "mark paid" action); it does not itself call Razorpay/Stripe/etc. — that's a signed-webhook controller you add alongside it.
- **Seasonal/special-date pricing UI.** The `pricing_rules` table and `PricingService` resolution logic support `seasonal`/`special_date` rows; there's no admin screen to create them yet (today they'd be inserted directly or via a small future controller).
