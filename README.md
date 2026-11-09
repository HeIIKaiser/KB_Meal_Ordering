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
| M0 Hosting spike | **Kit ready, not yet verified on the real hosting** — see [M0 checklist](#m0--hosting-checklist-websupport) |
| M1 Domain core | Done — migrations, models, ordering/pricing/cutoff/lock services, seeders, 54 tests |
| M2–M6 | Not started |

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

Demo logins (DemoDataSeeder only): staff `admin@jedalen.test`, `kuchyna@jedalen.test`, `mzdy@jedalen.test`
with password `heslo1234` (2FA setup is required on first login); employees `1001`–`1080` with PIN `1234`.

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

Preferred layout (app code outside the web root):

```
/<home>/jedalen/            ← whole repository (app/, vendor/, .env, storage/ …)
/<home>/jedalen/public/     ← the subdomain's document root must point HERE
```

Try in this order and note which works:

1. **Set the subdomain's document root** to `…/jedalen/public` in the Websupport admin.
2. If the root folder is fixed: **replace that folder with a symlink** to `…/jedalen/public`
   (`ln -s ~/jedalen/public <subdomain-root>`), if symlinks are allowed (the probe tests it).
3. Last resort: put the whole app *into* the subdomain root and copy
   [`deploy/htaccess-root-fallback`](deploy/htaccess-root-fallback) there as `.htaccess`. It rewrites
   everything into `public/` and denies access to `.env`, `vendor/`, `storage/` … —
   **verify** that `https://<subdomain>/.env` and `https://<subdomain>/composer.json` return 403/404.

### 3. Install the app

With SSH + Composer on the server:

```bash
cd ~/jedalen
git clone <repo> .            # or upload files
composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env          # see "Production .env" below
php artisan key:generate
php artisan migrate --force --seed
php artisan app:staff-user <your-email> --role=admin
php artisan optimize
```

Without SSH: on your PC run `deploy/build-release.sh` → upload and unzip `build/jedalen-<commit>.zip`
(it contains `vendor/`). Migrations then need a way to run `php artisan migrate --force`
(cron one-off job, or Websupport's web console) — **note what's available**.

#### Production `.env` (differences from `.env.example`)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://obedy.<firma>.sk
SESSION_SECURE_COOKIE=true
LOG_LEVEL=warning
DB_HOST=… DB_DATABASE=… DB_USERNAME=… DB_PASSWORD=…
DB_COLLATION=utf8mb4_slovak_ci
```

### 4. Cron

Add one cron job in the Websupport admin, **every minute**, using the PHP CLI binary of the
right version (≥ 8.3; the probe shows its path):

```
* * * * * /usr/local/bin/php8.3 /<home>/jedalen/artisan schedule:run >> /dev/null 2>&1
```

(Exact PHP path depends on the hosting; if the admin only allows "run a PHP script", point it
at `artisan` with argument `schedule:run`.) Ordering cutoffs do **not** depend on cron — cron is
for backups and housekeeping.

### 5. Verify

```bash
php artisan app:hosting-check
```

Checks PHP/extensions, `.env` safety (HTTPS URL, debug off, secure cookies), DB + InnoDB,
writable dirs, Filament assets, and that the cron **heartbeat** is younger than 3 minutes.
Then open `https://<subdomain>/admin`, log in, set up 2FA, and check `https://<subdomain>/up`.

### M0 findings

Fill in on the real hosting (and send to the developer):

| Check | Result |
|---|---|
| Websupport plan / server | |
| PHP versions available (web / CLI / cron) | |
| SSH available? Composer available? | |
| Document root can point to `public/`? Which option (1/2/3) works? | |
| HTTPS (Let's Encrypt) on the subdomain, HTTP→HTTPS redirect | |
| MySQL/MariaDB version, InnoDB, `utf8mb4_slovak_ci` available | |
| Cron: min. interval, can it run PHP CLI, which binary path | |
| `proc_open` / `exec` enabled? `mysqldump` available? (backups, M6) | |
| `memory_limit`, `max_execution_time`, `upload_max_filesize` | |
| `app:hosting-check` result | |

**If any of these blocks, stop and report before building more features.**

---

## Operations

### Add a staff user (kitchen / payroll / admin)

```bash
php artisan app:staff-user person@example.sk --role=kitchen        # roles: admin, kitchen, payroll (repeatable)
```

The user sets up 2FA (authenticator app) on first login at `/admin`. (In M3 this also moves into the panel.)

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
