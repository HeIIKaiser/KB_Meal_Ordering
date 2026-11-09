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
  *uzamknuté*, *uzavreté – poznámka*, *menu zatiaľ nie je*). **Tap = order** (Darované 1,50 €);
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
- **Jedlá a uzávierky** (cutoff per slot), **Objednávanie** settings (horizon, max extra), **Uzavreté dni**.
- **Jedálny lístok:** weekly grid (days × Obed/Večera × options, allergens), copy from the previous week,
  warning with the list of affected employees when an ordered dish is renamed/removed.
- **Import lístka** (template `datum, jedlo, cislo_menu, nazov, alergeny`), preview + per-row errors.
- **Objednávky:** filterable list for all staff; admin: *Objednať za zamestnanca* (price shown before saving),
  *Opraviť → Zmeniť jedlo / Zmeniť typ ceny / Zrušiť*, reason mandatory after cutoff.
- **Správcovia:** roles, password, switch off a lost 2FA. **História zmien:** audit log with reasons.
- 142 tests green (SQLite + MariaDB). Checked in Chromium: menu grid, import preview, orders, print-to-PDF of slips.

**Still needed from Roman:** a sample of the kitchen's real weekly menu file (for its own import adapter).

## M4 — Kitchen (done)

Try it: log in as `kuchyna@jedalen.test` / `heslo1234` → **Nástenka** shows today's and tomorrow's counts →
click one, or **Objednávky → Denný prehľad (kuchyňa)**.

- Day/slot picker, status (open until / after cutoff / locked by whom and when / closed), big totals per
  menu option incl. extras, alphabetical list (name, osobné číslo, stredisko, Menu n: dish, Extra mark, note),
  instant name filter without diacritics, auto-refresh every minute.
- **Tlačiť:** print page opens the print dialog — A4, two columns, checkbox per plate, header with date, slot and
  print time, totals at the bottom; 52 demo orders fit on one page (checked in Chromium print and dompdf).
- **PDF** (dompdf) and **Excel** (`kuchyna_2026-11-10_obed.xlsx`, with osobné číslo) with the same content.
- **Uzamknúť / Odomknúť** right on the page (kitchen, admin), audited.
- 158 tests green (SQLite + MariaDB), incl. a constant-query check for the list.

Kitchen section added to `docs/navod.md`. Please try printing on the kitchen's printer.

## M5 — Exports (done)

Try it: log in as `mzdy@jedalen.test` / `heslo1234` → **Objednávky → Exporty**.

- **Mesačný prehľad** → `stravovanie_2026-11_mesacny.xlsx`
  - sheet *Mesiac*: per employee a block with day-of-week row, Darované / Extra counts per day (or split
    obed / večera), Spolu and Suma €; weekends shaded; frozen name/header; prints on A4 landscape;
  - sheet *Súhrn*: one row per employee (osobné číslo, priezvisko, meno, stredisko, ks and € per type, suma) + totals —
    the sheet for the payroll import.
- **Zoznam objednávok** for any period, XLSX or CSV → `stravovanie_2026-11_zoznam.csv` (one row per order incl.
  cancelled, all columns from the brief).
- Every export is logged in *História zmien*.
- 168 tests green (SQLite + MariaDB): day counts (lunch + dinner = 2), extra, cancelled excluded, month
  boundaries, snapshot prices after repricing, split rows, summary totals, CSV/XLSX content, permissions.
  Checked the XLSX rendered in LibreOffice and downloads in Chromium.

**Needs Roman:** if payroll must import into the existing attendance/meal workbook, send a sample of its input sheet —
the layout lives in one class (`MonthlyMatrixXlsx`) and can be matched exactly.

### Follow-up after M5 (Roman's answers, 2026-11-09)

- Extra meals are unlimited by default (6 € each); a cap can still be set in *Nastavenia → Objednávanie* (empty = unlimited).
- Cutoff defaults kept (09:00 / 14:00), adjustable in the admin.
- Disclaimer „Cena stravy je odhadom a po porovnaní s dochádzkou sa môže zmeniť.“ on the employee's *Môj mesiac*,
  in the monthly export (both sheets) and on the export page.
- Concurrency test now covers both unlimited extras and a cap. 170 tests green (SQLite + MariaDB).

### Follow-up: soup of the day and the kitchen's weekly Excel (2026-11-09)

- Lunch = soup (shown to everyone, not ordered) + Menu 1 + Menu 2; dinner = 1 dish. Soup is on the employee card,
  kitchen screen/print/PDF/Excel and in the weekly grid (row **P**); slots get a *Má polievku dňa* switch.
