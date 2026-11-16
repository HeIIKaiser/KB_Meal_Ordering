# Decisions & open questions

Status of every **OPEN** item from the brief (§13) and every design decision that
isn't spelled out in the brief. Update this file when Roman answers.

## Open items

| # | Question | Status | Current value in the app |
|---|---|---|---|
| 1 | PHP version, SSH vs web console, DB credentials, subdomain (M0) | **Answered 2026-11-09** — verified on test hosting `kbsandbox.arpo.cc`, see README → M0 findings | PHP 8.5, MariaDB 11.4, SSH + Composer, symlinked doc root, cron via `deploy/websupport-cron.php` |
| 2 | Cutoff times Obed / Večera | **Answered 2026-11-09** — keep defaults, Roman adjusts them in the admin (*Nastavenia → Jedlá a uzávierky*) as needed | Obed same day 09:00, Večera same day 14:00 |
| 3 | `max_extra_per_slot` | **Answered 2026-11-09** — essentially unlimited, 6 € each | setting empty = unlimited (default); a cap can still be set in *Nastavenia → Objednávanie* |
| 4 | Number of employees | Assumed ≈ 80 (demo seeder) | — |
| 5 | Kitchen's real menu file sample | **Answered 2026-11-09** — lunch = soup for everyone + 2 main courses, dinner = 1 main course; the kitchen will use an Excel with 4 entries per day | weekly import format `datum, polievka, obed_1, obed_2, vecera` (one row per day), template in the admin |
| 6 | Monthly export layout matching the existing workbook | **Answered 2026-11-09** — the brief's layout is fine, Roman works with the data | brief layout + *Súhrn* sheet (`MonthlyMatrixXlsx`) |
| 7 | Go-live date | Needed for pilot planning | — |

## Design decisions (M1)

1. **Which price version applies:** the version with the latest `valid_from` **on or before the meal date** (not the ordering date). *The amount is still snapshotted on the order when it is placed* — adding a price version later never changes existing orders. Roman (2026-11-09): prices are simply 1,50 € / 6,00 € and practically never change, so this is a corner case; kept as is.
2. **Price typing:** price types are tried in `sort` order; the first one whose per-slot daily limit isn't used up by the employee's *active* orders wins. Default data: Darované (limit 1) → Extra (limit = setting `max_extra_per_slot`). Cancelling the donated order moves the oldest remaining extra in that slot/day to the donated price (changed 2026-11-10, see below).
3. **Extra confirmation is enforced server-side:** `OrderService::place()` refuses to create an Extra order unless the caller passes `acceptExtra: true`, so a double tap can never silently create a 6 € meal.
4. **Concurrency:** every order write runs in a transaction that first locks the employee row (`SELECT … FOR UPDATE`). A unique index `(employee_id, date, meal_slot_id, donated_guard)` additionally makes two active donated orders impossible at the DB level. Proven by `tests/Concurrency` (6 parallel processes, MariaDB).
5. **Manual unlock** only removes a manual lock; it does not reopen a slot whose automatic cutoff has passed. Late orders after the cutoff are entered by an admin (with reason) — **confirmed by Roman 2026-11-09**.
6. **Who may change orders after lock:** only `admin` (brief §4: kitchen = view/print/lock, payroll = read-only). Staff actions outside the ordering window require a reason; all order actions are written to the activity log with the reason.
7. **Switching an option** ("Zmeniť objednávku") updates the existing order's menu item; type and snapshot price stay.
8. **Dates** (`orders.date`, `menus.date`, …) are calendar dates stored as `Y-m-d` and read as local-midnight dates in Europe/Bratislava (`App\Casts\LocalDate`). Timestamps are stored in UTC (`app.timezone = UTC`).
9. **Settings table** has a numeric `id` + unique `key` (the activity log needs numeric subject ids).
10. **Slovak collation** `utf8mb4_slovak_ci` is recommended (`DB_COLLATION`) so alphabetical lists sort Č, Š, Ž, Ch correctly.

## Design decisions (M2)

