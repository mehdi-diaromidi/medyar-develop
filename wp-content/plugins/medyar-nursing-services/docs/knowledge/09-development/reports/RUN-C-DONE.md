# RUN-C DONE

## Cards
| Card | Status | Notes |
|------|--------|-------|
| T-C.1 | DONE | `purge_leftovers` + AC-TC-1 `leftover=0` |
| T-C.2 | DONE | `POST /patient/files`, JS upload, attach order, orphan cron |
| T-C.3 | DONE | Nurse `can_read` prescription only assigned/in_progress; AC-FIL-1 |
| T-C.4 | DONE | Live A-13 when `data-mns-live=1`; fixture path kept |
| T-C.5 | DONE | AC-M3-3..7, ASG-1..5, M3-10..15, FIL-1 via `MNS_Selftest_Orders` |
| T-C.6 | DONE | self lock/limits, notes/address/phone harden, essentials whitelist |
| T-C.7 | BLOCKED | `reports/BLOCKED-T-C.7.md` — DrPlus OTP auth-only; no verify helper |

## Files touched
- `includes/class-mns-selftest.php`
- `includes/class-mns-selftest-scenarios.php`
- `includes/class-mns-selftest-orders.php` *(new)*
- `includes/class-mns-autoloader.php`
- `includes/services/class-mns-files.php`
- `includes/backend/class-mns-backend-files.php`
- `includes/class-mns-activator.php`
- `includes/rest/class-mns-rest-patient.php`
- `includes/services/class-mns-orders.php`
- `includes/services/class-mns-patients.php`
- `includes/services/class-mns-nurse-orders-api.php`
- `includes/services/class-mns-patient-orders-api.php`
- `assets/js/public.js`

## Migrations
None.

## Assumptions
- Green A-13 UI reuses yellow class (no CSS change). Soft 422/429/network ⇒ yellow message, continue unlocked.
- Serum seed + Tehran district 1 used for green self-test fixtures.
- Self-test admin page needs login; scenarios are registered for supervisor run.

## Open questions
- Product choice for alternate-phone OTP (see BLOCKED-T-C.7).
- Whether live A-13 should show a dedicated green style later (CSS currently has yellow/red only).

## Risks
- AC-M3-5 depends on `slot_capacity=1` and a single seeded nurse; environment schedule/region data must match.
- Concurrent dual-connection GET_LOCK stress not simulated beyond capacity filling.
- Minimal JPEG bytes in `store_bytes` for ACL tests may differ from GD-processed uploads.

## Live health (develop.medyarco.com)
All protocol URLs + availability/orders panels ⇒ **HTTP 200**, no Fatal/Parse/critical. **HEALTH=OK**.

## Self-test PASS/FAIL
Not executed remotely (tools.php requires auth). Latest registered suite includes prior M0/M2/M3-1/2/A13 plus new AC-M3/ASG/FIL and AC-TC-1. Supervisor should open `tools.php?page=mns-selftest` and record `data-pass` / `data-fail` from `#mns-selftest-summary`.

## Commit/push
Not done (per brief).
