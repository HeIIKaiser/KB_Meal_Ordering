# Agent notes — Jedáleň

Read `docs/BRIEF.md` (requirements) and `docs/DECISIONS.md` (answers to OPEN items, design decisions) before changing anything.

- Runtime target is **Websupport shared hosting**: PHP + MySQL/MariaDB + cron only. No Node, queue workers, websockets or daemons on the server. Compiled front-end assets are committed.
- UI text is **Slovak** with correct diacritics; code, comments, identifiers and commits are English.
- Timestamps are stored in UTC; business dates/cutoffs use `App\Support\LocalTime` (Europe/Bratislava). Calendar-date columns use the `App\Casts\LocalDate` cast.
- Money is integer cents; format with `App\Support\Money::format()` ("1,50 €").
- **Never create or modify orders directly** — go through `App\Domain\Ordering\OrderService` (price typing, limits, row locking, audit). Locks via `SlotLockService`.
- Every business rule needs a Pest feature test. Run `./vendor/bin/pest` (SQLite) and, for locking/SQL changes, also against MySQL/MariaDB (see README). Format with `./vendor/bin/pint`.
- Every milestone ends with green tests, notes in `docs/milestones.md`, and a commit.
- Ask Roman (the owner) about items marked OPEN instead of guessing; record answers in `docs/DECISIONS.md`.
