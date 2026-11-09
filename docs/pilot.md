# Pilot & go-live checklist

Tick each line on the **production** hosting. Details are in the README (technical) and `docs/navod.md`
(Slovak manual for staff).

## 1. Production hosting (Roman)

- [ ] Run `tools/hosting-probe.php` on the company's Websupport hosting (PHP ≥ 8.3 web + CLI + cron, MariaDB, symlink), then delete it.
- [ ] Subdomain created, HTTPS certificate active, document root symlinked to `public/` (README → Deployment).
- [ ] App installed from `main` with the production `.env` (`APP_ENV=production`, `APP_DEBUG=false`, `https://` URL, `SESSION_SECURE_COOKIE=true`, DB host name).
- [ ] `php artisan migrate --force --seed` done; **no demo data** seeded.
- [ ] First admin created: `php artisan app:staff-user <email> --role=admin`; admin enabled 2FA in *Profil* (recommended).
- [ ] Cron wrapper installed and running every minute (`storage/logs/cron-last-run.txt` updates).
- [ ] `php artisan app:backup` once, then `php artisan app:hosting-check` → all OK.
- [ ] `https://<subdomain>/.env` and `https://<subdomain>/composer.json` return 403/404.
- [ ] Company offsite pull of `storage/app/private/backups/` set up; first file arrived.
- [ ] **Restore test:** restore the newest backup into a scratch database (`gunzip -c … | mysql …`) and check the row counts.

## 2. Set-up in the panel (admin, kitchen)

- [ ] *Nastavenia → Ceny*: Darované 1,50 €, Extra 6,00 € (preset; change only if needed).
- [ ] *Nastavenia → Jedlá a uzávierky*: cutoff times confirmed with the kitchen (default 09:00 / 14:00 same day).
- [ ] *Nastavenia → Objednávanie*: ordering horizon (14 days), extra cap (empty = unlimited).
- [ ] *Jedálny lístok → Uzavreté dni*: public holidays and closures for the pilot period.
- [ ] Staff accounts for kitchen and payroll (*Ľudia → Správcovia*), roles checked.
- [ ] Employees imported (*Zamestnanci → Import*, from the HR system's export with osobné číslo) and checked against HR.
- [ ] Menu for the first 2 weeks entered or imported (weekly Excel: `datum | polievka | obed_1 | obed_2 | vecera`).

## 3. Pilot group (1–2 weeks, ~10–15 people: kitchen + a few departments)

- [ ] Access slips printed for the pilot group (*Zamestnanci* → select → *Tlačiť prístupové lístky*, or *Nevytlačené lístky* after the import) and handed out personally.
- [ ] Each pilot user: scanned the QR code, logged in, **added the icon to the home screen**, ordered one meal.
- [ ] Test on real devices: an old Android phone, an iPhone, a desktop PC (ordering, cancel, change, extra 6 €, Môj mesiac).
- [ ] Kitchen: daily overview → **print on the kitchen printer** (margins, readability, checkbox column); lock/unlock tried once.
- [ ] Late order after the cutoff entered by an admin (*Objednať za zamestnanca* with reason).
- [ ] Payroll: monthly export downloaded and matched against attendance for the pilot group (darované vs. 8/12 h shifts).
- [ ] Collect feedback (what was unclear, what took too long) → adjust before rollout.

## 4. Rollout to everyone

- [ ] Go-live date agreed; old system switch-off date known (OPEN §13.7).
- [ ] Short notice for employees (how to log in, who gives PINs, cutoff times, 1,50 € / 6,00 € rule, the price disclaimer).
- [ ] Access slips printed for everyone (8 per A4 page) and distributed by department heads.
- [ ] First week: someone available for "I can't log in" (PIN reset = 1 minute in *Zamestnanci → Reset PIN*).
- [ ] After the first full month: monthly export → payroll import worked; backups arrive offsite.

## 5. Regular checks

| How often | What |
|---|---|
| Daily (automatic) | Backup 02:30; cron heartbeat |
| Weekly | Kitchen enters next week's menu (by Thursday) |
| Monthly | Payroll export; check that offsite backups arrive; glance at *História zmien* |
| Twice a year | Restore test; `composer audit`; update with `deploy/update.sh` |