1. **Login errors are generic** ("Nesprávne osobné číslo alebo PIN.") — also for deactivated accounts, so the login form doesn't reveal which personal numbers exist. A deactivated employee who is still logged in is logged out on the next request with "Váš účet nie je aktívny."
2. **Rate limit:** 5 failed attempts per osobné číslo + IP, then 60 s lockout (`config/jedalen.php`). Other employees on the same Wi-Fi (same IP) are not affected.
3. **Remember-me** is always on: HttpOnly cookie, 400 days (browser maximum). A PIN change or admin PIN reset invalidates the remember token → other devices are logged out.
4. **Tap logic:** tapping an ordered option asks to cancel it (if that option is ordered twice, the extra one is cancelled first). Tapping another option while something is ordered asks "Zmeniť objednávku?" — *switch* changes the donated order; *Pridať extra* is offered only while the extra limit isn't reached.
5. **Desktop (≥ 1024 px)** shows the whole week at once; phones show one day with a week strip and swipe left/right.
6. **Service worker** caches only static assets (`/build`, icons) and an offline page. Pages and Livewire requests always go to the network — no offline ordering, no personal data in caches.
7. **Fonts:** system fonts only (no web-font download; fast on old phones).

## Design decisions (M3)

1. **Permissions:** employees, users, slots, settings, audit log, order corrections = admin only. Kitchen: menus (grid + import), reads orders and closed days. Payroll: reads orders and prices. Enforced by policies / `canAccess()` and again inside `OrderService` / `SlotLockService`.
2. **Nothing is deleted:** employees, staff users, slots and orders are deactivated/cancelled; menu options are deactivated. Only future price versions and closed days can be deleted.
3. **PINs** are 6 random digits (no trivial patterns like 123456). Since 2026-11-10 they are generated automatically for every new employee and are reprintable (see *Decisions by Roman*). A reset logs the employee out on all devices.
4. **Menu changes with orders:** renaming or removing an ordered option lists the affected employees and needs confirmation; their orders stay valid (the kitchen still sees them). Allergen-only changes don't warn.
5. **Imports** preview first and skip rows with errors (each listed with its line number). Menu import upserts by date + slot + number and never removes options; employee import upserts by osobné číslo and only deactivates missing people when explicitly ticked. CSV may use `;`, `,` or tab and UTF-8 or Windows-1250 (Slovak Excel).
6. **Price versions** can only be added for tomorrow or later; a version already in effect is read-only.
7. **Staff can't lock themselves out:** an admin can't remove their own admin role or deactivate themselves.

## Design decisions (M4)

1. **One data source** (`App\Domain\Kitchen\DailyOverview`) feeds the screen, print page, PDF and XLSX, so their numbers can't differ. Two queries regardless of the number of orders.
2. **One row per order:** an employee with a donated and an extra meal appears twice (the extra one marked **EXTRA**), so every served plate has its own checkbox.
3. **Print layout:** A4 portrait, two columns read top-to-bottom, header repeated on every page, totals per option at the bottom; ~55 people fit on one page. Long names/dishes are shortened on paper (full text on screen and in Excel).
4. **PDF** is generated with dompdf (pure PHP, works on shared hosting; DejaVu Sans for Slovak diacritics). Browser print is the primary path; PDF is for sending/archiving.
5. **Lock button** is shown only before the cutoff (afterwards the slot is closed anyway); **Unlock** is shown whenever a manual lock exists. Payroll can view and print but not lock.
6. The kitchen screen and dashboard **poll every 60 s** (cheap on shared hosting) while the selected day is today or later.

## Design decisions (M5)

1. **Who is in the monthly export:** every employee with at least one active order in the month (also people deactivated since — they ate that month). Option *Aj aktívni zamestnanci bez objednávok* adds currently active employees with zero rows (useful for matching against attendance).
2. **Only active orders** are counted; € sums use each order's **snapshot price** (`unit_price_cents`), so later price changes or corrections of the price list never change past months.
3. **Matrix layout** (sheet *Mesiac*): per employee a header row "Priezvisko Meno (osobné č. …) | mesiac | Po Ut St …", one row per price type (or per type × slot with *Rozdeliť na obed / večeru*), a *Spolu* row; day cells are counts (lunch + dinner donated = 2), weekends shaded, frozen panes, prints A4 landscape with all days on one page width.
4. **Summary sheet** (*Súhrn*): Osobné číslo (as text, keeps leading zeros), Priezvisko, Meno, Stredisko, ks and € per price type, Suma €, total row. Money cells are numbers formatted `0,00 €` (not text), so they can be summed/imported.
5. **Flat list** includes cancelled orders (column `stav`: aktivna / zrusena) so it can be reconciled; `typ` is `darovane` / `extra`; times are local (Europe/Bratislava). CSV = UTF-8 with BOM, `;` separator, decimal comma (opens correctly in Slovak Excel). Max. range 1 year.
6. **Access:** exports are for payroll and admin (kitchen has its own daily export). Every export download is written to the audit log (it contains personal data of all employees).
7. Downloads are plain GET routes that stream a temp file — not through Livewire — to stay within shared-hosting memory limits (demo month: 0.2 s, ~40 MB peak).

