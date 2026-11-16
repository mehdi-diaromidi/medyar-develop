# Report T-E.5 — E2E assigned-order seed

## Shipped
- `MNS_E2E::seed_assigned_orders()` creates two tomorrow serum orders for `mns_e2e_patient`:
  - district **10** / female → assign `mns_e2e_nurse_f` (`8-12`)
  - district **1** / male → assign `mns_e2e_nurse_m` (`12-16`)
- Admin Tools → MNS E2E: button **«ساخت سفارش نمونهٔ تخصیص‌یافته»** (`admin_post_mns_e2e_seed_assigned`), same enable/host guards as other E2E actions.
- `fake_national_id()` unchanged (checksum); nurse seed still `replace`s nurse rows on re-run.
- Orders belong to `mns_e2e_*` users → removed by existing `purge_all` → `MNS_Selftest::purge_user_rows`.

## Supervisor (required before T-E.6)
1. Enable E2E (2h) if needed.
2. «ساخت دادهٔ E2E» (or confirm users exist).
3. «ساخت سفارش نمونهٔ تخصیص‌یافته».
4. «لینک‌های تازه» and write `.e2e/links.json` (same shape as RUN-D).

Agent does **not** purge (WAF). Cleanup remains supervisor after the browser pass.

## Deploy
Uploaded with plugin `MNS_VERSION` **0.5.40**. Waiting on fresh `.e2e/links.json` for T-E.6.
