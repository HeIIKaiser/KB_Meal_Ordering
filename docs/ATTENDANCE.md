# Feature: Meal entitlements from live attendance (WebVisitor) + Vema export

> For Claude Code. Read this whole file, `docs/DECISIONS.md` and the ticket-mode code (`app/Domain/Tickets`) first.
> **Start by posting an implementation plan** (data model, sync API, bridge, how it plugs into `TicketAllocation` / `Repricer`, new pages) and wait for Roman's OK before writing code.
> Record every decision you make that isn't spelled out here in `docs/DECISIONS.md`.
> *Version 2: replaces the earlier "upload the attendance XLSX" design. The data source is now verified on the real system (see §3).*

## 1. Goal

Monthly meal administration should run by itself:

- **During the month:** every employee's tickets (or mandatory meals) follow their **real shift plan and real attendance**, synced every night from the attendance system. Nobody types monthly counts or uploads files.
- **After the 10th of the next month** (when department heads have finished their corrections): Roman presses **„Finalizovať"**, reviews the differences, closes the month and downloads the **Vema export**.

Existing manual tools (group month counts, individual overrides, the „Konečné lístky" import) stay as fallbacks.

## 2. Groups and pricing modes

Extend `PricingMode` with a third mode, **`mandatory`** (povinná strava).

| | Tickets (8 h, 12 h, …) | Mandatory |
|---|---|---|
| Pricing mode | `tickets` (existing) | `mandatory` (new) |
| Ordering in the app | tickets at 1,50 €, overflow 6 € (existing) | **always 6 €** (take-home food only), no tickets, no daily limit |
| Charged automatically | — | **N meals per worked day at the employee price (1,50 €)**, eaten or not. N is a group setting, default **2** |

**Mandatory mode** is for kitchen workers and similar staff whose eating can't be checked.
- They don't order those meals and the kitchen list doesn't show them.
- Days off are not charged.
- Their take-home orders appear in the kitchen list as normal.
- The order screen shows: „Povinná strava sa účtuje automaticky podľa dochádzky."
- Store the automatic charges **per day** (e.g. `mandatory_meals: employee_id, date, count, unit_price_cents, source`), so exports can show them in day columns.

**Per-employee settings** (on the employee form; optional columns in the employee import):
- **Max meals per day** (empty = no cap; e.g. 1 for people charged lunch only, „Len obedy účtovať"). It applies everywhere: estimates, actuals, mandatory charges.
- **Vema number `vema_cislo`** (§7).

## 3. Data source: WebVisitor database (verified 2026-11-16)

WebVisitor (APIS webBox) runs on POLANA (`192.168.0.3`): a PHP/Symfony app on WAMP, **MySQL 8.0.18 on port 3308**, database **`webbox`**. Roman created a read-only user **`meals_ro`**, allowed **only from DOCKER01 (`192.168.0.66`)**, with `SELECT` on exactly these tables:

| Table | Use |
|---|---|
| `sys_employment` | `PK_`, `PERSONALNUMBER` (int, = osobné číslo without leading zeros), `CODE`, `DATEBEGIN`, `DATEEND`. One person can have several employment rows. |
| `vis_attdaypart` | **one row per employment per day**, pre-generated for the whole year: `DATE` (`YYYYMMDD`), `FUND` (seconds; **> 0 = planned shift**, 0 = day off), `PLANSTART`/`PLANEND` (Unix timestamps of the planned shift), `STATUS`, `VIS_WORKINTERVAL_PK_`, `SYS_EMPLOYMENT_PK_` |
| `vis_daycumulative` | daily totals per `VIS_ATTDAYPART_PK_`: `TYPE` + `TYPE_PK_` + `VALUE` (seconds). Relevant: **`totalWork`** (= „Celkom" in the UI); `break` with `TYPE_PK_` = absence code from `sys_kodd` |
| `sys_kodd` | absence codes: 1 Obed (lunch break, **not** an absence), 2 Dovolenka, 3 Lekár, 6 PN, 10 Sviatok, 11 OČR, 12 MD, 14/16 Náhradné voľno, 15 Dovolenka ½ dňa, 17 Dovolenka minuloročná, … |
| `vis_workinterval` | name of the planned shift (e.g. `Kuch 12H`, `0H`), `START`, `END` |
| `vis_monthatt` | per employment and `PERIOD`: `LOCK`, `LOCKTYPE`, `LOCK_DATETIME` (attendance month locked by the department). Note: `LOCK` is a reserved word, so backtick it. |

Verified against the WebVisitor UI for one employee (October 2026): `totalWork` 51 720 s = Celkom 14:22, `acceptedWork` = Uznané 13:22 (after the automatic lunch deduction), break type 1 = OB. **Use `totalWork` (Celkom), never `acceptedWork`.** The automatic lunch deduction must not affect the meal count (Roman's decision).

**Never write to this database.** Treat its schema as undocumented and possibly changed by vendor updates (§4.4).

## 4. Sync: bridge on DOCKER01 → app

The app runs on Websupport hosting and **must not connect into the company network**. A small **bridge** inside the network pushes data **out** to the app.

### 4.1 Bridge (new, in this repo under `bridge/`)
- A PHP 8 CLI script with PDO MySQL (same language as the app), packaged as a Docker image with a `docker-compose.yml`. Config via `.env`: DB host/port/user/password, app URL, sync token, schedule.
- **Every night (default 02:00)**, it reads **the current and the previous month completely** and sends them to the app. Late corrections by department heads are therefore always picked up.
- **Every 5 minutes** it calls `GET /api/attendance-sync/pending`. If an admin pressed „Synchronizovať teraz" in the app, it syncs immediately. This is the only way to trigger it from the app, because the app can't reach the network.
- Sends **only** what is needed, with no names: personal number, dates and numbers.
- Logs to stdout (Docker logs). It reports failures to the app (`POST /api/attendance-sync/status`) so they're visible in the admin.

### 4.2 Data sent per month
For each `PERSONALNUMBER` and day (rows of all its employments merged by date: sum `totalWork`, planned = any employment planned):
```json
{ "month": "2026-10", "generated_at": "…", "employees": [
  { "osobne_cislo": "29", "month_locked": true, "days": [
    { "date": "2026-10-01", "planned_seconds": 43200, "plan_name": "Kuch 12H",
      "total_seconds": 51720, "absences": [], "status": 1 }, … ] } ] }
```
`planned_seconds` = `PLANEND − PLANSTART` when `FUND > 0`, else 0. `absences` = `sys_kodd` codes from `break` rows, **excluding code 1 (Obed)**.

### 4.3 App endpoint
- `POST /api/attendance-sync` with a bearer token. Store the token **hashed**, generate/rotate it in the admin, require HTTPS, rate-limit it, gzip allowed. An optional IP allowlist setting.
- **Idempotent:** the payload **replaces** the stored attendance for that month. Store it in e.g. `attendance_days (osobne_cislo, date, planned_seconds, plan_name, total_seconds, absences json, status, synced_at)`.
- After storing, recompute that month's entitlements (§5) and reprice affected employees (existing `Repricer`, in the usual transaction + employee lock), **unless the month is closed** (§6.3).
- Personal numbers not found in the app are listed on an admin page („Nepárované z dochádzky"). App employees in ticket or mandatory groups who are missing from attendance are listed as a warning. Nothing is silently dropped.

### 4.4 Robustness
- The bridge checks at startup that the expected tables and columns exist. If not, it reports the error and sends nothing.
- The admin dashboard shows the **last successful sync** and warns if it is older than 36 h.
- **Fallback:** if the bridge is down, the existing manual tools (overrides, „Konečné lístky" import) still work.

## 5. Meals per day (the rule)

From `total_seconds` (Celkom) on a day:

| Celkom on the day | Meals |
|---|---|
| 0, or only absences | 0 |
| more than **4:00** and less than **10:00** | 1 |
| **10:00** or more | 2 |

Both thresholds are settings. Apply the employee's max meals per day after this.

**Planned meals** for a day = the same table applied to `planned_seconds` (a 12 h shift → 2, an 8 h shift → 1, a day off → 0).

### 5.1 While the month is open (estimate shown to employees)
For each day:
- **future day** → planned meals
- **past day with work or an absence code** → actual meals
- **past planned day with no work and no absence** (not filled in yet, e.g. 29.10. in the test data) → **planned meals**, so missing data never lowers anyone's tickets mid-month
- **unplanned day with work** (extra shift) → actual meals

Tickets for the month = the sum of these (allocation source `attendance_estimate`). For mandatory groups, mandatory meals = N × days with ≥ 1 meal by the same per-day logic.

### 5.2 At finalisation (§6)
Only actual data counts. A planned day with nothing filled in = 0, and the preview flags it so Roman can check with the department.

### 5.3 Allocation chain
Extend `TicketAllocation::allocation()`. The first available value wins:
1. manual individual override (`source = manual`)
2. **final attendance result** (`source = attendance_final`)
3. Chonker-style import (`source = import`)
4. **live attendance estimate** (§5.1)
5. group count for the month (existing)
6. `default_tickets`

„Lístky na mesiac" shows the source for every employee.

## 6. Month end

### 6.1 Status page „Dochádzka"
Per month: the last sync, „uzamknuté u X z Y zamestnancov" (from `month_locked`), the list of unfilled planned days, and unmatched personal numbers.

### 6.2 „Finalizovať mesiac" (admin; normally after the 10th of the next month)
1. Offer „Synchronizovať teraz" first, and wait for a fresh sync (or allow continuing with the last one, with a warning).
2. **Preview:** only employees whose numbers change from the estimate. Show the estimate → final tickets or mandatory meals, meals used, meals changing price, € before → after, and flags (unfilled planned days, attendance month not locked, manual override kept). A toggle shows everyone.
3. „Uložiť" stores the `attendance_final` results and mandatory charges, reprices, and then offers „Uzavrieť mesiac" (existing).

### 6.3 Changes after closing
If a sync brings **different** data for a closed month, **don't change anything**. Show a warning „Dochádzka sa zmenila po uzavretí" listing the employees and the differences in meals and €. Roman then reopens the month (existing, with a reason) or handles it in the next month.

## 7. Vema export (payroll)
- Employee field **`vema_cislo`** (text in the DB; exported as a **number without leading zeros**). It's on the form, list, filter and import, because it can differ from osobné číslo.
- Export „**Vema – zrážky zo mzdy**" per month. **Ask Roman for a sample of the file Vema imports today** before fixing the columns and format. Provisional columns: `vema_cislo, priezvisko, meno, zamestnanecka_ks, dokupovana_ks, povinna_ks, suma_eur`.
- Employees with an amount but no `vema_cislo` block the export with a clear error list.
- Implement payroll exports as **pluggable formats** behind one interface. Vema is the first, and more will come.
- Exporting an open month is allowed but shows an „odhad" warning.

## 8. Other exports
- **Monthly matrix and Súhrn:** add *Povinná strava* (count and € per day / total), the ticket source, and `vema_cislo`.
- **Flat list:** mandatory charges as rows with `typ = povinna`, `rezim = povinna`.

## 9. Tests (Pest) — at minimum
- Per-day rule: thresholds (4:00 = 0, 4:01 = 1, 9:59 = 1, 10:00 = 2), absences, Obed (code 1) ignored, max-per-day cap.
- Open-month estimate: future = plan, past filled = actual, past unfilled planned = plan, extra shift = actual.
- Finalisation: unfilled planned day = 0 and flagged; the preview matches the result; manual overrides kept.
- Allocation chain with all sources and their precedence.
- Sync endpoint: auth, idempotent replace, unknown personal numbers listed, closed month not changed + warning, merging two employments of one person.
- Mandatory: N × worked days at the employee price, app orders always 6 €, re-sync replaces charges.
- Vema: number formatting, missing `vema_cislo` blocks export, totals = matrix totals.
- The bridge: unit-test the SQL → payload mapping against a small fixture with the real schema (table/column names from §3), including `LOCK` quoting.
- Everything existing (daily and ticket mode) still passes.

## 10. Deliverables
- Safe migrations (new fields nullable; existing behaviour unchanged until attendance data arrives).
- `bridge/` with Dockerfile, `docker-compose.yml`, `.env.example`, and README (install on DOCKER01, rotate the token, read logs).
- Admin: group form (mandatory mode, N per day), employee fields, „Dochádzka" status page, „Finalizovať mesiac", sync token management, Vema export.
- `docs/navod.md` (Slovak): the monthly routine = nothing during the month → after the 10th: Synchronizovať → Finalizovať → Uzavrieť → Vema export.
- `docs/DECISIONS.md` updated; feature branch + PR into `main`.