## Design decisions (M6)

1. **Backups in pure PHP** (`DatabaseBackup`), not `mysqldump`: works on any shared hosting without `exec`. One statement per line, so the file also restores with the `mysql` CLI or phpMyAdmin (tested with the real `mysql` client). Written as `.part` and renamed, ends with `-- END OF BACKUP`; restore refuses incomplete files. Sessions/cache are backed up without rows.
2. **Scheduled tasks run in-process** (`Schedule::call`), so cron needs no `proc_open`. The backup runs at 02:30 Europe/Bratislava with 30-day rotation (7 newest always kept), and also before every `deploy/update.sh`.
3. **Second login limit per osobné číslo** (10 failures / 15 min from any IP) against distributed PIN guessing, on top of the per-IP limit.
4. **Plain staff routes are guarded at the route** (`staff:<roles>` middleware), and a test fails if any route is unauthenticated.
5. **CSP** restricts everything to our own origin; `unsafe-inline`/`unsafe-eval` stay because Livewire/Alpine need them. HSTS only in production. Filament's third-party avatar service is replaced by local initials, so no staff names leave the server.
6. **Laravel's private-storage serving route is disabled** (`filesystems.disks.local.serve = false`): backups and uploads have no URL.
7. **Trusted proxies off by default** (`TRUSTED_PROXIES`), so `X-Forwarded-For` can't be spoofed to dodge rate limits.

## Design decisions — ticket mode (2026-11-12)

Spec from Roman (ticket mode / lístky as an alternative pricing rule per employee group). Daily mode is unchanged and
the default; nothing changes until an admin creates a group in ticket mode.

1. **Data model.**
   - `meal_groups` (name, default tickets, active);
   - `meal_group_modes` (group, mode, effective_from): history, the latest row on or before a date applies;
   - `employee_group_memberships` (employee, group or NULL, effective_from): history;
   - `ticket_month_counts` (group, month);
   - `ticket_overrides` (employee, month, source manual | import);
   - `closed_months`;
   - `orders.pricing_mode`.

   Months are stored as `YYYY-MM`. Existing orders get `daily`.
2. **Mode of a meal** = the mode of the employee's group on the meal date; no group or no mode row = daily. It is stored
   on the order (`pricing_mode`) when the order is created and rewritten when repriced.
3. **Allocation** = individual override → the group's count for the month → the group's default, so tickets exist for every
   month (also the next one) without a cron job. If an employee changes group mid-month, the count of the group they
   are in on the last ticket-mode day of the month applies. *Lístky na mesiac* prefills from last month's count; until
   saved, the default applies.
4. **Repricing rule (`app/Domain/Tickets/Repricer.php`)**, per employee and month:
   - active ticket-mode orders sorted by date, slot order, id; the first N are Zamestnanecká strava, the rest Dokupovaná,
     priced by `PriceResolver` for the meal date;
   - for ticket-mode orders the stored price is the current state, not a snapshot;
   - daily-mode orders keep their snapshot, except slot-days whose orders change mode (group mode switch / group change),
     which are re-typed by the daily rule (oldest order donated, the rest extra);
   - only changed orders are written, each with one `repriced` audit entry (old/new mode, type, price, cause);
   - it runs inside the caller's transaction with the employee row locked (place, cancel, allocation/override, month
     import, mode switch, group change).
5. **Quote = marginal price.** Ticket mode: 1,50 € while active ticket orders in the month < allocation, else 6,00 € with
   the extra confirmation „Lístky na <mesiac> sú minuté. Toto jedlo bude za 6,00 €.“ No per-day limit; `max_extra_per_slot`
   does not apply. Ordering an earlier date after the tickets are used up costs a ticket from the latest meal, and the
   employee is told which meal now costs 6 €.
6. **DB guard.** `donated_guard` (unique per employee/date/slot) is set only for daily-mode orders. Parallel ticket orders are
   serialised by the employee row lock (`tests/Concurrency/SimultaneousTicketOrdersTest.php`).
7. **Previews run the real change in a rolled-back transaction** (`Tickets\Simulation`), so preview numbers always equal
   the result. Used for the mode switch, group change, monthly counts and the month-end import.
