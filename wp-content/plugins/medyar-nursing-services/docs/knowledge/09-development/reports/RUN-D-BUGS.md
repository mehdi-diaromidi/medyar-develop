# RUN-D bugs (not fixed in-run / product or infra)

## B-D.1 — Patient order-view / dashboard still render `uiStatic` fixtures
- **Repro:** Login as `mns_e2e_patient` → `mns_page=order-view&mns_order=19207` (after REST cancel).
- **Seen:** API `GET /orders/19207` → `status=cancelled`, `label=لغو شده`, `can_cancel=false`. UI still shows fixture copy («حسن محمدی»، «پرستار شما مشخص شد») and a «لغو سفارش» button; body text has no «لغو شده».
- **Suspect:** `mnsPublic.uiStatic=1` develop fixtures path in patient order-view / dashboard vs live hydrate.
- **Also:** Nurse `mns_page=orders` correctly shows empty live state («هنوز سفارشی ندارید») — inconsistency across panels.

## B-D.2 — CDN / WCDN caching authenticated panel HTML
- **Repro:** After E2E login as patient, open `/nursing-patient-panel/?mns_page=orders` without cache-buster → HTML naming `M.Mehdi Diaromidi` + `rest_cookie_invalid_nonce`.
- **Mitigation used:** `?_=<ts>` + supervisor note that patient pages now send `private, no-store`.
- **Suspect:** edge cache of panel HTML; guest curls also return 200 with `wcdn-status` instead of auth redirect.

## B-D.3 — E2E seed national_id failed Iranian checksum
- **Repro:** `PATCH /nurse/me` with seeded `national_id` → `ERR_VALIDATION` / «کد ملی نامعتبر است.»
- **Cause:** `sprintf( '6%09d', $uid % … )` ignores checksum.
- **Fix applied locally+deployed:** `MNS_E2E::fake_national_id()` (checksum). Needs re-seed to rewrite existing nurse rows.

## B-D.4 — WAF blocks automation admin / logout
- `/wp-admin/tools.php?page=mns-e2e` → Upstream Forbidden.
- `/wp-login.php?action=logout` → Upstream Not Found.
- Blocks Cursor-driven E2E purge and clean guest sessions.

## B-D.5 — Heavy patient orders list crashes embedded browser
- Navigating to `mns_page=orders` (large fixture DOM ~446 refs) repeatedly ended in `chrome-error://chromewebdata/`.
- Worked around with REST from `new-order` / `order-view`.

## Partial coverage notes (not bugs)
- `ERR_SLOT_HAS_ORDER` and region-order warning: nurse_f had **0** assigned orders (`assignment_mode` untouched / manual) — could not trigger.
- Nurse_m «male district 1 order reaches him»: no auto-assign; verified gender=`male`, districts `[1,2,3]`, foreign order `19207` → UI 404 + REST `ERR_NOT_FOUND`.
