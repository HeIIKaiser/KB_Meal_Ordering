# Jedáleň — objednávanie stravy pre zamestnancov KB SPA

Employee meal ordering for KB SPA / Kúpele Brusno. Replaces the lunch ordering of
the old company software. **Flow: pick day → tap meal → done.**

- Full requirements: [`docs/BRIEF.md`](docs/BRIEF.md)
- Decisions + open questions for Roman: [`docs/DECISIONS.md`](docs/DECISIONS.md)
- Milestone demo notes: [`docs/milestones.md`](docs/milestones.md)

| | |
|---|---|
| Stack | Laravel 13, Filament 5 (staff panel `/admin`), Livewire 4, MySQL/MariaDB |
| Hosting | Websupport shared webhosting, subdomain (e.g. `obedy.<firma>.sk`) |
| Runtime needs | PHP ≥ 8.3 + MySQL/MariaDB + cron. **No** Node, queue workers or daemons on the server. |
| Language | UI Slovak; code/comments/commits English |
| Time | Timestamps stored in UTC; business dates and cutoffs in `Europe/Bratislava` |

## Status

| Milestone | State |
|---|---|
| M0 Hosting spike | **Verified** on Websupport test hosting (`kbsandbox.arpo.cc`) — see [M0 findings](#m0-findings) |
| M1 Domain core | Done — migrations, models, ordering/pricing/cutoff/lock services, seeders, 54 tests |
| M2 Employee app | Done — login (osobné číslo + PIN, 400-day remember-me), one-screen ordering, Môj mesiac, PIN change, PWA |
| M3–M6 | Not started |

---

## Local development

Requirements: PHP 8.3+ (ext: pdo_mysql, intl, zip, mbstring), Composer 2, MySQL 8 / MariaDB 10.6+.
Node is needed only later (M2) to compile CSS/JS locally — never on the server.

```bash
git clone <repo> jedalen && cd jedalen
composer install
cp .env.example .env
php artisan key:generate
```

Create a database (`utf8mb4`, ideally collation `utf8mb4_slovak_ci`), fill `DB_*` in `.env`, then:

```bash
php artisan migrate --seed                       # schema + reference data (slots, price types, prices)
php artisan db:seed --class=DemoDataSeeder       # optional demo data (~80 employees, menus, orders)
php artisan serve
```

Employee app: `/` (login at `/prihlasenie`). Staff panel: `/admin`.

Demo logins (DemoDataSeeder only): staff `admin@jedalen.test`, `kuchyna@jedalen.test`, `mzdy@jedalen.test`
with password `heslo1234`; employees `1001`–`1080` with PIN `1234`.

### Front-end assets

The server has no Node.js, so CSS/JS are compiled locally and **`public/build` is committed**:

```bash
npm install
npm run build        # after any change in resources/views, resources/css or resources/js
git add public/build
```

Livewire/Alpine JS is served by Livewire itself (no build step). The PWA files are static in
`public/` (`manifest.webmanifest`, `sw.js`, `offline.html`, `icons/` — regenerate icons with
`php tools/make-icons.php`). When changing `sw.js`, bump its `CACHE` name.

### Tests

```bash
./vendor/bin/pest                    # fast, SQLite in memory
```

Run against MySQL/MariaDB as well — this also runs the real concurrency test
(`tests/Concurrency`, needs the `pcntl` extension) which fires parallel processes at
the same employee/slot to prove that row locking prevents double donated meals:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=jedalen_test DB_USERNAME=... DB_PASSWORD=... ./vendor/bin/pest
```

The test database is wiped by the tests — never point it at real data.

Code style: `./vendor/bin/pint`.

---

## M0 — hosting checklist (Websupport)

Goal: prove that the hosting can run the app **before** building features. Record each
finding in the [Findings](#m0-findings) table below.

### 1. Hosting probe (no Laravel needed)

1. Edit `tools/hosting-probe.php`: change `TOKEN` to a random string.
2. Upload it (FTP/SFTP) to the subdomain's web root and open
   `https://<subdomain>/hosting-probe.php?token=<TOKEN>`.
   It reports PHP version + extensions, disabled functions, limits, HTTPS, writable dirs,
   symlink support, and (via the form) the MySQL connection incl. `SELECT … FOR UPDATE`.
3. If SSH exists, also run `php hosting-probe.php` there, and once **from a cron job**
   (`php /path/hosting-probe.php > /path/probe-cron.txt`) — the CLI PHP version used by cron
   is often different from the web one.
4. **Delete the file afterwards.**

### 2. Document root → `public/`

Verified layout on Websupport (option 2 below), app code outside the web root:

```
~/apps/<app>/                       ← whole repository (app/, vendor/, .env, storage/ …)
~/<domain>/sub/<subdomain>          ← RELATIVE symlink → ../../apps/<app>/public
~/<domain>/cron/<app>-schedule.php  ← cron wrapper (see Cron)
```

```bash
# a new subdomain folder contains a Websupport placeholder index.php — move it away first
mv ~/<domain>/sub/<subdomain> ~/<subdomain>-placeholder-backup
cd ~/<domain>/sub && ln -s ../../apps/<app>/public <subdomain>
```

Options, in order of preference:

1. Set the subdomain's document root to `…/<app>/public` in WebAdmin (if offered).
2. **Replace the subdomain folder with a relative symlink** to `…/<app>/public` (works on Websupport).
3. Last resort: put the whole app *into* the subdomain root and copy
   [`deploy/htaccess-root-fallback`](deploy/htaccess-root-fallback) there as `.htaccess`
   (rewrites into `public/`, denies `.env`, `vendor/`, …).

After setup, check that `public/` holds only `index.php`, `.htaccess`, `favicon.ico`, `robots.txt`,
`manifest.webmanifest`, `sw.js`, `offline.html`, `icons/`, `build/` and the Filament asset folders
(`css/`, `js/`, `fonts/`).

### 3. Install the app

Websupport has a web console with SSH, Composer and git. Clone over SSH with a
read-only GitHub **deploy key**, deploying the `main` branch:

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

Update an existing installation:

```bash
cd ~/apps/<app>
php artisan down
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan up
```

Compiled CSS/JS (`public/build`) is committed — never run `npm` on the server.

Without SSH: on your PC run `deploy/build-release.sh` → upload and unzip `build/jedalen-<commit>.zip`.

#### Production `.env` (differences from `.env.example`)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://obedy.<firma>.sk
SESSION_SECURE_COOKIE=true
LOG_LEVEL=warning
DB_HOST=db.r6.websupport.sk       # the DB host shown in WebAdmin (see note)
DB_PORT=3306
DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=…
DB_COLLATION=utf8mb4_slovak_ci
```

On Websupport, `localhost`/socket only works on the web servers, **not from the SSH console**
(where `artisan migrate` and cron run). Use the full DB host name (e.g. `db.r6.websupport.sk`),
which works from both.

### 4. Cron

Websupport WebAdmin cron can only run a **PHP file** (path relative to the domain folder,
no arguments), so `artisan schedule:run` is called through a wrapper:

1. Copy [`deploy/websupport-cron.php`](deploy/websupport-cron.php) to e.g.
   `~/<domain>/cron/<app>-schedule.php` (outside any web root).
2. Edit `APP_DIR` at the top — absolute, or relative to the wrapper's folder
   (default `../../apps/kbsandbox`).
3. WebAdmin → Cron: new job, file `cron/<app>-schedule.php`, **every minute**, PHP 8.5.
4. Each run writes `storage/logs/cron-last-run.txt` (start, end, exit code, PHP binary).

On a host with a normal crontab the equivalent is:

```
* * * * * /usr/php85/bin/php /<home>/apps/<app>/artisan schedule:run >> /dev/null 2>&1
```

Ordering cutoffs do **not** depend on cron — cron is for backups and housekeeping.

### 5. Verify

```bash
php artisan app:hosting-check
```

Checks PHP/extensions, `.env` safety (HTTPS URL, debug off, secure cookies), DB + InnoDB,
writable dirs, Filament assets, and that the cron **heartbeat** is younger than 3 minutes.
Then open `https://<subdomain>/admin`, log in, set up 2FA, and check `https://<subdomain>/up`.

### M0 findings

Verified by Roman on **2026-11-09** on `kbsandbox.arpo.cc` (Roman's arpo.cc Websupport hosting = test
environment). Production will be the company's Websupport hosting — same platform, repeat the
checklist there.

| Check | Result |
|---|---|
| Platform | Websupport shared webhosting, test subdomain `kbsandbox.arpo.cc` |
| PHP (web) | 8.5, selected in WebAdmin |
| PHP (SSH console CLI) | `/usr/bin/php8.5` — 8.5.12 |
| PHP (cron) | `/usr/php85/bin/php` — 8.5.12 |
| SSH / Composer / git | Web console with SSH ✔, Composer 2.10.3 ✔, git 2.34.1 ✔; outbound SSH to GitHub works (read-only deploy key) |
| Database | MariaDB 11.4.12, InnoDB ✔, `utf8mb4_slovak_ci` ✔. Host `db.r6.websupport.sk:3306`; socket/`localhost` only work on the web servers, **not** from the console → always use the host name |
| Document root → `public/` | Option 2 ✔: app in `~/apps/kbsandbox`, `~/arpo.cc/sub/kbsandbox` is a **relative** symlink to `../../apps/kbsandbox/public`. New subdomain folders contain a Websupport placeholder `index.php` — move it out before symlinking |
| Sensitive files | Websupport WAF answers `/.env` with its own 403; `public/` verified to contain only `index.php`, `.htaccess`, favicon, robots and Filament assets |
| HTTPS | ✔ |
| Cron | WebAdmin cron runs only a PHP *file* (no arguments) → wrapper [`deploy/websupport-cron.php`](deploy/websupport-cron.php), writes `storage/logs/cron-last-run.txt` ✔ |
| `php artisan app:hosting-check` | All checks passed ✔ |

Nothing blocks. Note: Laravel 13 / Filament 5 run on PHP 8.5 there; development and CI use ≥ 8.3.

---

## Operations

### Add a staff user (kitchen / payroll / admin)

```bash
php artisan app:staff-user person@example.sk --role=kitchen        # roles: admin, kitchen, payroll (repeatable)
```

2FA is optional: a user can enable it under *Profil* in `/admin` (authenticator app + recovery codes).
(In M3 user management also moves into the panel.)

### Reset an employee's PIN

```bash
php artisan app:reset-pin <osobne_cislo>          # prints a new random 6-digit PIN, logs out all devices
```

(In M3 this is a button in the admin panel, with a printable access slip.)

### Backups

Planned for M6 (nightly DB dump via the scheduler into `storage/app/backups`, 30-day rotation).

---

## Code map

| Path | What |
|---|---|
| `app/Livewire/OrderScreen.php` + `resources/views/livewire/order-screen.blade.php` | Employee one-screen ordering (week strip, tap = order). |
| `app/Http/Controllers/Employee/*` | Employee login/logout, PIN change, Môj mesiac. |
| `app/Domain/Ordering/OrderService.php` | **All order writes**: place / switch / cancel / admin price-type correction. Price typing, limits, row locking, audit. |
| `app/Domain/Ordering/AvailabilityService.php` | Is a slot/day orderable? (cutoff, lock, closed day, horizon, menu). Batch evaluation for a week. |
| `app/Domain/Ordering/PriceResolver.php` | Versioned prices (valid on the meal date). |
| `app/Domain/Ordering/SlotLockService.php` | Manual lock / unlock (kitchen, admin). |
| `app/Support/Settings.php` | Settings with defaults (`ordering_horizon_days`, `max_extra_per_slot`). |
| `app/Support/LocalTime.php`, `app/Casts/LocalDate.php` | UTC storage vs. Europe/Bratislava business time. |
| `database/seeders/ReferenceDataSeeder.php` | Slots, price types, initial prices (safe to rerun). |
| `database/seeders/DemoDataSeeder.php` | Demo data — never in production. |
| `tools/hosting-probe.php` | M0 standalone hosting probe. |
| `deploy/` | Fallback `.htaccess`, release ZIP builder. |