8. **Group change dates.**
   - The employee form and the import apply a change from the 1st of next month by default.
   - A new employee joins from the 1st of the current month (no orders yet).
   - An earlier date is allowed: the form shows what will be repriced, and the notification after saving says it again.
   - A new assignment replaces later planned ones.
9. **Admin price-type correction is refused for ticket-mode orders** (the rule decides); change the allocation instead.
10. **Closed months** block placing, switching, cancelling, price-type changes, counts/overrides, mode switches and group
    changes with a date in the month, for everybody and both modes. Kitchen lock/unlock is unaffected. Reopen = admin
    with a mandatory reason (audited). Exports of a closed month say „Mesiac uzavretý – konečné ceny.“ instead of the
    estimate disclaimer.
11. **Month-end import.** `osobne_cislo ; listky` (other columns are ignored). Accepted counts are 0–999; `19,0` / `19.0`
    from Excel are accepted. Saved as overrides with source `import`. Employees in ticket mode who are missing from the
    file keep their allocation.
12. **Exports.**
    - Súhrn: new columns Skupina, Režim (`denny` / `listky` / `zmiesany`), Lístky pridelené / použité / prepadnuté are
      appended after *Suma €*, so existing column positions stay the same for payroll imports.
    - použité = min(ticket meals, allocation); prepadnuté = max(0, allocation − ticket meals); only the ticket-mode part
      of a mixed month counts.
    - Flat list: column `rezim` appended last.
    - Matrix: a "Lístky" line under each ticket-mode employee.
13. **Navigation.** *Ľudia → Skupiny stravníkov* (admin edits, payroll reads); *Objednávky → Lístky na mesiac* (payroll
    reads, admin edits, close/reopen); *Objednávky → Konečné lístky (import)* (admin).

## Design decisions — attendance from WebVisitor (2026-11-16)

Spec: `docs/ATTENDANCE.md` (v2). Roman approved the plan, including answers 1–4 below.

1. **Data model.**
   - `attendance_days`: mirror of the synced days, no names, replaced month by month.
   - `attendance_results`: per employee and month: estimate and final tickets / mandatory meals, plus flags.
   - `mandatory_meals`: automatic charges per day, source `estimate` | `final`. They are not orders, so the kitchen
     never sees them.
   - `attendance_syncs`: log of data and status calls, unmatched numbers, late changes.
   - New columns: `employees.vema_cislo`, `employees.max_meals_per_day`, `meal_groups.mandatory_per_day`.
   - `PricingMode::Mandatory`. The mode columns were widened to 16 characters because "mandatory" is 9.
2. **Matching.**
   - WebVisitor `PERSONALNUMBER` is an int, so the app matches osobné číslo without leading zeros ("003" = 3).
   - Two app employees with the same normalised number are a conflict: neither is updated, and the number is listed as
     unmatched.
   - Duplicate entries of one person in a payload are merged like two employments.
3. **Day rule.** Worked time ≤ 4:00 → 0 meals, more than 4:00 → 1, 10:00 or more → 2, using `totalWork` („Celkom“).
   The thresholds are settings. The employee cap is applied after the rule, also to mandatory N.
   - **Estimate:** today counts as a future day (its data is still incomplete), so it uses the plan.
   - **Final:** a planned past day with nothing filled in is 0 and flagged.
4. **Mandatory mode.**
   - N meals per day with at least one meal by the rule, priced at the Zamestnanecká strava price of that date.
   - App orders: `pricing_mode = mandatory`, always Dokupovaná strava with the extra confirmation. Admin price-type
     corrections are refused.
5. **Allocation chain:** manual override → `attendance_final` → import override → `attendance_estimate` → group month
   count → group default. *Lístky na mesiac* shows the source. After finalisation, a *Konečné lístky* import no longer
   applies to that month; manual overrides still do (Roman, answer 4).
6. **Sync API** (`routes/api.php`):
   - bearer token stored as SHA-256 and shown once; rotating it invalidates the old one at once;
   - HTTPS required in production, 30 requests/min, optional IP/CIDR allowlist, gzip accepted, 30 MB limit;
   - one month per request, idempotent replace;
   - `pending` is computed from the request time and the months received since, so there is no flag to clear.
7. **Recompute.**
   - An open month is recomputed per affected employee in the usual transaction and employee lock, then repriced.
     Affected = everyone in ticket/mandatory mode that month, plus anyone matched in the data, plus anyone with a result.
   - A nightly app job (03:30) refreshes the estimate of open months from the mirror, because the past/future boundary
     moves daily even when the bridge is down.
   - A finalised month that is reopened and synced again keeps its final values until it is finalised again.
