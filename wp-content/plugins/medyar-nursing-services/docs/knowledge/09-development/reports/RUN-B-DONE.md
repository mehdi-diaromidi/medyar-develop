# RUN-B DONE — M2 + M3 continuous run

## Cards

| Card | Status | Notes |
|------|--------|-------|
| T-2.1 | OK | Schedule save_days, regions, locations, status; AC-M2-1..6 |
| T-2.2 | OK | REST NRS-4..7 + availability page/JS; HEALTH=OK |
| T-3.1 | OK | MNS_Services + admin + seed config |
| T-3.2 | OK | Patients CRUD + ownership 404 |
| T-3.3 | OK | A-13 API + time validation + rate limit |
| T-3.4 | OK | Orders create + GET_LOCK + new-order wire |
| T-3.5 | OK | Assignment + admin assign/phone order |
| T-3.6 | OK | Patient ORD-2/3/4 + orders page live |
| T-3.7 | OK | Nurse NOR-1/2 + notifications cron |
| T-3.8 | OK | SMS 4/5/11/12 on mns_order_transitioned |
| T-3.9 | OK | Slice-1 live cutover (admin/dev fixtures remain) |

## Migrations
None (schema v1 tables already covered orders/patients/services/notifications).

## Key assumptions
- Extended domain `MNS_Nurse_Schedule` (no services duplicate).
- Admin orders UI is a compact page (no WP_List_Table class file).
- Order-view pages still SSR-fixture for admin; REST for live clients.
- `assignment_mode=auto` attempts assign after create; failure leaves `submitted`.

## Open questions
- Full interactive AC in browser (supervisor).
- Concurrent AC-M3-5 / AC-ASG-1 under load.
- OTP flow for alternate phone (ERR_OTP_REQUIRED only).
- Pattern IDs for SMS events still empty ⇒ log-only until configured.

## Risks
- Phone-order account creation depends on DrPlus phone helpers when present.
- A-13 alternatives performance unchanged (known M9 item).
- Fixture grep AC-M3-16 not absolute (preview fallbacks remain).

## Live health
HEALTH=OK after T-2.1, T-2.2, and final M3 batch (home, nurse/patient dashboards, new-order, signup, availability, patient/nurse orders).
