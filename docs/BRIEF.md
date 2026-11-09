# BRIEF — Employee meal ordering app (KB SPA / Kúpele Brusno)

> Working name: **Jedáleň**. Owner: Roman (IT admin, KB SPA). Read this whole file before writing code.
> Ask Roman when something here is marked **OPEN** and you reach the milestone that needs it — don't guess silently.
>
> Decisions and answers to OPEN items are tracked in [`docs/DECISIONS.md`](DECISIONS.md).

## 1. Why this exists

KB SPA is switching its main company software and loses the current online lunch ordering. We are replacing it with our own web app so we can extend it later (payroll exports, attendance matching, card kiosk).

The old system's ordering flow is the thing to beat: today an employee must first "add points" (1,50 € or 6 €) to a day, only then see the menu, then order. **Our flow: pick day → tap meal → done.** Users are not technical; every extra step is a support call.

## 2. Hard constraints

- **Hosting: Websupport shared webhosting**, on a subdomain of the company website (e.g. `obedy.<company-domain>.sk`). Not on the internal network — this keeps company servers off the internet.
  - Assume **PHP + MySQL/MariaDB only**, no Node.js / Python at runtime, no long-running processes, no queue workers, no websockets.
  - Cron is available (1-minute granularity) — verify that it can run PHP CLI.
  - Front-end assets must be **built locally and committed** (or use zero-build tooling). Never require `npm` on the server.
  - Subdomain document root must point to Laravel's `public/`. If Websupport doesn't allow that, document the workaround.
  - **M0 must verify all of this on the real hosting before building features.**
- **Stack:** latest stable **Laravel** + **Filament** (admin/kitchen panel) + **Livewire/Blade + Alpine** for the employee UI, Tailwind compiled locally. MySQL/MariaDB.
- **UI language: Slovak** (with correct diacritics). Code, comments, commits, identifiers: English.
- **Timezone:** `Europe/Bratislava` everywhere. Store timestamps in UTC, display/evaluate cutoffs in local time.
- **Money:** integer cents (`150`, `600`), formatted as `1,50 €`.
- **Not connected to attendance** in v1. Attendance is matched outside the app using exports → every export carries the **personal number (`osobne_cislo`)** as the join key.
- **Not payroll.** We never calculate payroll — we export clean data for it.
- Private git repo. Write a README a future admin can follow without Roman.

## 3. Business rules

### Meal slots
- Two slots per day: **Obed** (lunch) and **Večera** (dinner). Slots are admin-configurable records (name, sort order, active, cutoff rule) so another one can be added later.
- Each slot per day has a menu with 1..n options ("Menu 1", "Menu 2", …), each with a name and optional allergen numbers (EU 1–14).

### Price types
| Type | Default price | Limit |
|---|---|---|
| **Darované** (donated / employer-subsidised) | 1,50 € | **max 1 per slot per day per employee** (= max 1 lunch + 1 dinner a day) |
| **Extra** (full price) | 6,00 € | **OPEN:** how many per slot per day — make it a setting `max_extra_per_slot`, default `1` |

- **The price type is assigned automatically**, never chosen up front: the employee's first active order in a slot on a day is *Darované*; any further order in the same slot is *Extra*. Before confirming an Extra order, show clearly "Toto jedlo bude za 6,00 €".
- **Prices are versioned** (`valid_from` date). Every order stores a **snapshot** of `price_type` and `unit_price_cents` at the moment of ordering. Repricing never changes existing orders.
- Cancelling an order does **not** re-type the employee's other orders. (If they cancel the donated one and keep an extra, the extra stays extra; their next new order becomes donated again.) Admin can change price type manually (audited).

### Ordering window and locks
- A slot/day is **orderable** when: a menu exists, the day isn't closed, it's before the cutoff, and there's no manual lock.
- **Automatic cutoff** per slot: `cutoff_days_before` (0 or 1) + `cutoff_time`. Evaluated at request time — **no cron needed for locking.**
  - **OPEN** defaults — use: Obed = same day 09:00, Večera = same day 14:00 until Roman confirms.
- **Manual lock:** one button (kitchen/admin) per date + slot, stored as a record (who, when). Also allow manual **unlock**.
- **Ordering horizon:** setting, default 14 days ahead.
- **Closed days:** admin can mark a date (or date + slot) closed with a note ("Sviatok").
- Employees can cancel/change their own orders only while the slot is orderable. After lock, only admin can change (audited, with reason).

## 4. Roles

| Role | Can |
|---|---|
| `employee` | order/cancel own meals, see own history and monthly total |
| `kitchen` | see orders, print lists, lock/unlock, import menus |
| `payroll` | read-only: orders and all exports |
| `admin` | everything: employees, prices, slots, settings, corrections, users |

One person can have several roles. Staff roles (kitchen/payroll/admin) use the Filament panel at `/admin`.

## 5. Authentication