8. **Closed month.** The mirror is still updated, but results, charges and prices are not changed. The differences are
   computed exactly by simulating a finalisation in a rolled-back transaction, stored on the sync log and shown as
   „Dochádzka sa zmenila po uzavretí“.
9. **Finalisation of someone without any attendance** stores nothing final, so the fallbacks (import, group count)
   keep applying. The preview flags them „bez dochádzky“.
10. **Bridge.**
    - Standalone PHP 8.3 CLI, no Composer, in Docker on DOCKER01.
    - Nightly at 02:00 and once at container start (previous + current month); polls `pending` every 5 minutes.
    - Schema check before every sync. The shift-name column of `vis_workinterval` is assumed to be `NAME`
      (configurable with `WV_SHIFT_NAME_COLUMN`).
    - `planned_seconds` = PLANEND − PLANSTART when FUND > 0 (FUND if the plan times are missing). Employments of a
      person are summed per date. The month is locked only if all of the person's employments are locked.
    - **`vis_monthatt.PERIOD`:** both `YYYYMM` and a date are accepted (matched by digits), and the bridge logs which
      format it found. To be made strict once seen on real data (Roman, answer 1).
11. **Vema number (Roman, answer 2).** Digits only, unique, stored as text, exported as a number without leading zeros.
    A one-off action *Doplniť Vema čísla* fills it from osobné číslo (003 → 3) where it is empty. It never overwrites,
    and skips non-numeric or already used values.
12. **Exports.**
    - Súhrn: columns *Povinná strava ks / €*, *Zdroj lístkov* and *Vema číslo* are appended at the end, so existing
      positions stay the same. **Suma € now includes the mandatory charges.**
    - Matrix: a *Povinná strava* row per day; the employee total includes it.
    - Flat list: mandatory charges are **one row per meal** with `typ = povinna`, `rezim = povinna` (Roman, answer 3),
      appended after the orders.
13. **Payroll export — Vema format is PROVISIONAL.**
    - Formats sit behind `App\Domain\Exports\Payroll\PayrollFormat` and are registered in `PayrollFormats`.
    - `VemaFormat` is labelled **„Formát Vema – predbežný“** in the UI.
    - Columns `vema_cislo, priezvisko, meno, zamestnanecka_ks, dokupovana_ks, povinna_ks, suma_eur`; XLSX, one row
      per employee with an amount, sorted by Vema number.
    - Its whole layout is `VemaFormat::columns()` and `write()`. When Roman sends the sample (today payroll imports the
      „ZS – podklady pre vyúčtovanie“ XLSX exported from Pramen, with personal numbers as plain numbers, 003 → 3),
      only that class and its test change.
    - Missing Vema numbers block the export with a list. An open month needs an explicit „odhad“ confirmation.

## Design decisions — temps and self-employed (2026-11-17)

Spec: `docs/CATEGORIES.md`. Roman approved the plan, including answers 1–5 below.

1. **Group settings.** `estimate_source` (`plan` | `orders`), `settlement` (`payroll` | `invoice`) and `max_per_slot`
   (fixed-mode cap per meal and day). The defaults keep the previous behaviour.
2. **Temps (`estimate_source = orders`).**
   - New allocation source `orders_estimate`: tickets = the person's active ticket-mode orders in the month. It ranks
     after manual, `attendance_final` and import overrides, and replaces the plan-based estimate and the group counts.
   - Every order is quoted and priced at 1,50 € while open (Roman, answer 5). One constant,
     `TicketAllocation::ORDERS_ESTIMATE_AT_EMPLOYEE_PRICE`, switches to the strict variant.
   - At finalisation attendance decides (`attendance_final`), and the latest orders above it become 6 €. The preview
     flags it („brigádnik: N jedál → 6 €“).
   - A temp with no attendance at all keeps the order-based count and is flagged „bez dochádzky“ (Roman, answer 4).
3. **Self-employed: `PricingMode::Fixed`.**
   - Price type `fixed` „Pevná cena“, excluded from the price list, the daily/ticket typing and price-type corrections
     (`PriceType::standard()`).
   - Prices live in `fixed_prices`: group or person, all meals or one meal (Roman, answer 1), `valid_from` = meal date.
   - Lookup order: person + meal → person → group + meal → group, latest valid version each. No price → the order is
     refused.
   - The price is snapshotted on the order. No confirmation dialog; the optional cap is per meal and day. Never
     repriced, except orders that move into fixed mode through a group mode switch.
   - Excluded from the attendance roster (no warnings, no finalisation rows).
   - A new price may start today or in the past (so a new SZČO can order at once); existing orders keep their
     snapshot. Versions already in effect can't be edited, as with *Ceny*.
