# Report T-D.1 — E2E sandbox

## Files
- `includes/services/class-mns-e2e.php` *(new)* — enable window, seed `mns_e2e_*`, hashed one-time login tokens, purge, hourly cron
- `admin/class-mns-admin-e2e.php` *(new)* — `tools.php?page=mns-e2e` (only if host contains `develop.`)
- `includes/class-mns-autoloader.php`
- `includes/backend/class-mns-backend-selftest.php`
- `includes/class-mns-activator.php` — unschedule E2E cron on deactivate

## Security
- Default off until `mns_e2e_enabled_until` is in the future (2h enable button)
- Host gate: `develop.` only; otherwise menu/handlers not registered
- Login token stored as SHA-256 hash in transient (30 min, single-use); requires `mns_e2e_user=1`
- Cleanup deletes all `mns_e2e_*` users + related MNS rows and clears enable option

## Seeded users (after admin presses «ساخت دادهٔ E2E»)
| login | role | phone | regions |
|-------|------|-------|---------|
| mns_e2e_patient | patient | 09120000011 | — |
| mns_e2e_nurse_f | nurse female | 09120000012 | THR districts 10–22, all open slots weekly |
| mns_e2e_nurse_m | nurse male | 09120000013 | THR districts 1–3, all open slots weekly |

`assignment_mode` is not changed.

## Live check
Protocol URLs → HEALTH=OK after deploy.

## Status for supervisor
**آمادهٔ ساخت لینک توسط ناظر.**  
Please open `tools.php?page=mns-e2e` → enable 2h → seed → copy links into repo file `.e2e/links.json` as `{"patient":"url","nurse_f":"url","nurse_m":"url"}`.
