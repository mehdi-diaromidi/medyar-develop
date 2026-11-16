# RUN-A DONE — M0 remainder + M1

Date: 2026-11-16 · Plugin path: `wp-content/plugins/medyar-nursing-services/`

## Card status

| Card | Status | Live health |
|---|---|---|
| T-0.3 Settings | done | OK |
| T-0.4 Error/Log/REST | done | OK |
| T-0.5 Domain machine/eligibility | done | OK |
| T-0.6 Self-test/Reset | done | OK |
| T-0.7 Private files | done | OK |
| T-1.1 Nurse applications | done | OK |
| T-1.5 SMS | done | OK |
| T-1.3 Admin nurses + seed | done | OK |
| T-1.2 Signup UI | done | OK |
| T-1.4 Nurse profile REST | done | OK (final batch) |

No `BLOCKED-*` reports.

## Modules registered (`MNS_Backend::modules`)
`core` → `db` → `settings` → `rest` → `domain` → `selftest` → `files` → `nurses`

## Migrations
- Schema version still **1** (T-0.2). No schema v2 required in RUN-A cards.
- Options: `mns_schema_version`, `mns_migration_log`, `mns_settings`, `mns_backend_errors`, `mns_log`

## Key new surfaces (admin)
- Tools → وضعیت دیتابیس MNS (`mns-db-status`)
- Tools → MNS Self-test (`mns-selftest`) + `#mns-selftest-summary`
- Tools → بازنشانی MNS (`mns-reset`, phrase `RESET MNS`)
- خدمات پرستاری → تنظیمات (real form)
- خدمات پرستاری → عضویت پرستاران (list + detail)

## Public / REST
- `/nursing-nurse-signup/` real apply + states
- `GET/PATCH /mns/v1/nurse/me`, photo POST/DELETE
- `GET /mns/v1/files/{id}`, `GET /mns/v1/ping`
- Profile JS saves via REST when `restNonce` present

## Assumptions
1. Auto-migrate skips AJAX admin requests.
2. Signup CSS shipped inline in the view (`.mns-nsu*`) for first paint; can move fully into `public.css` later.
3. Service seed (`serum`,`injection`) runs when admin nurses page opens and table is empty.
4. SMS patterns empty by default ⇒ log only until patterns are set.
5. Admin/dev preview of nurse profile still uses fixtures so static UX does not break.

## Open questions / risks
- **SMS patterns / admin phones** still empty (owner).
- **Contract text** is temporary seed — replace via admin when owner provides.
- **Self-test AC-ELG** creates/deletes WP users; run only on develop.
- **Reset tool is destructive** (no backup) — owner-aware.
- Full interactive AC runs (apply form uploads, approve flow) need logged-in browser by supervisor.
- `initNurseProfile` photo upload to REST not fully wired (PATCH fields yes; multipart photo can be follow-up).
- National ID uniqueness across pending/approved may collide on empty string historically — applications always send validated 10-digit.

## Per-card reports
See `reports/T-0.3.md` … `T-1.5.md` in this folder.

## Commit
Not created (per RUN-A protocol).
