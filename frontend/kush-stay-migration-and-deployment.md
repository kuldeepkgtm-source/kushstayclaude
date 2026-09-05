# Kush Stay — Migration & Deployment

Companion to `kush-stay-laravel-backend.zip` and `kush-stay-production-architecture.md`. This doc covers two things: moving the prototype's existing demo data into MySQL, and putting the Laravel app on shared cPanel hosting. Anything specific to your HostyCare account that can't be known generically is marked **CHECK IN HOSTYCARE CPANEL** rather than guessed.

## 1. Migrating data out of the Artifact

1. **Export.** In the running artifact, open **Demo & Tests → Export data (JSON)**. This downloads everything currently in the browser's persistent storage — bookings, leads, OTA connections, prices, settings.
2. **Stand up the database.** Run `php artisan migrate --seed` on the Laravel app first (§3 below) — this creates the 16 beds/2 rooms/base pricing the export's `bedIds`/`roomKey` values reference. The importer matches by bed *code* (e.g. `AC-U1`), not by database ID, so seeding order doesn't matter.
3. **Dry run.** `php artisan import:artifact-json export.json --dry-run` — prints exactly what would be created/skipped without writing anything.
4. **Real import.** Drop `--dry-run`. The command:
   - Skips any `booking_ref` that already exists in MySQL (safe to re-run without duplicating).
   - Skips a row if none of its bed codes match a real bed (reports it instead of guessing).
   - Wraps the whole run in one DB transaction — a mid-import failure rolls back everything, not just the failed row.
5. **Validate.** Compare counts: the command's final summary line (`Imported: N, duplicates skipped: N, invalid skipped: N`) should account for every row in the export's `bookings` array. Spot-check a few bookings in the admin UI against the original artifact.

No booking data is deleted or overwritten by this process — worst case, a row is skipped and reported, never silently dropped.

## 2. What "CHECK IN HOSTYCARE CPANEL" means below

Generic cPanel/Laravel steps are safe to follow anywhere. A few specifics vary by host (exact PHP-selector UI, whether Git deploy is available, default document-root layout, backup tooling). Those are flagged inline so you don't get a step that silently does the wrong thing on your account.

## 3. Deployment steps

1. **PHP version** — cPanel → *Select PHP Version* / *MultiPHP Manager*, choose **8.2 or newer** for the domain. **CHECK IN HOSTYCARE CPANEL** for the exact menu name and which 8.2.x/8.3.x builds are offered.
2. **Database** — cPanel → *MySQL® Databases*: create a database (e.g. `kushstay`), create a user, add the user to the database with **All Privileges**. Note the final generated names — most hosts prefix both with your account username (`user_kushstay`, `user_dbuser`). **CHECK IN HOSTYCARE CPANEL** for the exact prefix format.
3. **Upload the app.** Either:
   - Git: cPanel → *Git™ Version Control* → clone your repo, then SSH in for `composer install` — **CHECK IN HOSTYCARE CPANEL** whether Git deploy and SSH access are enabled on your plan; or
   - Upload a zip of the app (built with `composer install --no-dev --optimize-autoloader` done locally first, since Composer may not be runnable on the host) via *File Manager* or FTP, then extract.
4. **.env** — copy `.env.example` to `.env`, fill in the `DB_*` values from step 2, `APP_URL` to your real domain, and `SANCTUM_STATEFUL_DOMAINS`/`FRONTEND_URL` to wherever the React frontend is hosted.
5. **APP_KEY** — run `php artisan key:generate` (via SSH, or cPanel's *Terminal* feature if SSH isn't available — **CHECK IN HOSTYCARE CPANEL**).
6. **Document root** — point the domain/subdomain to the app's `public/` folder specifically, not the app root (cPanel → *Domains*, edit the document root). Getting this wrong exposes `.env` and the whole codebase publicly.
7. **Storage permissions** — `chmod -R 775 storage bootstrap/cache`, owned by the account's web-server user. **CHECK IN HOSTYCARE CPANEL** if that user has a non-standard name (some hosts run PHP-FPM as a per-account user rather than `www-data`).
8. **Migrate + seed** — `php artisan migrate --seed` (creates all 17 tables and the demo inventory/pricing from `KushStaySeeder`).
9. **Set the admin password** — the seeder deliberately leaves a random unusable password; set a real one via `php artisan tinker` (see the backend README) before anyone tries to log in.
10. **Cron** — cPanel → *Cron Jobs*, add exactly one line:
    ```
    * * * * * php /home/USERNAME/path-to-app/artisan schedule:run >> /dev/null 2>&1
    ```
    This single entry drives both `ical:sync` (every 15 min) and `holds:purge` (every minute) via `routes/console.php` — no separate cron line per task. **CHECK IN HOSTYCARE CPANEL** for the correct absolute path format.
11. **HTTPS** — cPanel → *SSL/TLS Status*, run AutoSSL (or install a purchased certificate). Confirm the site loads on `https://` before going further.
12. **Test a booking** — create one through the API (or once the frontend is pointed at it) and confirm it appears in `bookings`.
13. **Test double-booking prevention** — attempt the same bed/dates twice; the second must return `409`. This is `tests/Feature/DoubleBookingAndConcurrencyTest.php`, run for real against this database.
14. **Test iCal** — hit `GET /ical/{a-real-export_token}.ics` and confirm a calendar app can subscribe to it; run `php artisan ical:sync` manually once and check `calendar_events`.
15. **Logs** — confirm `storage/logs/laravel.log` is writable and check it after the above tests for anything unexpected.
16. **Backups** — cPanel's built-in *Backup Wizard* covers full-account backups; for database-only backups on a schedule, add a second cron line running `mysqldump` to off-site storage. **CHECK IN HOSTYCARE CPANEL** for retention limits on built-in backups and whether off-site (e.g. S3) backup destinations are reachable from a cron job on your plan.
17. **Rollback plan** — before running `migrate` against production data in the future, always run `php artisan migrate --pretend` first to preview the SQL, and keep a fresh `mysqldump` taken immediately before any migration that isn't purely additive.

## 4. Pointing the frontend at this backend

In the artifact, open **Settings → Data source**, switch to **production**, and set the API base URL to your deployed domain (e.g. `https://api.yourdomain.example`). As documented in the gap analysis, this currently wires the WhatsApp booking-creation path through the real API; everything else keeps using local browser storage until the remaining call sites get the same one-line swap.
