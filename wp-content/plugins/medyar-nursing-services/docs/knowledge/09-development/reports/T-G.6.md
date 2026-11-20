# T-G.6 — Snapshot موجودی

## خلاصه

- `GET /mns/v1/availability/snapshot` · `permission_patient` · `?_envelope=1` · `Cache-Control: private, no-store`
- خروجی فشرده: `regions[prov:city:district][gender][dayOffset]=bitmask` + `slots` + `svc_restricted` + `horizon`
- بدون نام/شناسه/تعداد پرستار (R-PRV)
- Transient ۶۰ث؛ ابطال روی `mns_order_transitioned` / schedule / regions
- JS: بعد از `window.load` با `requestIdleCallback`/`setTimeout`؛ تا آمدن A-13 زنده؛ بعد از آمدن قرمز محلی فوری؛ سرور همچنان هنگام ثبت برنده است
- خدمت‌های `needs_explicit_nurse` از مسیر snapshot رد می‌شوند و به A-13 زنده می‌مانند

## فایل‌ها

- `includes/domain/class-mns-availability-snapshot.php`
- `includes/rest/class-mns-rest-patient.php`
- `includes/backend/class-mns-backend-orders.php` (boot)
- `assets/js/public.js`
- Self-test `AC-G-6`

## انحراف جزئی

افق snapshot با `min(21, order_horizon)` محدود شده تا اندازه JSON کوچک بماند.
