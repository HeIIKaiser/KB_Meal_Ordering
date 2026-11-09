# Security review (M6)

Scope: the whole app as of M6: employee app, staff panel, plain staff routes, exports, imports and backups.
Hosting: Websupport shared hosting behind the Websupport WAF, HTTPS.

Changes made during the review are marked **(M6)**. Automated checks live in
`tests/Feature/Security/SecurityTest.php`, `tests/Concurrency` and `tests/Database`.

## Authentication

| Area | Measure |
|---|---|
| Employee login | osobné číslo + 4–6 digit PIN, bcrypt hash. Generic error message (doesn't reveal existing numbers or inactive accounts). Session regenerated on login. |
| Brute force (employees) | 5 failures per osobné číslo + IP / 60 s, **and (M6) 10 failures per osobné číslo from any IP / 15 min**. The second limit stops distributed PIN guessing. A 4-digit PIN at 10 tries / 15 min would take months on average. |
| PIN generation | 6 random digits (`random_int`), trivial patterns rejected; shown once on the printed slip (encrypted session, removed on first view). Reset or change invalidates the remember token → all devices are logged out. |
| Remember-me | Laravel remember cookie, HttpOnly, 400 days (browser maximum), encrypted. No tokens in localStorage. |
| Deactivated employee | Logged out on the next request, including Livewire requests (persistent middleware). |
| Staff login | Email + password (min. 12 characters), Filament login throttled (5 attempts). 2FA (TOTP + recovery codes) **optional per user** (Roman's decision). Admin can switch off a lost 2FA. |
| Deactivated staff user | `canAccessPanel()` is checked on every panel request → 403 even with an open session (tested). |
| Guards | Employee (`employee`) and staff (`web`) sessions are separate guards. An employee login never opens `/admin`, a staff login never opens the employee app. Both can be used in one browser (tested). |

## Authorization

| Area | Measure |
|---|---|
| Staff panel | Policies for every resource (`app/Policies`) and `canAccess()` on every custom page; role matrix tested per page. Payroll is read-only, kitchen can't change orders, prices or employees. |
| Writes in custom pages | Every Livewire action that writes re-checks the role (`abort_unless(static::canAccess())`). It doesn't rely on the page having been opened. |
| Order rules | `OrderService` / `SlotLockService` re-check the actor's role and ownership themselves, so a UI bug can't bypass them. Corrections after the cutoff need a reason and are audited. |
| Plain staff routes (print, PDF, Excel, exports, slips, templates, backup download) | **(M6)** guarded at the route by `staff:<roles>` middleware (`EnsureStaffRole`). Guests are sent to the staff login. |
| Route guard test | **(M6)** a test lists all routes and fails if any non-public route lacks authentication. |
| Employee ordering screen | All lookups are scoped to the logged-in employee. The confirmation dialog state is `#[Locked]` (tamper-proof). The service checks ownership again. |
| Private files | **(M6)** Laravel's `storage/{path}` serving route is disabled. Backups and uploads in `storage/app/private` have no web route. The document root is `public/` only. |
| Backup download | Admin only, strict file-name whitelist (no path traversal, tested), audited. |

## Input handling

| Area | Measure |
|---|---|
| SQL injection | Eloquent / query builder with bindings everywhere. Backups quote values with `PDO::quote`. |
| XSS | Blade escaping; values passed to Alpine via `@js()` / data attributes. No user HTML is rendered. |
| CSRF | Laravel CSRF on all forms and Livewire. GET routes only read data (exports also write an audit entry). |
| Mass assignment | Explicit `$fillable`. `pin_hash`, remember tokens and 2FA secrets aren't fillable (set only by dedicated code). Staff `roles` are fillable but only exposed in the admin-only user form, and an admin can't remove their own admin role. |
| File uploads (imports) | XLSX/CSV only, max 5 MB, max 5 000 rows. Stored in Livewire's private temp directory, parsed with OpenSpout (no formulas evaluated). |
| PDF | dompdf with remote resources disabled and chroot set to the views directory. |

## Transport, headers, cookies

| Area | Measure |
|---|---|
| HTTPS | `URL::forceHttps()` in production, secure session cookie (`SESSION_SECURE_COOKIE=true`), **(M6) HSTS** `max-age=31536000; includeSubDomains` in production. |
| Headers **(M6)** | `Content-Security-Policy` (own origin only), `X-Frame-Options: DENY` + `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (camera, mic, geolocation, payment off), `Cross-Origin-Opener-Policy: same-origin`. `X-Powered-By` removed. |
| CSP limits | `'unsafe-inline'` and `'unsafe-eval'` for scripts are required by Livewire/Alpine. All other sources (scripts, styles, fonts, images, frames, form targets) are restricted to the app's own origin. Checked in Chromium on all main screens: no violations. |
| Third parties **(M6)** | Filament's default avatar service (ui-avatars.com) leaked staff names on every page view. It is replaced by local initials. The app makes **no** third-party requests: system fonts, no CDNs, no analytics. |
| Session | Encrypted (`SESSION_ENCRYPT=true`, verified by `app:hosting-check` in production), HttpOnly, SameSite=Lax, database driver. |
| Service worker | Caches only static build assets and the offline page — no personal data, no pages. |
| Reverse proxy | `TRUSTED_PROXIES` is empty by default (no spoofable `X-Forwarded-For`). Set it only if a proxy fronts PHP. |

## Data protection

| Area | Measure |
|---|---|
| Personal data | Names, osobné číslo, stredisko, orders. No email or phone numbers for employees. |
| Audit log | Orders (with reasons), prices, locks, menus, employees, PIN resets, staff users, settings, **exports and backup downloads (M5/M6)**. Read-only in the panel, admin only. |
| Exports | Payroll and admin only; every download is logged. |
| Backups | Nightly, 30-day rotation, private directory, admin-only download (logged). Offsite copy pulled by the company over SSH. |
| Errors **(M6)** | Slovak error pages without technical details. `APP_DEBUG=false` is enforced by `app:hosting-check`. |

## Dependencies

`composer audit` and `npm audit`: **no known vulnerabilities** at the time of the review. Re-run before each
release (`composer audit`, `npm audit --omit=dev`).

## Residual risks / recommendations

1. **CSP allows `unsafe-eval`/`unsafe-inline`** (required by Livewire/Alpine). This is mitigated by Blade
   escaping and the absence of user HTML.
2. **Optional staff 2FA.** At least admins should enable it (*Profil → Dvojstupňové overenie*). Use
   long, unique passwords.
3. **Shared phones / kiosks.** Employees stay logged in for 400 days. On a shared device they must use
   *Odhlásiť sa*. The access slip mentions the PIN; employees should keep it private.
4. **Offsite backups** depend on the company pull job. Check monthly that new files arrive and test a
   restore twice a year (`docs/pilot.md`).
5. **Keep PHP and dependencies updated** (Websupport PHP version in WebAdmin; `deploy/update.sh` after
   dependency updates).