- **Employees:** log in with **osobné číslo + PIN** (4–6 digits, hashed). No email required.
  - **Remember-me always on** (Laravel remember cookie, max allowed lifetime ≈ 400 days). Users should practically never log in again on the same device. Use server-set HttpOnly cookies only — never localStorage tokens (Safari wipes script storage after 7 days without use).
  - Rate-limit by personal number + IP (e.g. 5 attempts/min, then a lockout with a clear Slovak message).
  - Employees can change their own PIN; admin can reset it.
  - **Admin feature: print access slips** — A4 with N slips per page: name, osobné číslo, PIN, app URL + QR code, and 3 lines on "add to home screen". PINs are generated on reset and shown only once (on the slip).
- **Staff (kitchen/payroll/admin):** email + password + **2FA** (Filament MFA).
- Session/CSRF hardening, HTTPS only (HSTS), secure cookies.

## 6. Screens

### 6.1 Employee app (mobile-first, also good on desktop) — the most important part
- Installable **PWA**: manifest, icons (incl. `apple-touch-icon`), `display: standalone`, theme color. Minimal service worker (app shell only; **no offline ordering**).
- **One screen:**
  - Top: week strip (Po–Ne, date), today highlighted, arrows for prev/next week. Swipe on mobile.
  - Body for the selected day: a card per slot (Obed, Večera). Each card shows its status (otvorené do 09:00 / uzamknuté / zatvorené) and the menu options as **large tap targets**.
  - **Tap an option = order it** (Darované automatically). Ordered option shows clearly as selected (check mark + price). Tap again → confirm cancellation.
  - Tapping another option in the same slot while one is ordered → ask: "Zmeniť objednávku?" (switch) or "Pridať extra za 6,00 €" (adds an extra).
  - Small indicators on the week strip for days where something is ordered.
- Footer/secondary page: **"Môj mesiac"** — list of this month's orders, count and € total; previous months too.
- Big fonts, high contrast, no hover-only interactions, works on old Android phones and small screens. Desktop: same flow, wider layout (the whole week can be visible at once).
- Every action gives immediate visible feedback (Livewire), and errors are in plain Slovak ("Objednávky na obed sú už uzavreté.").

### 6.2 Kitchen (Filament)
- **Daily overview** for a date + slot: totals per menu option at the top, then the alphabetical list `Priezvisko Meno – Menu 1: Rybie prsty` (+ an "Extra" mark where relevant).
- **One-click print** (print CSS, A4, fits on as few pages as possible, date/slot/print timestamp in the header, checkbox column for serving, totals per option at the bottom).
- Also downloadable as XLSX (and PDF if easy on shared hosting, e.g. dompdf).
- **Lock / Unlock** button right on this page.
- Menu import and weekly menu editing (see 6.3).

### 6.3 Admin (Filament)
- **Employees:** CRUD, active flag, department/stredisko, roles, PIN reset, access slips. **Import from XLSX/CSV**, upsert by `osobne_cislo`; never delete — deactivate (history stays).
- **Prices:** list with `valid_from`, add new price version. Clear "current price" display.
- **Slots & settings:** cutoffs, ordering horizon, `max_extra_per_slot`, closed days.
- **Menus:**
  - Weekly grid editor (days × slots × options) for quick manual entry/editing.
  - **Import XLSX/CSV** with template columns: `datum, jedlo (obed|vecera), cislo_menu, nazov, alergeny`. Downloadable template. Preview before commit, then commit; report errors per row.
  - **OPEN:** the kitchen's actual weekly menu file. Roman will provide a sample; build an importer adapter for that exact format once received.
  - Changing/removing a menu option that already has orders must warn and list affected employees.
- **Orders:** filterable table (date range, slot, employee, type, status), admin create on behalf of an employee (for people without phones), corrections after lock with mandatory reason.
- **Audit log** of all changes to orders, prices, locks, menus, employees (e.g. `spatie/laravel-activitylog`). Viewable in admin.

## 7. Exports (all XLSX; CSV where noted)

All exports include `osobne_cislo`. Filenames include date/month, e.g. `stravovanie_2026-11_mesacny.xlsx`.

1. **Kitchen daily** (date + slot) — same content as the print.
2. **Monthly per-employee matrix** — the main payroll/attendance export. For a selected month, one block per active-in-month employee, sorted by surname:

   | | | 1 | 2 | 3 | … | 30 | Spolu | Suma € |
   |---|---|---|---|---|---|---|---|---|
   | **Novák Ján** (osobné č. 1234) | November 2026 | Po | Ut | St | … | Po | | |
   | | Darované | 1 | 0 | 2 | … | 1 | 14 | 21,00 |
   | | Extra | 0 | 0 | 1 | … | 0 | 2 | 12,00 |

   - Day cells are **counts**, not booleans (lunch + dinner both donated on the same day = `2`).
   - Day-of-week row helps visual matching with attendance; weekends shaded.
   - Option on the export screen: **split by slot** (rows Darované–obed / Darované–večera / Extra–obed / Extra–večera).
   - € sums use each order's **snapshot price**.
   - Final summary sheet: totals per employee (one row each: osobné číslo, meno, darované ks, extra ks, suma €) — this is the one payroll will actually import.
   - **OPEN:** Roman may want the layout matched to the input sheet of his existing attendance/meal workbook — keep the layout code in one place so it's easy to adjust.