- Menu import accepts the kitchen's **one-row-per-day** Excel `datum | polievka | obed_1 | obed_2 | vecera`
  with allergens in brackets after the dish; the downloadable template uses this format (round-trip tested).
- Demo data follows the real shape. 185 tests green (SQLite + MariaDB).
- Deploy: `git pull` → `php artisan migrate --force` (new columns) → `php artisan optimize`.

## M6 — Hardening & handover (done)

- **Security review** → `docs/security.md`. Changes made:
  - security headers + CSP;
  - HSTS in production;
  - per-osobné-číslo brute-force lockout;
  - `staff:` route middleware plus a test that every route requires login;
  - private-storage route disabled;
  - local avatars instead of ui-avatars.com (it leaked staff names);
  - Slovak error pages;
  - encrypted-session check in `app:hosting-check`;
  - optional `TRUSTED_PROXIES`.

  `composer audit` / `npm audit` are clean. Checked in Chromium: no CSP violations on any main screen.
- **Backups:**
  - `php artisan app:backup`, nightly at 02:30 with 30-day rotation, also run by `deploy/update.sh`;
  - *Nastavenia → Zálohy* to list, download and create backups now (admin, audited);
  - `php artisan app:backup-restore` (makes a safety backup first);
  - restore also works with the plain `mysql` client (tested on the full demo DB: identical row counts);
  - `app:hosting-check` warns when the newest backup is older than 26 h.
- **Handover:**
  - README rewritten (overview, code map, deployment, updating with `deploy/update.sh`, cron, backups incl. offsite pull and restore, operations, troubleshooting);
  - `docs/pilot.md` go-live checklist;
  - Slovak manual extended with backups and a guide for employees.
- 211 tests green (SQLite + MariaDB).

**Remaining from the brief:** OPEN §13.7 (go-live date) — drives the pilot plan in `docs/pilot.md`.

### Follow-up: employee logins (2026-11-10)

- New employees (form, import, CLI) get a PIN automatically. Access slips show the **current** PIN and can be
  reprinted any time:
  - **Lístok** per employee;
  - **Tlačiť prístupové lístky** for a selection;
  - **Nevytlačené lístky (N)** for everyone not printed yet, e.g. after an import (the import notification also offers it).
- App-generated PINs are stored encrypted besides the hash. A PIN the employee chose stays unreadable, and their
  slip shows a note. PIN status column + "lístok ešte nevytlačený" filter. Printing is audited.
- Deploy: `bash deploy/update.sh` (new columns). Existing employees from before this change show PIN status
  *neznámy*: use *Nové PINy + prístupové lístky* once for them.

### Follow-up: prints, wording and desktop layout (2026-11-10)

- **Weekly menu print**: *Jedálny lístok → Tlačiť týždeň / PDF* — A4 landscape, Monday–Sunday, soup, options, dinner,
  allergens + legend, closed days; one page (tested).
- **Access sheets**: one A4 per employee with large credentials, QR code and full instructions (live cutoffs and prices).
  The 8-per-page cards are still available as *Malé lístky*.
- **Kitchen daily print**: one row per employee with the full dish name and osobné číslo, zebra rows, header repeated
  on every page (~33 people/page).
- **Wording**: *Zamestnanecká strava* (1,50 €) and *Dokupovaná strava* (6,00 €) everywhere (migration renames existing data).
- **Desktop week view**: rows aligned across all days (CSS subgrid).
- Deploy: `bash deploy/update.sh` (one migration).

### Follow-up: web address on the access sheet (2026-11-10)

- Every A4 access sheet now shows the full `https://…` address (from `APP_URL`) in a large green box for PC users,
  next to the QR code for phones; step 1 explains both ways. Still one page per person (checked in Chromium print).

### Follow-up: one more of the same meal (2026-11-10)

- Tapping an already ordered meal now offers **Dokúpiť ďalšie rovnaké jedlo za 6,00 €** next to *Zrušiť objednávku*
  (phone and PC). With two or more of the same meal, cancelling removes the Dokupovaná one first. When an admin cap on
  Dokupovaná strava is reached, only cancelling is offered.

### Follow-up: reusable branding (2026-11-10)

- Organisation name moved from the code to *Nastavenia → Objednávanie → Názov firmy* (fallback `APP_COMPANY`).
  Shown on access sheets, the login page, the installed app's name and in the 2FA app. Existing installations keep
  "KB SPA" automatically (migration), new ones start neutral. README: *Using it for your organisation*.
- Deploy: `bash deploy/update.sh` (one migration). On a brand-new installation, set the name once in the admin or `.env`.
