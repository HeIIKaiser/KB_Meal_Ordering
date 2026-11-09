# Decisions & open questions

Status of every **OPEN** item from the brief (§13) and every design decision that
isn't spelled out in the brief. Update this file when Roman answers.

## Open items

| # | Question | Status | Current value in the app |
|---|---|---|---|
| 1 | PHP version, SSH vs web console, DB credentials, subdomain (M0) | **Answered 2026-11-09** — verified on test hosting `kbsandbox.arpo.cc`, see README → M0 findings | PHP 8.5, MariaDB 11.4, SSH + Composer, symlinked doc root, cron via `deploy/websupport-cron.php` |
| 2 | Cutoff times Obed / Večera | **Waiting** — using brief defaults | Obed same day 09:00, Večera same day 14:00 (editable per slot) |
| 3 | `max_extra_per_slot` | **Waiting** — using brief default | `1` (setting) |
| 4 | Number of employees | Assumed ≈ 80 (demo seeder) | — |
| 5 | Kitchen's real menu file sample | Needed for M3 | — |
| 6 | Monthly export layout matching the existing workbook | Needed for M5 | — |
| 7 | Go-live date | Needed for pilot planning | — |

## Design decisions (M1)

1. **Which price version applies:** the version with the latest `valid_from` **on or before the meal date** (not the ordering date). So a new price "from 1 January" applies to January meals even if they were ordered in December. *The amount is still snapshotted on the order when it is placed* — adding a price version later never changes existing orders. → Confirm with Roman.
2. **Price typing:** price types are tried in `sort` order; the first one whose per-slot daily limit isn't used up by the employee's *active* orders wins. Default data: Darované (limit 1) → Extra (limit = setting `max_extra_per_slot`). This gives exactly the brief's rules incl. "cancel the donated one, keep the extra → next order is donated again".
3. **Extra confirmation is enforced server-side:** `OrderService::place()` refuses to create an Extra order unless the caller passes `acceptExtra: true`, so a double tap can never silently create a 6 € meal.
4. **Concurrency:** every order write runs in a transaction that first locks the employee row (`SELECT … FOR UPDATE`). A unique index `(employee_id, date, meal_slot_id, donated_guard)` additionally makes two active donated orders impossible at the DB level. Proven by `tests/Concurrency` (6 parallel processes, MariaDB).
5. **Manual unlock** only removes a manual lock; it does not reopen a slot whose automatic cutoff has passed. Late changes after cutoff are done by an admin (with reason). → Ask Roman whether the kitchen needs "reopen until HH:MM".
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

## Decisions by Roman

- **2026-11-09 — Staff 2FA is optional.** Users can enable TOTP in their Filament profile; it is not enforced on first login and there are no prompts. (Brief §5 said "email + password + 2FA" — relaxed because most staff can't handle authenticator apps.)
- **2026-11-09 — Deployment from `main`.** The repository will be private with a `main` branch; servers deploy from `main` via a read-only deploy key.
- **2026-11-09 — Test environment** is `kbsandbox.arpo.cc` (Roman's Websupport hosting). Production will be the company's Websupport hosting, same platform.
