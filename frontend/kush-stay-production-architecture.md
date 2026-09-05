# Kush Stay — Production Architecture Reference

Companion document to `kush-stay-booking-system.jsx`. The artifact is the working prototype; this is the spec for turning it into a real, multi-user, always-on system. Target stack: **PHP 8.2+, Laravel, MySQL/MariaDB, Apache/LiteSpeed, standard shared cPanel hosting** — no Docker, Kubernetes, Redis, Supervisor, or long-running Node process required (all optional/future).

---

## 1. Database schema

All tables use `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` and `created_at`/`updated_at` timestamps unless noted.

| Table | Key columns | Notes |
|---|---|---|
| `users` | `name`, `email`, `password_hash`, `role_id` | Admin/staff accounts |
| `roles` | `name` (admin, staff, viewer) | Role-based permissions |
| `properties` | `name`, `address`, `phone`, `whatsapp_number`, `timezone`, `currency` | One row today; supports multi-property later |
| `rooms` | `property_id`, `name`, `is_ac` (bool) | AC Dormitory, Non-AC Dormitory |
| `room_types` | `room_id`, `name` (dorm/private) | Supports future room types |
| `beds` | `room_id`, `code` (e.g. `AC-U1`), `position` (upper/lower), `active` | The 16 seed rows map 1:1 to today's bed IDs |
| `customers` | `name`, `phone` (unique), `email`, `gender`, `id_type`, `id_number`, `address`, `notes` | |
| `bookings` | `booking_ref` (BK-YYYYMMDD-NNNN), `customer_id`, `source`, `check_in`, `check_out`, `guest_count`, `booking_type` (individual/private), `room_id`, `subtotal`, `discount`, `tax`, `total`, `amount_paid`, `balance`, `payment_status`, `booking_status`, `notes` | |
| `booking_beds` | `booking_id`, `bed_id` | Join table — one row per bed in a booking; a private-room booking has 8 rows |
| `payments` | `booking_id`, `method`, `amount`, `status`, `gateway_reference`, `paid_at` | One booking can have multiple payment attempts/installments |
| `pricing_rules` | `bed_type`, `base_price`, `weekend_surcharge_pct`, `valid_from`, `valid_to` | Supports the weekday/weekend logic in the prototype, extensible to seasonal rules |
| `calendar_sources` | `name`, `type` (ota/ical), `ical_url`, `room_mapping`, `sync_frequency_minutes`, `status`, `last_synced_at` | Powers the OTA & iCal screen |
| `calendar_events` | `calendar_source_id`, `external_uid`, `check_in`, `check_out`, `bed_id`, `raw_summary` | Imported iCal events, before/instead of becoming a full booking row |
| `settings` | `key`, `value` (JSON) | Property-wide config (policies, tax, languages) |
| `audit_logs` | `user_id`, `action`, `entity_type`, `entity_id`, `before`, `after` | Every booking/price/status change, for accountability |

**Key relationships:** `bookings.customer_id → customers.id`; `booking_beds.booking_id → bookings.id`, `booking_beds.bed_id → beds.id`; `beds.room_id → rooms.id`; `payments.booking_id → bookings.id`; `calendar_events.calendar_source_id → calendar_sources.id`.

**The single source of truth rule:** availability is always computed by querying `booking_beds` joined to `bookings` (status ≠ cancelled) for date overlaps — exactly what `isBedFree()` does client-side in the prototype. WhatsApp, the admin panel, OTAs, and iCal imports all write to this same table; none of them keep a separate count.

---

## 2. REST API design (Laravel)

All endpoints return JSON; all mutating endpoints require an authenticated session or API token.

