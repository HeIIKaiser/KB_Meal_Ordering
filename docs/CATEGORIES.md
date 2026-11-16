# Feature: Temps / students and self-employed (invoice settlement)

> For Claude Code. Builds on `docs/ATTENDANCE.md` and the ticket-mode code. Read both and `docs/DECISIONS.md` first.
> **Post an implementation plan and wait for Roman's OK before writing code.** Record decisions in `docs/DECISIONS.md`.

## 1. Context

Besides 8 h, 12 h and mandatory groups, KB SPA has two more categories:

| Category | Price | Estimate during the month | Paid via |
|---|---|---|---|
| **Temps / students** (brigádnici, dohody) | tickets (as now) | **by orders** (new) | Vema payroll |
| **Self-employed** (SZČO) | **fixed price, may differ per person** (new) | — | **invoice** issued by accounting (new) |

To keep this general, a group gets **two independent new settings**: an estimate source (§2) and a settlement (§4). Self-employed get a new pricing mode (§3).

## 2. Temps / students: estimate by orders

Temps are in WebVisitor with personal numbers and are paid through Vema, so their **final** entitlement works exactly like other ticket groups (ATTENDANCE.md §5.2: meals per day from Celkom). The difference is that they have little or no shift plan, so a plan-based estimate would give them 0 tickets during the month.

- New group setting **`estimate_source`**: `plan` (default, current behaviour) | `orders`.
- With `orders`, while the month is open, the live estimate for an employee = **the number of their active ticket-mode orders in that month**. Every order is shown and priced at 1,50 € provisionally.
- At finalisation, attendance decides as usual: tickets = meals by attendance, and the latest orders above that become 6 € (existing `Repricer`).
- The order screen and „Môj mesiac" show for these employees: „Cena sa potvrdí podľa dochádzky na konci mesiaca."
- The finalisation preview highlights employees of `orders` groups with meals moving to 6 €.
- *Assumption (Roman didn't choose the strict variant):* provisional 1,50 € rather than 6 € until attendance confirms the day. Keep it easy to change.

## 3. Self-employed: fixed price mode

- New `PricingMode` **`fixed`** („Pevná cena"). Every order costs the person's price. There are no tickets, no daily limits, no attendance, no repricing at month end, and no extra confirmation dialog (the price is simply shown on the button/confirmation).
- **Price:** a group default price, plus an optional **per-person price**. Both are **versioned by `valid_from`** like the existing price list (the latest version on or before the meal date applies). Optionally separate prices for lunch and dinner. If that complicates the model too much, propose one price per person in the plan.
- The price is **snapshotted on the order**, as in daily mode.
- Optional cap „max jedál na slot a deň" (empty = unlimited).
- These people are **excluded** from attendance sync warnings („missing from attendance", „Nepárované").
- The kitchen sees their orders as normal.

## 4. Settlement: payroll vs invoice

- New group setting **`settlement`**: `payroll` (default) | `invoice`. It's independent of the pricing mode, though self-employed will normally be `fixed` + `invoice`.
- **The Vema export contains only `payroll` employees.** `invoice` employees never appear in it.
- New export **„Fakturácia"** per month (payroll and admin roles):
  - **Súhrn sheet:** one row per `invoice` person with orders: osobné číslo, name, billing details (§5), count of lunches / dinners / other, unit price(s), total €.
  - **Detail sheet:** one row per order (date, meal, menu item, price) as an attachment for the invoice.
  - Accounting issues the invoices themselves. **The exact columns are provisional**, built as a pluggable format like the Vema export, so the layout can follow accounting's wishes later. Mark it „predbežný" in the UI.
- Monthly matrix / Súhrn / flat list: add a column `vysporiadanie` (`mzdy` / `faktura`), and include `fixed` mode orders like the others.

## 5. Employee data

- **Billing details** (optional, shown when the employee's group has `invoice` settlement): fakturačný názov, IČO, DIČ, adresa, e-mail, poznámka. They're in the employee form and as optional import columns.
- **Personal numbers can change** (self-employed currently use numbers from Pramen, which may change). Admin can edit `osobne_cislo` on the employee form (unique, audited). Orders and history are linked by internal id, so nothing breaks. Document that the employee import matches by osobné číslo, so a number change must be made in the admin **before** importing a file with the new number. Otherwise the import creates a new person. The import preview should warn when a new number has the same name as an existing employee.

## 6. Tests (Pest) — at minimum
- `estimate_source = orders`: all orders at 1,50 € while open; finalisation reprices the orders above the attendance entitlement to 6 € (latest first); `plan` groups unchanged.
- `fixed` mode: group price, per-person override, `valid_from` versioning by meal date, snapshot on the order, optional cap, no repricing at finalisation, excluded from attendance warnings.
- Settlement: Vema export excludes `invoice`; the Fakturácia export includes only `invoice`, and totals match the monthly matrix.
- Changing osobné číslo keeps orders and history; the import warns about a same-name new number.
- Everything existing still passes.

## 7. Deliverables
- Safe migrations (defaults = current behaviour: `estimate_source = plan`, `settlement = payroll`).
- Group form: estimate source, settlement, fixed price; employee form: per-person price, billing details, editable osobné číslo.
- „Fakturácia" export (provisional format).
- `docs/navod.md` (Slovak): how to set up temps and self-employed, and the monthly Fakturácia export.
- `docs/DECISIONS.md` updated; feature branch + PR into `main`.
