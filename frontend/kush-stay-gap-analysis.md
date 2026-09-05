# Kush Stay — Gap Analysis (Artifact → Production)

Baseline: `kush-stay-booking-system.jsx` as it exists right now in this conversation (2,109 lines). No new file was uploaded this turn, so this analysis is against that real, current source — not a description of it.

## What's already correct and gets reused as-is

| Area | Current implementation | Verdict |
|---|---|---|
| Inventory model | 16 fixed bed IDs (`AC-U1..4`, `AC-L1..4`, `NAC-U1..4`, `NAC-L1..4`) generated from a `ROOMS` map | Logic is right; production just needs these as DB rows instead of a JS constant |
| Overlap rule | `overlap(aS,aE,bS,bE) = aS<bE && bS<aE`, checkout night excluded | Exactly the rule you specified — ports 1:1 into SQL (`WHERE check_in < ? AND check_out > ?`) |
| Private-room rule | `isPrivateAvailable()` requires all 8 beds of a room free for the full range | Reuse the algorithm verbatim in `AvailabilityService` |
| Pricing | Per-bed-type price + a flat weekend-night surcharge %, computed night-by-night (`getNightlyBedRate`) | Weekday/weekend logic is real and reusable; seasonal/special-date pricing is **not yet implemented** anywhere (prototype or backend) — noted as a gap below |
| Booking/payment statuses | `BOOKING_STATUSES = [Pending, Confirmed, Checked-in, Checked-out, Cancelled, No-show]`, `PAYMENT_STATUSES = [Unpaid, Partially Paid, Paid, Refunded]` | Matches your spec; used as-is for the `bookings` table enums |
| Booking ID | `BK-YYYYMMDD-NNNN`, sequence counted from same-day bookings in the array | Same format, reused; production computes the sequence with a DB query instead of an array scan |
| Booking sources | `WhatsApp AI, Direct, Website, Walk-in, Phone, Booking.com, Airbnb, MakeMyTrip, Goibibo, Other OTA, iCal Import, Manual/Admin` | Preserved verbatim as the `bookings.source` enum |
| WhatsApp dialogue engine | `processMessage()` — a pure function of `(text, {slots, stage, bookings, holds, prices, ...})` → `{newMessages, slotsPatch, stagePatch, sideEffects}` | This was deliberately written with no React/browser dependency, specifically so it can be lifted into a PHP service almost line-for-line. That design pays off now. |
| iCal generate/parse | `generateICS()` / `parseICS()`, regex-based VEVENT extraction | Logic reusable; production needs it re-implemented in PHP (can't `require` JS from Laravel), same algorithm |
| Hold concept | `holds` array with `expiresAt`, checked in `isBedFree`, expired via a client `setInterval` | Correct concept, **wrong trust boundary** — see Critical Gaps |
| Persistence | `window.storage.get/set("appState", ...)` — one JSON blob, debounce-saved | Explicitly prototype-only, as you already flagged |
| Admin gate | Hardcoded `admin`/`admin123` check in React state | Explicitly demo-only, as you already flagged |

## Critical gaps (must change for production, not just "nice to have")

1. **No server-side enforcement anywhere.** Every availability check, hold, and booking write happens in the browser's React state. Two people using two browser tabs against the same `window.storage` don't even see each other's data — `window.storage` here is *personal per-user* storage, not a shared database. There is currently no mechanism by which two real customers could conflict, which also means there's currently no real double-booking *protection* — there's just an absence of the scenario in a single-user prototype. This is the single most important gap.
2. **Time trust.** Hold expiry (`expiresAt > Date.now() + offsetMs`) is computed and checked entirely client-side. A modified or frozen browser clock can hold beds indefinitely. Production must expire holds using the database server's clock.
3. **Auth.** `admin`/`admin123` is a string comparison in a `useState`, visible in the bundled JS. Fine as a labeled demo; cannot gate anything real.
4. **Seasonal/special-date pricing** — requested in both prior specs and still not implemented in either the prototype or (until now) the backend. Weekday/weekend surcharge is the only date-sensitive rule that exists.
5. **iCal dedup.** The prototype's importer creates a new booking per import run with no memory of previously-seen `UID`s — re-importing the same `.ics` file twice would double-book. Production `calendar_events` needs a unique constraint on `(calendar_source_id, external_uid)`.

## What this delivery adds

- A hand-written Laravel skeleton (`kush-stay-laravel-backend.zip`) implementing every item above as real PHP: migrations for all 17 tables, `AvailabilityService`/`BookingService` with a transaction + row-lock double-booking guard, a `holds` table with server-computed `expires_at`, Sanctum-based auth replacing the hardcoded login, `PricingService` extended with `pricing_rules` (weekday/weekend/seasonal/special-date/discount/tax), `IcalService` with UID-based dedup, the `whatsapp/webhook` controller calling the *same* services the REST API and admin UI use, an `ical:sync` Artisan command, an artifact-JSON import command, and Feature tests for all 14 cases you listed (including the exact 05→07 Sep checkout-date case, asserted, not just described).
- The smallest-practical-step frontend change: `kush-stay-booking-system.jsx` keeps every existing screen, component, and prototype behavior untouched, and gains one new `dataMode` setting (`prototype` default / `production`) plus a real `apiClient` module. In production mode, availability lookups and booking creation call the Laravel API above instead of local state; everything else (admin CRUD screens, OTA simulation, demo controls) still runs in prototype mode today and is flagged in code comments as the next mechanical wiring step — deliberately not rewritten wholesale in this pass, per your instruction.
- `kush-stay-migration-and-deployment.md` — the Artifact→MySQL migration procedure and generic cPanel steps, with anything HostyCare-specific explicitly marked `CHECK IN HOSTYCARE CPANEL` rather than invented.

## Honesty note on this environment

This sandbox can run Node (used to unit-test the JS engine earlier) and now PHP 8.3 for syntax linting (`php -l`, run against every file below), but has no network access to Packagist/Composer, so the Laravel *framework itself* can't be installed or booted here — the PHP files are written correctly by hand against Laravel 11 conventions and syntax-checked, but not executed against a real Laravel bootstrap or MySQL instance the way the JS engine was actually run and tested earlier in this conversation. The README in the zip has the exact commands to run them for real once `composer install` is possible.
