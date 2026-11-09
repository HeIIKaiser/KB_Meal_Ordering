# Milestone demo notes

## M0 — Hosting spike (verified 2026-11-09 on kbsandbox.arpo.cc)

Done in the repository:

- Fresh Laravel 13 + Filament 5 app; staff panel at `/admin` (2FA optional per user since M2 decision; TOTP + recovery codes).
- `tools/hosting-probe.php` — standalone probe to upload before anything else.
- `php artisan app:hosting-check` — post-deploy check incl. cron heartbeat.
- Scheduler heartbeat every minute (proves cron runs `schedule:run`).
- `deploy/htaccess-root-fallback` for hostings where the document root can't point to `public/`.
- `deploy/build-release.sh` — upload-ready ZIP with `vendor/` for hostings without SSH/Composer.
- README: step-by-step checklist + findings table.

Roman verified everything on the test hosting; findings are in the README. Added `deploy/websupport-cron.php` (WebAdmin cron can only run a PHP file). Staff 2FA made optional (Roman's decision).

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

## M2 — Employee app (done)

Try it: `php artisan db:seed --class=DemoDataSeeder`, open `/`, log in with `1001` / PIN `1234`.

- **Login** `/prihlasenie`: osobné číslo + PIN, big inputs with numeric keyboard; stays logged in for 400 days
  (HttpOnly cookie). 5 wrong attempts → 60 s lockout with a Slovak message.
- **Objednávky** `/`: week strip (Po–Ne, today highlighted, dot = something ordered), ‹ › for weeks,
  swipe left/right between days on phones. One card per slot with its status (*otvorené do 09:00*,
  *uzamknuté*, *zatvorené – poznámka*, *menu zatiaľ nie je*). **Tap = order** (Darované 1,50 €);
  tap again → "Zrušiť objednávku?"; tap another option → "Zmeniť objednávku?" with
  *Zmeniť na Menu 2* / *Pridať extra za 6,00 €* / *Späť*. Every action shows a Slovak message.
  Desktop shows the whole week.
- **Môj mesiac** `/moj-mesiac`: orders of the month per day, count, Darované/Extra subtotals and € total;
  previous/next month.
- **Účet** `/ucet`: name and osobné číslo, PIN change, "add to home screen" instructions, logout.
- **PWA**: manifest, icons incl. apple-touch-icon, standalone display, minimal service worker with an
  offline page (no offline ordering).
- Checked in Chromium at 360 px and 1400 px; 85 tests green (SQLite + MariaDB).

Please try on a few real phones (old Android, iPhone): install to home screen, order, cancel, switch, extra.

## M3 — Admin (done)

Try it with demo data: `/admin`, `admin@jedalen.test` / `heslo1234` (or `kuchyna@…`, `mzdy@…` to see the role limits).
The Slovak manual for staff is in `docs/navod.md`.

- **Zamestnanci:** list with search/filters, create/edit (deactivate instead of delete), **Reset PIN** and
  bulk **Nové PINy + prístupové lístky** → printable A4 page, 8 slips per page with name, osobné číslo, PIN,
  QR code and "add to home screen" steps. PINs are shown only once.
- **Import zamestnancov** (XLSX/CSV, template download): preview of new/changed/unchanged rows with the exact
  changes, per-row errors, optional deactivation of people missing in the file.
- **Ceny:** current prices on top, new version with *Platí od*; versions in effect are read-only.
- **Jedlá a uzávierky** (cutoff per slot), **Objednávanie** settings (horizon, max extra), **Zatvorené dni**.
- **Jedálny lístok:** weekly grid (days × Obed/Večera × options, allergens), copy from the previous week,
  warning with the list of affected employees when an ordered dish is renamed/removed.
- **Import lístka** (template `datum, jedlo, cislo_menu, nazov, alergeny`), preview + per-row errors.
- **Objednávky:** filterable list for all staff; admin: *Objednať za zamestnanca* (price shown before saving),
  *Opraviť → Zmeniť jedlo / Zmeniť typ ceny / Zrušiť*, reason mandatory after cutoff.
- **Používatelia správy:** roles, password, switch off a lost 2FA. **História zmien:** audit log with reasons.
- 142 tests green (SQLite + MariaDB). Checked in Chromium: menu grid, import preview, orders, print-to-PDF of slips.

**Still needed from Roman:** a sample of the kitchen's real weekly menu file (for its own import adapter).