4. **Settlement for a month** = the group on the last day of the month (Roman, answer 2).
   - Vema export: payroll only.
   - New provisional export **„Fakturácia – predbežný formát“** behind `InvoiceFormat`; the layout is
     `BillingXlsxFormat`.
   - *Súhrn* sheet: billing details, `obedy_ks`, `vecere_ks`, `ostatne_ks` (other meals + mandatory meals, if any;
     Roman, answer 3), unit prices, `suma_eur`. *Detail* sheet: one row per meal.
   - Totals equal the monthly matrix.
5. **Exports.** Súhrn appends *Pevná cena ks / €* and *Vysporiadanie* (`mzdy` / `faktura`); *Suma €* includes fixed
   orders. The flat list appends `vysporiadanie` and uses `typ = pevna`. The matrix shows a *Pevná cena* row (only
   when used) and „– faktúra“ after the name.
6. **Employees.**
   - Billing fields are optional; IČO is 6–8 digits and DIČ 10 digits. They show on the form for invoiced groups (or
     when already filled) and are optional import columns.
   - Osobné číslo stays editable (unique, audited). Orders, history and attendance pairing follow the employee id.
   - The import warns (doesn't block) when a new number has an existing person's name.
7. **Found along the way.**
   - Filament's `date()` column showed `valid_from` one day early (UTC conversion of a local date), also on the
     existing *Ceny* page. Both now pass the local time zone; a regression test covers it.
   - Headings use Slovak sentence case (`SentenceCaseLabels`), e.g. „Pevné ceny“, not „Pevné Ceny“.

## Design decisions — daisyUI on the employee side (2026-11-17)

Roman approved the plan, with answers 1–2 below.

1. **Scope.**
   - daisyUI 5 (devDependency) is enabled with `@plugin "daisyui"` in `resources/css/app.css`. Only
     `layouts/employee.blade.php` loads that file, so the Filament admin (`theme.css`, unchanged), kitchen/print views,
     access slips and error pages are unaffected.
   - No daisyUI JS, no CDN, no web fonts; the CSP is unchanged.
   - `include:` limits daisyUI to the components the employee screens use. Without it, Tailwind's scan of all views
     pulled in unused components through common words like "menu" or "table".
2. **One light theme `kbz`** in `app.css`, colours in hex (not oklch) for old phones. Light tints are pre-mixed hex
   tokens (`primary-soft`, `success-soft`, …) instead of `bg-primary/10` or daisyUI `*-soft`: without `color-mix()`
   (Chrome < 111, Safari < 16.2) those fall back to the full colour behind dark text.
3. **WCAG AA, all measured:**

   | Pair | Ratio |
   |---|---|
   | text on card / page | 17.5 / 16.0 |
   | secondary text (stone-700) | 10.3 / 9.4 |
   | white on primary #047857 (header, buttons, „Objednané“) | 5.5 |
   | white on #065f46 (active nav, success message) | 7.7 |
   | black on accent #fbbf24 (6 € buttons, „Lístky minuté“) | 12.6 |
   | black on warning #f59e0b („Dokupované“) | 9.8 |
   | white on error #b91c1c | 6.5 |
   | primary links on white / page | 5.5 / 5.0 |
   | „otvorené do …“ badge | 4.9 |
   | „uzamknuté“ badge | 9.0 |
   | error message | 5.7 |
   | today's tab | 6.2 |
   | ordered card | 15.2 |
   | soup box | 16.1 |

   Small white text with opacity on the green *Môj mesiac* card (< 4.5) is now at full opacity. daisyUI dims inactive
   tabs to 50 %; the day buttons keep full-contrast text.
4. **Components.**
   - `btn` (all tap targets ≥ 48 px; measured: inputs 56, login button 64, dialog buttons 56, week arrows 48, day tabs
     69, bottom nav 68).
   - `card`; `badge`; `tabs tabs-box` for the week strip (same Alpine bindings and `aria-selected`); `stat` for the
     ticket bar (whole sentence in one line, wording unchanged — answer 2).
   - `toast` + `alert` for feedback; `fieldset` / `input` for login and PIN change.
   - Confirmations use a native `<dialog class="modal">`, rendered when `$dialog` is set and opened by Alpine
     (`x-init="$el.showModal()"`). Esc calls `closeDialog` as before; clicking outside does nothing (as before).
   - The desktop subgrid (`.week-grid` / `.week-day` / `.week-slot`) is unchanged; the slot card stays a block on phones.
5. **Offline page** (`public/offline.html`, cached by the service worker, can't load the hashed CSS): the same look in
   inline CSS with the theme colours (answer 1). The service-worker cache was bumped to `jedalen-v3`.
6. **Size:** `app.css` 29.1 → 58.8 KB raw, **6.4 → 11.2 KB gzip**. The admin theme is unchanged (71.4 KB gzip).
7. **Known limit on old browsers.** daisyUI's own hover/pressed shading, input borders and the disabled-button colour
   use `color-mix()`; their fallbacks are the stronger full colour. A button disabled during a Livewire request
   (a fraction of a second) can look dark there.

## Decisions by Roman

- **2026-11-09 — Staff 2FA is optional.** Users can enable TOTP in their Filament profile; it is not enforced on first login and there are no prompts. (Brief §5 said "email + password + 2FA" — relaxed because most staff can't handle authenticator apps.)
- **2026-11-09 — Deployment from `main`.** The repository will be private with a `main` branch; servers deploy from `main` via a read-only deploy key.
- **2026-11-09 — Pricing rule confirmed:** per employee and day the first lunch and the first dinner are *Darované* 1,50 €, everything beyond that is *Extra* 6,00 € — exactly what `OrderService` does. Extras are unlimited (`max_extra_per_slot` empty).
- **2026-11-09 — Shift length is not known to the app:** employees on 12-hour shifts are entitled to 2 donated meals a day, on 8-hour shifts to 1. The app can't know shifts (attendance is not connected), so it always prices the first lunch and first dinner at 1,50 € and payroll corrects it against attendance. Therefore every monthly rundown (employee *Môj mesiac*, monthly export sheets *Mesiac* and *Súhrn*, the export page) shows: **„Cena stravy je odhadom a po porovnaní s dochádzkou sa môže zmeniť.“** (`App\Support\Money::ESTIMATE_DISCLAIMER`).
- **2026-11-09 — Cutoff defaults kept** (Obed 09:00, Večera 14:00, same day); Roman tunes them in the admin.
- **2026-11-09 — Late orders after the cutoff go through an admin** (*Všetky objednávky → Objednať za zamestnanca*); no "reopen" button for the kitchen.
- **2026-11-09 — Menu shape:** lunch = **soup of the day** (served to everyone, not ordered, not priced) + **2 main courses**; dinner = **1 main course**. Implemented as `menus.soup_name/soup_allergens` on slots with `has_soup` (Obed); the soup is shown on the employee's lunch card, the kitchen screen, print, PDF and Excel. The weekly grid offers soup + 2 rows for lunch and 1 row for dinner (more can still be added).
- **2026-11-09 — Kitchen Excel = one row per day** with 4 entries: `datum | polievka | obed_1 | obed_2 | vecera`. Allergens either in brackets after the dish (`Guláš, knedľa (1,3,7)`, also `A: 1,3,7` or a bare trailing `1,3,7`) or in optional `<column>_alergeny` columns. Empty cells change nothing. The old long format (`datum, jedlo, cislo_menu, nazov, alergeny`) is still accepted and detected automatically.
- **2026-11-10 — Employee logins: easy creation, import, reprintable slips.** Every new employee (form, import, CLI) gets a PIN automatically; access slips print the **current** PIN individually, in batch and "all not yet printed" without changing it. To allow reprinting, app-generated PINs are stored **encrypted** (`APP_KEY`) besides the hash. A PIN the employee changes themselves is **not** stored readable; their slip shows a note instead. Staff accounts (`/admin`) are unchanged: email + password, no import/slips.
- **2026-11-10 — Fix: import "Uložiť" did nothing.** Livewire 4 maps `wire:click="commit"` to its built-in `$commit` (re-render only), so the save method of both import pages was never called. Renamed to `saveImport`; `tests/Feature/Security/LivewireNamesTest.php` now fails if any component method or `wire:click` uses a Livewire magic name. At the same time, app-generated PINs are hashed at the first login instead of during import/reset: an 82-person import took 16 s (300 people ≈ 1 min, over the hosting time limit), now 0.15 s.
- **2026-11-10 — Wording: „Zamestnanecká strava“ / „Dokupovaná strava“.** The 1,50 € meal is the mandatory employer contribution, not a gift, so the earlier label „Darované“ was wrong. Display names of the price types are now *Zamestnanecká strava* (code `donated`) and *Dokupovaná strava* (code `extra`). A migration renames them in existing databases. UI, prints, exports (flat list `typ`: `zamestnanecka` / `dokupovana`) and docs follow. Internal codes are unchanged.
- **2026-11-10 — Weekly menu print + A4 login sheet + aligned desktop week.** *Jedálny lístok → Tlačiť týždeň / PDF*: A4 landscape Monday–Sunday with soup, options, dinner, allergens and legend, for the board. Access slips are now **one A4 page per employee** with large credentials, QR and full instructions (live cutoffs and prices); the 8-per-page cards remain as *Malé lístky*. On desktop the week view uses CSS subgrid, so Obed / soup / Menu 1 / Menu 2 / Večera line up across all days.
- **2026-11-09 — Test environment** is `kbsandbox.arpo.cc` (Roman's Websupport hosting). Production will be the company's Websupport hosting, same platform.

- **2026-11-10 — Reusable for other organisations** (Roman). The organisation name is no longer in the code: it is the
  setting *Názov firmy* (Nastavenia → Objednávanie), falling back to `APP_COMPANY` in `.env`. Existing installations
  (with employees) get "KB SPA" preset by a migration, so updates need no manual change. The PWA manifest is now
  served by Laravel (`/manifest.webmanifest`) so the installed app's name follows the setting.
- **2026-11-10 — Public, MIT** (Roman). Repository licensed MIT; demo logins removed (seeder + a migration that disables
  them on existing installations).
- **2026-11-10 — Cancelling re-types the remaining order** (Roman, after alpha testing). Order Menu 1 (1,50 €) + Menu 2 (6,00 €),
  cancel Menu 1 → Menu 2 becomes Zamestnanecká strava at 1,50 € (oldest extra first; audited as `price_type_changed`;
  the employee sees it in the confirmation message). Same for admin cancellations. Cancelling an extra changes nothing.
- **2026-11-12 — Ticket mode (lístky)** (Roman's spec). Per employee group: monthly tickets at the employee price, no
  daily limits, the rest at 6 €. The month-end import of final counts from attendance is followed by closing the month.
  See "Design decisions — ticket mode" above.
- **2026-11-13 — App name and icon are neutral** (Roman). The installed app is called by the setting *Názov aplikácie*
  (fallback `APP_NAME`; existing installations get „KBZ“ via a migration) and the icon is a neutral „people“
  pictogram instead of plate + cutlery, so the app can grow beyond meal ordering. The name is also used in the app header
  and in the instructions on the access sheets. Already installed icons update when the phone refreshes the manifest
  or when the app is added to the home screen again; the service-worker cache version was bumped.
- **2026-11-16 — Attendance from WebVisitor, mandatory meals, Vema export** (Roman's spec `docs/ATTENDANCE.md` v2,
  plan approved with answers 1–4 as proposed). The Vema format stays provisional until the sample file arrives; see
  "Design decisions — attendance" above.
- **2026-11-17 — Temps (estimate by orders) and self-employed (fixed price, invoice)** (Roman's spec
  `docs/CATEGORIES.md`, plan approved with answers 1–5 as proposed). The Fakturácia format stays provisional until
  accounting says what they need.
- **2026-11-17 — SZČO in mandatory mode; attendance number** (Roman). Pricing mode and settlement are independent, so
  „SZČO – kuchyňa“ = *Povinná strava* + *faktúra*. If the person or group has a **fixed price**, automatic mandatory
  meals are charged at it (priced like a lunch: lunch price → all-meals price), and app orders in mandatory mode get the
  fixed price without the 6 € dialog; otherwise 1,50 € / 6,00 € as before. Orders already in mandatory mode keep their
  snapshot; only orders coming from another mode are priced. New optional employee field `attendance_cislo` („Číslo
  v dochádzke“, digits, unique; import column `cislo_dochadzka`): WebVisitor data is paired by it when set, otherwise by
  osobné číslo. An attendance number equal to another person's osobné číslo is a conflict (neither is updated).
- **2026-11-17 — daisyUI for the employee side** (Roman, plan approved with answers 1–2). Admin, kitchen/print and
  access slips unchanged. See "Design decisions — daisyUI on the employee side".