```
POST   /api/availability          → { checkIn, checkOut, guests, acPreference? }
                                     ⇒ { acFree, nacFree, privateAcAvailable, privateNacAvailable, options: [...] }

POST   /api/bookings              → { customer, checkIn, checkOut, bedIds | bookingType:"private"+roomKey, source }
                                     ⇒ 201 { bookingRef, total, balance, status }
GET    /api/bookings/{id}         ⇒ full booking record
PUT    /api/bookings/{id}         → partial update (dates, beds, status, payment)
DELETE /api/bookings/{id}         → cancels (soft — sets booking_status = cancelled), 204

GET    /api/beds                  ⇒ all beds with current status for a given date range (?checkIn=&checkOut=)
GET    /api/rooms                 ⇒ room + bed-count summary

POST   /api/whatsapp/webhook      → Meta webhook payload ⇒ routes to the same availability/booking services
                                     used by the internal admin UI — no parallel logic

GET    /api/ical/{token}.ics      → per-property/room/bed secure calendar feed (see §4)
POST   /api/ical/import           → { calendarSourceId, icsFileOrUrl } ⇒ parses VEVENTs into calendar_events,
                                     then reconciles into bookings where a matching bed is free
```

**Example — `POST /api/bookings`:**
```json
// Request
{ "customer": { "name": "Priya Singh", "phone": "9911223344" },
  "checkIn": "2026-09-25", "checkOut": "2026-09-27",
  "bedIds": ["AC-U1", "AC-U2", "AC-L1"], "source": "whatsapp" }

// Response 201
{ "bookingRef": "BK-20260925-0007", "nights": 2, "total": 1900,
  "balance": 1900, "bookingStatus": "pending", "paymentStatus": "unpaid" }
```
A `409 Conflict` with `{ "error": "bed_unavailable", "bedIds": ["AC-L1"] }` is returned if any requested bed is no longer free — the same overlap check as the prototype's `isBedFree()`, now enforced with a database transaction and row lock so two simultaneous requests can't both win.

---

## 3. WhatsApp Business Cloud API integration

```
Guest ──▶ WhatsApp ──▶ Meta webhook (POST /api/whatsapp/webhook)
                              │
                              ▼
                    Booking/Availability Service  ◄── same service the admin UI and iCal import call
                              │
                              ▼
                          MySQL database
                              │
                              ▼
                    Response text/buttons ──▶ Meta Send API ──▶ WhatsApp
```
The prototype's `processMessage()` dialogue logic (intent extraction, slot-filling, recommendation engine) ports over largely as-is — it becomes a Laravel service class called from the webhook controller instead of from a browser `useState` hook. This is the point of designing it as a pure function of `(message, state, availability)` in the prototype: the same function can run server-side unchanged.

Requirements: a permanent WhatsApp Business Account, Meta app review for message templates outside the 24-hour session window, and a queue worker (Laravel Horizon or the database queue driver — no Redis required) to keep webhook responses fast.

---

## 4. iCal production plan

