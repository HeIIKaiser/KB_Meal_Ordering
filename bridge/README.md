# Attendance bridge (WebVisitor → Jedáleň)

A small PHP 8 CLI in a Docker container on **DOCKER01 (192.168.0.66)**. It reads attendance from the WebVisitor
database (`webbox` on POLANA, MySQL 8 on port 3308, read-only user `meals_ro`) and **pushes it out** to the app over
HTTPS. The app on Websupport never connects into the company network. Spec: `docs/ATTENDANCE.md`.

What it sends: per personal number and day, the planned seconds, the shift name, the worked seconds („Celkom“,
`totalWork`), the absence codes (without *Obed*) and whether the attendance month is locked. **No names.**

| When | What |
|---|---|
| Every night at `SYNC_TIME` (default 02:00), and once right after the container starts | Previous + current month, complete |
| Every `POLL_MINUTES` (default 5) | Asks the app whether an admin pressed **Synchronizovať teraz**, and syncs if so |
| Before each sync | Checks that all expected tables/columns exist. If not, it sends nothing and reports the error to the app |

## Install on DOCKER01

```bash
git clone <this repo> jedalen && cd jedalen/bridge     # or copy only the bridge/ folder
cp .env.example .env
```

Edit `.env`:

- `WV_DB_PASSWORD`: password of `meals_ro`.
- `APP_URL`: the app's address (`https://…`).
- `SYNC_TOKEN`: in the app, open *Nastavenia → Dochádzka – synchronizácia → Vygenerovať token*. The token is shown only once.

Then start the container:

```bash
docker compose up -d --build
docker compose logs -f              # watch the first sync
```

Check without sending anything: `docker compose run --rm bridge php bin/bridge check` (schema + app connection).
Sync now by hand: `docker compose run --rm bridge php bin/bridge sync 2026-10 2026-11`.

## Rotate the token

1. In the app: *Nastavenia → Dochádzka – synchronizácia → Vygenerovať nový token*. The old one stops working at once.
2. On DOCKER01: put the new value in `bridge/.env` (`SYNC_TOKEN=`) and run `docker compose up -d` to restart.

## Logs and troubleshooting

- `docker compose logs --tail=100 bridge`. Each sync logs one line per month: people sent, days stored, unmatched
  numbers, and the format of `vis_monthatt.PERIOD` it found.
- Failures are also reported to the app: the *Dochádzka* page and the dashboard show the last error. The dashboard
  turns red if the last successful sync is older than 36 h.
- `Schéma WebVisitor sa zmenila: …`: a vendor update renamed a table or column. Fix the SQL in
  `src/WebVisitorReader.php` / `src/SchemaCheck.php`; the shift-name column can also be set with `WV_SHIFT_NAME_COLUMN`.
- `HTTP 401`: wrong token. `HTTP 403 IP address not allowed`: check the IP allowlist in the app settings.
- While the bridge is down, the app keeps the last data. The manual tools (individual tickets, *Konečné lístky* import)
  still work.

## Files

| File | Purpose |
|---|---|
| `src/WebVisitorReader.php` | **All SQL** against WebVisitor (read-only; `LOCK` is backticked) |
| `src/PayloadBuilder.php` | Rows → payload: merges a person's employments by date, `totalWork` only, drops Obed (code 1) |
| `src/SchemaCheck.php` | Expected tables/columns |
| `src/AppClient.php` | HTTPS to the app (gzip, bearer token) |
| `src/Bridge.php`, `bin/bridge` | Loop / commands (`run`, `sync`, `check`) |

Tests: `tests/Unit/Bridge/BridgeTest.php` in the app repo (fixture with the real table names, SQLite and MySQL).
