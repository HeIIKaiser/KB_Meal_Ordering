# Jedáleň — objednávanie stravy pre zamestnancov

Employee meal ordering for a company canteen: employees order lunch and dinner on their phone or PC,
the kitchen gets daily lists, payroll gets monthly exports. **Flow: pick day → tap meal → done.**
Originally built for KB SPA (Kúpele Brusno); nothing organisation-specific is in the code — see
[Using it for your organisation](#using-it-for-your-organisation).

This README is the handover document: a future admin should be able to install, run, update, back up
and restore the app with it alone.

| Document | For |
|---|---|
| [`docs/BRIEF.md`](docs/BRIEF.md) | Original requirements |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Every decision and answered question (read before changing behaviour) |
| [`docs/navod.md`](docs/navod.md) | **Slovak manual** for kitchen, payroll, admin and employees |
| [`docs/pilot.md`](docs/pilot.md) | Pilot / go-live checklist |
| [`docs/security.md`](docs/security.md) | Security review (M6) |
| [`docs/milestones.md`](docs/milestones.md) | What was delivered in each milestone |

| | |
|---|---|
| Stack | Laravel 13, Filament 5 (staff panel `/admin`), Livewire 4 + Alpine, Tailwind 4 (built locally), MySQL/MariaDB |
| Hosting | Any PHP shared webhosting with MySQL/MariaDB and cron (built and verified on Websupport) |
| Runtime needs | PHP ≥ 8.3 + MySQL/MariaDB + cron. **No** Node, queue workers or daemons on the server |
| Language | UI Slovak; code, comments, commits English |
| Time | Timestamps stored in UTC; business dates and cutoffs in `Europe/Bratislava` |

## Using it for your organisation

Everything organisation-specific is configuration or data, so updates (`git pull`) never overwrite it:

| What | Where |
|---|---|
| Organisation name (access sheets, login page, installed app name, 2FA) | `APP_COMPANY` in `.env`, or *Nastavenia → Objednávanie → Názov firmy* (wins once saved) |
| Address on the access sheets / QR code | `APP_URL` in `.env` (use the full `https://…`) |
| Prices (Zamestnanecká 1,50 € / Dokupovaná 6,00 €), price history | *Nastavenia → Ceny* |
| Meals, cutoff times, soup of the day | *Nastavenia → Jedlá a uzávierky* |
| Ordering horizon, cap on Dokupovaná strava | *Nastavenia → Objednávanie* |
| Employees, staff accounts, menus, closed days | Admin panel (with Excel/CSV import) |

The price rule (first lunch and first dinner of a day at the employee price, anything more at the full
price) and the Slovak UI are built in; see `docs/DECISIONS.md` for the reasoning before changing them.

## Status

All milestones of the brief are done (M0–M6). Next step: the pilot — see [`docs/pilot.md`](docs/pilot.md).

| Milestone | |
|---|---|
| M0 Hosting | Verified on Websupport ([findings](#appendix-m0-hosting-findings)) |
| M1 Domain core | Ordering rules, pricing, cutoffs, locks, audit; concurrency-safe |
| M2 Employee app | PIN login (remembered 400 days), one-screen ordering, Môj mesiac, PIN change, installable PWA |
| M3 Admin | Employees (+ import, PIN slips), prices, cutoffs, settings, closed days, menus (grid + import, soup), orders + corrections, staff users, audit log |
| M4 Kitchen | Daily overview, print (A4), PDF, Excel, lock/unlock, dashboard |
| M5 Exports | Monthly per-employee matrix + payroll summary, flat order list (XLSX/CSV) |
| M6 Hardening | Security headers/CSP, brute-force limits, route guard tests, Slovak error pages, nightly backups + restore, handover docs |

---

## How it works (5-minute overview)

- **Employees** open the app on their phone (`/`), log in once with *osobné číslo* + PIN and stay logged
  in (400-day HttpOnly cookie). They tap a dish to order it; the first lunch and first dinner of a day are
  **Zamestnanecká strava** (1,50 €), anything more is **Dokupovaná strava** (6,00 €, confirmed before ordering). Internally the codes are `donated` / `extra`. Ordering closes
  automatically at the cutoff (Obed 09:00, Večera 14:00, same day — configurable) or when the kitchen locks it.
- **Kitchen** (`/admin`, role *kitchen*) enters the weekly menu (grid or Excel import, lunch = soup + 2 mains,
  dinner = 1), prints the daily list and can lock ordering early.
- **Payroll** (role *payroll*) downloads the monthly export. Prices are an estimate until matched with
  attendance (8 h shift = 1 donated meal, 12 h = 2) — every monthly rundown says so.
- **Admin** manages employees and PINs, prices, cutoffs, staff users, corrects orders (after the cutoff with
  a reason) and sees the full audit log.

Rules live in one place: **`app/Domain/Ordering/OrderService.php`** — every order write goes through it
(price typing, limits, row locking, audit). See [`docs/DECISIONS.md`](docs/DECISIONS.md) for all rules.

### Code map

| Path | What |
|---|---|
| `app/Domain/Ordering/OrderService.php` | **All order writes**: place / switch / cancel / admin price-type change. Price typing, limits, row lock, audit |
| `app/Domain/Ordering/AvailabilityService.php` | Is a slot/day orderable? (cutoff, lock, closed day, horizon, menu); batch for a week |
| `app/Domain/Ordering/PriceResolver.php`, `SlotLockService.php` | Versioned prices; manual lock/unlock |
| `app/Domain/Menus/*` | Menu writer (plan → affected employees → apply), menu import (weekly + long format, allergens) |
| `app/Domain/Employees/*` | Employee import, PIN issuing |
| `app/Domain/Tickets/*` | Ticket mode: employee groups and their mode/membership history (`EmployeeCalendar`), allocation, **`Repricer`** (the month's first N meals at the employee price), monthly counts, month-end import, closed months |
| `app/Domain/Kitchen/*`, `resources/views/kitchen/*` | Kitchen daily overview data (shared by screen, print, PDF, XLSX); printable sheet |
| `app/Domain/Exports/*` | Payroll exports: `MonthlyData` (numbers), **`MonthlyMatrixXlsx` (whole monthly layout)**, `OrderListExport` |
| `app/Domain/Backup/DatabaseBackup.php` | Pure-PHP gzipped SQL backup, rotation, restore |
| `app/Livewire/OrderScreen.php` + `resources/views/livewire/order-screen.blade.php` | Employee ordering screen |
| `app/Http/Controllers/Employee/*` | Employee login/logout, PIN change, Môj mesiac |
| `app/Http/Controllers/Admin/*` | Print/PDF/XLSX, exports, slips, templates, backup download (guarded by `staff:` middleware) |
| `app/Filament/**` | Staff panel: resources, custom pages (kitchen day, menu week, imports, exports, settings, backups), widgets |
| `app/Policies/*` | Who may do what in the staff panel |
| `app/Http/Middleware/*` | `SecurityHeaders`, `EnsureStaffRole` (`staff:`), `EnsureEmployeeIsActive` |
| `app/Support/*` | `Settings`, `Money` (+ price disclaimer), `LocalTime`, `SlovakDate`, `Allergens`, spreadsheet reader |
| `config/jedalen.php` | App settings: timezone, login limits, trusted proxies, backup path |
| `routes/web.php`, `routes/console.php` | Routes; scheduler (heartbeat, nightly backup) |
| `deploy/` | `update.sh`, Websupport cron wrapper, fallback `.htaccess`, release ZIP builder |
| `tools/` | Hosting probe (M0), icon generator |

Everything that can be changed without code is in the panel: cutoffs (*Nastavenia → Jedlá a uzávierky*),
prices, ordering horizon and extra cap (*Nastavenia → Objednávanie*), closed days, menus, users.

---

## Local development

Requirements: PHP 8.3+ (ext: pdo_mysql, intl, zip, mbstring, gd), Composer 2, MySQL 8 / MariaDB 10.6+,
Node 20+ (only to compile CSS locally).

```bash
git clone <repo> jedalen && cd jedalen
composer install
cp .env.example .env
php artisan key:generate
```

Create a database (`utf8mb4`, collation `utf8mb4_slovak_ci`), fill `DB_*` in `.env`, then:

```bash
php artisan migrate --seed                       # schema + reference data (slots, price types, prices)
php artisan db:seed --class=DemoDataSeeder       # optional demo data (~80 employees, menus, orders)
php artisan serve
```

Employee app: `/` (login at `/prihlasenie`). Staff panel: `/admin`.
There are no built-in logins. Create your staff account with `php artisan app:staff-user you@example.com --role=admin`;
employees (demo ones too) get random PINs — print them in *Zamestnanci → Tlačiť prístupové lístky*.
The demo seeder refuses to run in production.

### Front-end assets

The server has no Node.js, so CSS/JS are compiled locally and **`public/build` is committed**:

```bash
npm install
npm run build        # after any change in resources/views, resources/css, resources/js or app/Filament views
git add public/build
```

This includes the Filament panel theme (`resources/css/filament/admin/theme.css`). Livewire/Alpine JS is
served by Livewire itself. PWA files are static in `public/` (`manifest.webmanifest`, `sw.js`,
`offline.html`, `icons/` — regenerate icons with `php tools/make-icons.php`). When changing `sw.js`, bump
its `CACHE` name.

### Tests

```bash
./vendor/bin/pest                    # fast, SQLite in memory (2 MySQL-only tests are skipped)
```

Also run against MySQL/MariaDB for anything touching SQL, locking or backups — this runs the real
multi-process race test (`tests/Concurrency`, needs `pcntl`) and the backup/restore round trip on MySQL:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=jedalen_test DB_USERNAME=... DB_PASSWORD=... ./vendor/bin/pest
```

The test database is wiped by the tests — never point it at real data. Code style: `./vendor/bin/pint`.
`tests/Feature/Security/SecurityTest.php` fails if a new route is added without authentication.

---

## Deployment (Websupport)

Verified layout — app code outside the web root, deployed from the `main` branch:

```
~/apps/<app>/                       ← the repository (app/, vendor/, .env, storage/ …)
~/<domain>/sub/<subdomain>          ← RELATIVE symlink → ../../apps/<app>/public   (document root)
~/<domain>/cron/<app>-schedule.php  ← cron wrapper
```

### First installation

1. **Database:** create a MariaDB database + user in WebAdmin (collation `utf8mb4_slovak_ci`). Note the DB
   host name shown there (e.g. `db.r6.websupport.sk`).
2. **Code** (web console / SSH; GitHub read-only deploy key):

   ```bash
   mkdir -p ~/apps && cd ~/apps
   git clone -b main git@github.com:<owner>/<repo>.git <app> && cd <app>
   composer install --no-dev --optimize-autoloader
   cp .env.example .env && nano .env          # see "Production .env" below
   php artisan key:generate
   php artisan migrate --force --seed
   php artisan app:staff-user <your-email> --role=admin
   php artisan optimize
   ```

3. **Document root:** create the subdomain in WebAdmin, then replace its folder with a symlink
   (a new subdomain folder contains a Websupport placeholder `index.php` — move it away first):

   ```bash
   mv ~/<domain>/sub/<subdomain> ~/<subdomain>-placeholder-backup
   cd ~/<domain>/sub && ln -s ../../apps/<app>/public <subdomain>
   ```

   (If symlinks are not possible: set the document root to `…/<app>/public` in WebAdmin, or — last
   resort — copy [`deploy/htaccess-root-fallback`](deploy/htaccess-root-fallback) as `.htaccess`.)
4. **HTTPS:** enable the free certificate for the subdomain in WebAdmin.
5. **Cron:** see [Cron](#cron).
6. **Check:** `php artisan app:hosting-check` (all OK within a few minutes after the cron starts; the
   backup check turns OK after the first `php artisan app:backup`). Open `https://<subdomain>/admin`
   and `https://<subdomain>/up`.
7. Continue with [`docs/pilot.md`](docs/pilot.md) (employees import, menus, access slips, …).

#### Production `.env` (differences from `.env.example`)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://obedy.<firma>.sk
SESSION_SECURE_COOKIE=true
LOG_LEVEL=warning
DB_HOST=db.r6.websupport.sk       # host name from WebAdmin, NOT localhost (see below)
DB_PORT=3306
DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=…
DB_COLLATION=utf8mb4_slovak_ci
```

On Websupport, `localhost`/socket only works on the web servers, **not from the SSH console** (where
`artisan` and cron run) — always use the full DB host name. Optional keys: `TRUSTED_PROXIES` (only if
the hosting puts a reverse proxy in front of PHP — see `config/jedalen.php`), `BACKUP_PATH`.

### Updating

```bash
cd ~/apps/<app> && bash deploy/update.sh
```

It backs up the database, shows a maintenance page, fast-forwards to `origin/main`, installs
dependencies, migrates, refreshes caches and runs `app:hosting-check`. Never run `npm` on the server.
After changing `.env` run `php artisan optimize` (config is cached).

Without SSH: on a PC run `deploy/build-release.sh` → upload and unzip `build/jedalen-<commit>.zip`.

### Cron

Websupport WebAdmin cron can only run a **PHP file** (no arguments), so `artisan schedule:run` is called
through a wrapper:

1. Copy [`deploy/websupport-cron.php`](deploy/websupport-cron.php) to `~/<domain>/cron/<app>-schedule.php`.
2. Edit `APP_DIR` at the top (absolute, or relative to the wrapper's folder; default `../../apps/jedalen`).
3. WebAdmin → Cron: file `cron/<app>-schedule.php`, **every minute**, PHP 8.5.
4. Each run writes `storage/logs/cron-last-run.txt` (start, end, exit code, PHP binary).

Elsewhere: `* * * * * /usr/php85/bin/php /<home>/apps/<app>/artisan schedule:run >> /dev/null 2>&1`

Scheduled tasks (all run inside the cron process, no `exec`/`proc_open` needed):

| Task | When |
|---|---|
| Heartbeat (proves the cron runs; checked by `app:hosting-check`) | every minute |
| Database backup + 30-day rotation | daily 02:30 Europe/Bratislava |

Ordering cutoffs do **not** depend on cron.

---

## Backups

| | |
|---|---|
| What | Whole database as gzipped SQL (structure + data; sessions/cache without rows) |
| When | Nightly 02:30 (scheduler) · before every `deploy/update.sh` · on demand: *Nastavenia → Zálohy → Zálohovať teraz* or `php artisan app:backup` |
| Where | `~/apps/<app>/storage/app/private/backups/` — **not reachable from the web** |
| Name | `jedalen_YYYY-MM-DD_HHMMSS.sql.gz` (local time). In-progress files end with `.part` — ignore them |
| Rotation | 30 days, the 7 newest are always kept |
| Size | ~15 kB per month of data for 80 employees |
| Monitoring | `app:hosting-check` fails if the newest backup is older than 26 h; the *Zálohy* page shows a red warning |

Backups contain personal data (names, osobné čísla, PIN hashes and **encrypted printable PINs**). Downloads in
the panel are admin-only and logged in *História zmien*.

**Keep a copy of `APP_KEY` (from `.env`) in a safe place, separate from the backups.** Printable PINs are
encrypted with it: after restoring onto a server with a different key, logins still work, but slips show
„PIN nie je známy“ until PINs are reset.

### Offsite copy (pulled by the company)

Pull the files from inside the company network over SSH, e.g. nightly at 04:00 from a company server:

```bash
rsync -av --exclude='*.part' <ssh-user>@<websupport-ssh-host>:apps/<app>/storage/app/private/backups/ /backup/jedalen/
```

(Use an SSH key restricted to this purpose. `scp` works too. The files are complete once they no longer
end in `.part`; each ends with the line `-- END OF BACKUP`.)

### Restore

**Replaces all data.** The command first makes a safety backup of the current state and shows a
maintenance page while restoring:

```bash
php artisan app:backup-restore                                   # newest backup (asks for confirmation)
php artisan app:backup-restore jedalen_2026-11-10_023000.sql.gz  # a specific one
```

The files are plain SQL (one statement per line), so they also restore without the app:

```bash
gunzip -c jedalen_2026-11-10_023000.sql.gz | mysql -h <db-host> -u <user> -p <database>
```

or via phpMyAdmin → Import (the `.sql.gz` file directly). Restoring into an empty database works too
(e.g. a new hosting): restore, then `php artisan migrate --force` (no-op if up to date).

---

## Operations

| Task | How |
|---|---|
| Add a staff user | Panel: *Ľudia → Správcovia → Nový*. First admin / emergency: `php artisan app:staff-user person@example.sk --role=admin` |
| Staff forgot password | Admin edits the user and sets a new password. Locked-out admin: `php artisan app:staff-user <email>` (answer *yes* to set a new password) |
| Staff lost 2FA phone | Admin: *Správcovia → Vypnúť 2FA* (2FA is optional, enabled per user in *Profil*) |
| Employee logins | Every new employee (form, import, CLI) gets a random PIN automatically. Slips with the **current** PIN: *Zamestnanci* → *Lístok* (one), select → *Tlačiť prístupové lístky* (batch), *Nevytlačené lístky (N)* (all not printed yet, e.g. after an import). Printing doesn't change the PIN |
| Employee forgot PIN | Panel: *Zamestnanci → Reset PIN* (new PIN + slip, logs out other devices). CLI: `php artisan app:reset-pin <osobne_cislo>` |
| Employee locked after wrong PINs | Waits 60 s (5 tries per phone) or 15 min (10 tries per osobné číslo from anywhere). Immediately: `php artisan cache:clear` |
| New employees | *Zamestnanci → Import z XLSX/CSV* or *Nový*; then *Nové PINy + prístupové lístky* |
| Employee leaves | Untick *Aktívny* (never delete — history stays) |
| Late order after cutoff | *Objednávky → Všetky objednávky → Objednať za zamestnanca* (reason required) |
| Maintenance page | `php artisan down --retry=60` / `php artisan up` |
| Logs | `storage/logs/laravel-YYYY-MM-DD.log` (30 days), `storage/logs/cron-last-run.txt` |
| Health | `php artisan app:hosting-check`, `https://<subdomain>/up` |

### Troubleshooting

| Symptom | Cause / fix |
|---|---|
| „Stránka vypršala“ (419) | A page (usually a login form) was open too long. Reload it. |
| Dashboard/backup warnings, `app:hosting-check` heartbeat FAIL | Cron not running: check WebAdmin cron and `storage/logs/cron-last-run.txt` (exit code, PHP version) |
| „Nastala chyba“ (500) | See the newest `storage/logs/laravel-*.log`. Never set `APP_DEBUG=true` in production. |
| Changes in `.env` have no effect | `php artisan optimize` (config cache) |
| Admin pages look unstyled after an update | `public/build` not committed/pulled, or run `php artisan filament:assets` |
| DB connection refused from SSH | Use the DB host name, not `localhost` |

---

## Appendix: M0 hosting findings

Verified by Roman on **2026-11-09** on `kbsandbox.arpo.cc` (test environment). Production will be the
company's Websupport hosting — same platform; repeat `tools/hosting-probe.php` + `app:hosting-check` there.

| Check | Result |
|---|---|
| Platform | Websupport shared webhosting, test subdomain `kbsandbox.arpo.cc` |
| PHP (web) | 8.5, selected in WebAdmin |
| PHP (SSH console CLI) | `/usr/bin/php8.5` — 8.5.12 |
| PHP (cron) | `/usr/php85/bin/php` — 8.5.12 |
| SSH / Composer / git | Web console with SSH ✔, Composer 2.10.3 ✔, git 2.34.1 ✔; outbound SSH to GitHub works (read-only deploy key) |
| Database | MariaDB 11.4.12, InnoDB ✔, `utf8mb4_slovak_ci` ✔. Host `db.r6.websupport.sk:3306`; socket/`localhost` only on the web servers |
| Document root → `public/` | Relative symlink `~/arpo.cc/sub/kbsandbox` → `../../apps/kbsandbox/public` ✔ (move the placeholder `index.php` out first) |
| Sensitive files | Websupport WAF answers `/.env` with its own 403; `public/` contains only public files |
| HTTPS | ✔ |
| Cron | WebAdmin cron runs only a PHP *file* → `deploy/websupport-cron.php` ✔ |
| `php artisan app:hosting-check` | All checks passed ✔ |

The standalone probe for a new hosting is [`tools/hosting-probe.php`](tools/hosting-probe.php) (upload,
open with `?token=…`, run once from cron, delete).

## License

[MIT](LICENSE) — free to use, change and share, including commercially.