3. **Flat order list** (XLSX + CSV) for any date range: one row per order — `datum, jedlo, osobne_cislo, priezvisko, meno, stredisko, menu_cislo, menu_nazov, typ, cena, stav, vytvorene, zrusene, vytvoril`. For pivoting and future integrations.

Use a streaming writer (OpenSpout / Laravel Excel) — shared hosting has tight memory/time limits.

## 8. Data model (starting point — adjust if you find a better shape, but keep the snapshot and audit principles)

- `employees` — id, osobne_cislo (unique), first_name, last_name, department, pin_hash, is_active, timestamps
- `users` — staff accounts (email, password, 2FA, roles). Employees may be linked to a user if they're also staff.
- `meal_slots` — id, code (`obed`, `vecera`), name, sort, cutoff_days_before, cutoff_time, is_active
- `price_types` — id, code (`donated`, `extra`), name, per_slot_daily_limit (nullable = setting)
- `prices` — id, price_type_id, amount_cents, valid_from
- `menus` — id, date, meal_slot_id (unique together)
- `menu_items` — id, menu_id, number, name, allergens (string/json), is_active
- `orders` — id, employee_id, date, meal_slot_id, menu_item_id, price_type_id, unit_price_cents (snapshot), status (`active`, `cancelled`), cancelled_at, created_by_user_id (null = self), note, timestamps. **Enforce the donated limit in a DB transaction with row locking** (two quick taps must not create two donated meals).
- `slot_locks` — date, meal_slot_id, locked_by, locked_at (unique date+slot)
- `closed_days` — date, meal_slot_id (nullable = whole day), note
- `settings` — key/value
- `activity_log`

## 9. Quality bar

- **Feature tests (Pest)** for every business rule: donated limit, auto price typing, extra limit, cutoff edge cases (exactly at cutoff, day-before cutoff, DST change days), manual lock/unlock, closed days, price versioning + snapshot, cancellation rules, monthly matrix numbers.
- Seeders with realistic Slovak demo data (≈80 employees, 2 weeks of menus, orders) so every screen can be tested.
- Mobile check on narrow viewports (360 px) and desktop.
- No N+1 queries on the kitchen list or exports.
- `README.md`: local setup, deployment to Websupport step by step, cron line, backups, how to add an admin, how to reset a PIN.
- `docs/navod.md`: short Slovak user manual for kitchen and admin (later also for employees).

## 10. Backups

- Nightly scheduled DB dump (via cron + Laravel scheduler, if Websupport cron can run PHP CLI) kept in a non-public directory with rotation (e.g. 30 days).
- Admin can download the latest backup from the panel.
- Offsite copy is pulled from inside the company by Roman (outside this app) — document the expected file location/naming.

## 11. Milestones

Each milestone ends with: tests green, short demo notes for Roman, commit.

- **M0 — Hosting spike (do first).** Fresh Laravel + Filament deployed on the Websupport subdomain: PHP version ≥ the current Laravel requirement, Composer works (SSH or web console), MySQL connection, `public/` doc root, HTTPS, cron running `schedule:run`. Write down every finding in README. **Stop and report if anything blocks.**
- **M1 — Domain core.** Migrations, models, rule services (ordering, pricing, cutoff/lock evaluation), seeders, full rule test suite. No UI yet.
- **M2 — Employee app.** Login (osobné číslo + PIN, remember-me), one-screen ordering, "Môj mesiac", PWA install.
- **M3 — Admin.** Employees + import + PIN reset + access slips, prices, slots/settings, closed days, menus (grid + import), orders + corrections, audit log.
- **M4 — Kitchen.** Daily overview, print, XLSX, lock/unlock.
- **M5 — Exports.** Monthly matrix (+ split option + summary sheet), flat list XLSX/CSV.
- **M6 — Hardening & handover.** Security review (rate limits, 2FA, headers, authorization on every Filament resource and Livewire action), backups, README, Slovak manual, pilot checklist.

## 12. Not in v1 (but don't block them)

- Attendance integration (validate orders against card attendance).
- RFID card login at a canteen kiosk (employees table should allow adding `card_uid` later).
- Payroll-system import format for the new company software.
- Notifications (e.g. "you haven't ordered for tomorrow").
- Portion limits per menu option, guests/external diners, recurring orders.

## 13. OPEN — ask Roman when you get there

1. PHP version, SSH vs web console, DB credentials, subdomain name (M0).
2. Cutoff times for Obed and Večera (M1).
3. `max_extra_per_slot` — can someone order more than one 6 € meal per slot (e.g. for family)? (M1)
4. Approximate number of employees (affects nothing big, but sizes seeders/print layout).
5. Kitchen's real menu file sample (M3).
6. Whether the monthly export must match his existing workbook's input layout (M5).
7. Go-live date / when the old system switches off (drives pilot timing).
