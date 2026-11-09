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
2. **Price typing:** price types are tried in `sort` order; the first one whose per-slot daily limit isn't used up by the employee's *active* orders wins. Default data: Darované (limit 1) → Extra (limit = setting `max_extra_per_slot`). This gives exactly the brief's rules incl. "cancel the donated one, keep the extra → next order is donated again".
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
- **2026-11-09 — Test environment** is `kbsandbox.arpo.cc` (Roman's Websupport hosting). Production will be the company's Websupport hosting, same platform.
