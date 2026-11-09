# Milestone demo notes

## M0 — Hosting spike (kit ready, needs the real hosting)

Done in the repository:

- Fresh Laravel 13 + Filament 5 app; staff panel at `/admin` with **mandatory 2FA** (TOTP + recovery codes).
- `tools/hosting-probe.php` — standalone probe to upload before anything else.
- `php artisan app:hosting-check` — post-deploy check incl. cron heartbeat.
- Scheduler heartbeat every minute (proves cron runs `schedule:run`).
- `deploy/htaccess-root-fallback` for hostings where the document root can't point to `public/`.
- `deploy/build-release.sh` — upload-ready ZIP with `vendor/` for hostings without SSH/Composer.
- README: step-by-step checklist + findings table.

**Needs Roman:** run the checklist on Websupport and fill in the findings table (brief §13.1).

## M1 — Domain core (done)

What to look at:

- Rules live in `app/Domain/Ordering/` — read `OrderService` top comment first.
- `./vendor/bin/pest` → 54 tests (cutoffs incl. exact second and DST days, day-before cutoff,
  lock/unlock, closed days, horizon, auto price typing, extra confirmation, extra limit,
  cancellation without re-typing, admin corrections with mandatory reason, price versions +
  snapshot, DB guard against double donated, audit log, real parallel-process race test on MariaDB).
- `php artisan db:seed --class=DemoDataSeeder` → 80 employees, menus for last/this/next week,
  ~680 orders, one closed dinner.

Defaults used until confirmed (see `docs/DECISIONS.md`): Obed cutoff 09:00 same day, Večera 14:00
same day, `max_extra_per_slot = 1`, price version chosen by **meal date**.