| Prototype (now) | Production |
|---|---|
| Manual **Export** button generates an `.ics` file for download | `GET /ical/{token}.ics` — a stable, per-property/room/bed URL with a long random token, pullable by any OTA/calendar app |
| Manual **Import** — admin uploads a `.ics` file, parsed instantly in-browser | Server cron (e.g. every 15–30 min via Laravel's scheduler + `cron` on cPanel) fetches each `calendar_sources.ical_url`, diffs new/changed VEVENTs into `calendar_events`, and reconciles into `bookings` |
| No conflict resolution beyond "first free bed wins" | Conflict handling: if an imported event's dates now overlap an existing internal booking, flag it for manual review rather than silently double-booking |

Tokens should be long (32+ random bytes), regenerable from Settings, and never guessable. This is the same "sync frequency depends on the platform, use a server as source of truth" caveat already shown in the prototype's OTA & iCal screen.

---

## 5. cPanel / Laravel deployment checklist

Generic steps — no host-specific commands assumed, per your instruction not to invent them:

1. Create a MySQL database and a dedicated database user in cPanel → MySQL® Databases.
2. Assign the user full privileges on that database only.
3. In cPanel → **Setup PHP App** (or MultiPHP Manager), select PHP 8.2+ for the domain/subdomain.
4. Upload the Laravel application (via Git deploy, SSH + `git clone`, or file upload + `composer install --no-dev`).
5. Copy `.env.example` to `.env` and fill in DB credentials, `APP_URL`, mail, and WhatsApp/payment keys.
6. Run `php artisan key:generate`.
7. Point the domain's document root to the app's `public/` folder.
8. `chmod -R 775 storage bootstrap/cache` (or per host's recommended ownership) so Laravel can write logs/cache.
9. Run `php artisan migrate --force` to create all tables from §1.
10. Run a seeder to insert the 16 beds, 2 rooms, and default pricing (mirrors the prototype's seed data).
11. Set up a cPanel **Cron Job** to run `php artisan schedule:run` every minute (drives iCal sync, hold-expiry cleanup, daily reports).
12. Enable **AutoSSL**/Let's Encrypt for HTTPS.
13. Create a test booking through the UI and confirm it appears correctly in the database.
14. Attempt a deliberate double-booking (two browser tabs, same bed/dates) and confirm the second is rejected — this is the production equivalent of System Test #3.
15. Export and re-import an `.ics` file to confirm the calendar feed round-trips.
16. Configure the backup schedule (cPanel's built-in backups, or `mysqldump` via cron to off-site storage).

---

## 6. Cron / scheduled jobs

Run server-side via Laravel's scheduler (`php artisan schedule:run` triggered by one cPanel cron entry):

- **iCal sync** — pull each active `calendar_source` on its configured frequency.
- **Expire stale holds** — release any bed held (payment not completed) past its 10-minute window; the prototype does this client-side with a `setInterval`, production needs it server-side since no browser tab is guaranteed open.
- **Booking reminders** — SMS/WhatsApp the day before check-in.
- **Daily report** — email/Slack a summary of occupancy, revenue, and pending payments.
- **Database backup** — nightly dump to off-site storage.

## 7. Security requirements

- HTTPS everywhere (no plaintext admin panel).
- `password_hash()`/bcrypt for all user passwords — never the prototype's hardcoded `admin`/`admin123`.
- Laravel's built-in CSRF protection on every form.
- Parameterized queries / Eloquent ORM everywhere (no raw SQL string concatenation) to prevent injection.
- Output escaping (Blade auto-escapes by default) to prevent XSS.
- Server-side validation on every field the prototype validates client-side, since client checks are only a UX convenience.
- Role-based authorization (admin vs. staff vs. read-only) instead of a single shared login.
- Secure, `httpOnly`, `Secure` session cookies.
- Rate limiting on the WhatsApp webhook and login endpoint.
- `audit_logs` for every booking, price, and status change.
- Long, random, regenerable iCal tokens (never sequential IDs).
- Webhook signature verification for the WhatsApp Cloud API payload.
- API authentication (Laravel Sanctum) for any external integration.
- Database backups encrypted at rest; secrets kept in `.env`, never committed to Git or exposed to frontend JavaScript.

---

## 8. What's real vs. simulated today

| Capability | In the prototype | In production |
|---|---|---|
| Bed-level availability, private-room rule, no double-booking, pricing (incl. weekend surcharge), validation | **Real** — computed live from actual data | Same logic, server-enforced with DB transactions |
| Data persistence across reloads | **Real** — uses the platform's persistent artifact storage in place of `localStorage` (disabled for browser artifacts) | Real MySQL database |
| iCal export/import | **Real** — genuine `.ics` generation and VEVENT parsing, run in your browser | Same logic, plus a scheduled server-side pull |
| WhatsApp conversation | Simulated in a browser chat UI — same dialogue/recommendation logic, not connected to a real WhatsApp number | Real webhook via WhatsApp Business Cloud API |
| OTA channel sync (Booking.com/Airbnb/etc.) | Simulated — randomly generates plausible bookings to demonstrate the central-inventory effect | Real OTA API or iCal polling |
| Payments | Simulated success/fail | Real gateway (e.g. Razorpay) |
| Admin login | Demo-only hardcoded credentials | Laravel auth with hashed passwords, sessions, roles |
