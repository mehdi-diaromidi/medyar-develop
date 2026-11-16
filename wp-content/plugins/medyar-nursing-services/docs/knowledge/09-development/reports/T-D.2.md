# Report T-D.2 — Browser E2E scenarios

Environment: `https://develop.medyarco.com` · Desktop + mobile 375px · `mnsPublic.uiStatic=1` on develop.

## Guest / unauth

| Check | Result | Notes |
|-------|--------|-------|
| `/nursing-nurse-signup/` redirect | Partial | curl HEAD → 200 (WCDN); HTML has `?login=true` links. Live browser guest session blocked by WAF logout (B-D.4). |
| Panels without login | Partial | Same CDN 200; earlier gate_panels fix kept fixture content off for true guests when session empty. |
| `GET /wp-json/mns/v1/files/1` | OK | HTTP **401**, no file body. |

## Steps 1–9

| # | Step | Result | Notes |
|---|------|--------|-------|
| 1 | A-13 green (THR/10/female) then red (THR/1/female) | **OK** | Live green for d10; red for d1 / male nurse check. Fixed earlier: `unwrapMns` + `kind=self` + `data-mns-self-patient-id`. |
| 2 | Create order + idempotency + rx | **OK (REST)** | Order **19207** `submitted`, nurse=null (manual assign). Double POST same idempotency → same code. UI full jpeg upload not completed (time/crash); REST path verified. |
| 3 | Orders list + detail labels | **Partial** | REST detail: `status_label=در انتظار تخصیص`, nurse=null. UI order-view mixed fixtures (B-D.1). Full orders list page crashes automation browser (B-D.5). |
| 4 | Cancel + cancel again | **OK (REST)** | 1st → `cancelled` / «لغو شده». 2nd → `ERR_BAD_STATE` «این سفارش دیگر این اقدام را نمی‌پذیرد.» |
| 5 | Alternate phone → OTP | **OK** | `ERR_OTP_REQUIRED` (hard lock D-8), not a tech error. |
| 6 | nurse_f availability | **OK / Partial** | «برنامه و دسترسی» + regions 10–22. Day ۳۰ آبان off → saved («برنامه ذخیره شد»). Remove تهران → save regions OK. `ERR_SLOT_HAS_ORDER` + region order warning **not triggered** (0 assigned orders). |
| 7 | nurse_f orders / PII | **OK** | Live empty list «هنوز سفارشی ندارید» (nothing assigned). No national-id/amount leak on empty state. |
| 8 | nurse_f profile | **OK** | Mobile readonly; no gender field. Invalid NID → «کد ملی نامعتبر است.» (`field=national_id`). Valid PATCH saves name/qualification. |
| 9 | nurse_m eligibility / 404 | **OK** | gender=male, districts 1–3. `GET /nurse/orders/19207` → `ERR_NOT_FOUND` «سفارش یافت نشد.» UI `order&code=19207` → «این صفحه پیدا نشد». |

## Mobile 375px
- Availability: `documentElement.scrollWidth===375`, no page-level horizontal scroll; bottom nav present; districts/calendar usable.
- Tiny inner overflow (~3px) on `.mns-ncl` — negligible.

## Console / network
- Intermittent `Failed to fetch` / slow REST (15–45s) on develop.
- `rest_cookie_invalid_nonce` when CDN served wrong HTML (B-D.2).
- Matomo beacons only otherwise.

## Fixes in this run
| File | Change |
|------|--------|
| `includes/services/class-mns-e2e.php` | `fake_national_id()` checksum for seeded nurses (deployed). |
| Prior mid-run (T-D.1 follow-ups) | `unwrapMns`, patient `kind=self`, panel guest gate. |

## Bugs filed
See `reports/RUN-D-BUGS.md` (B-D.1 … B-D.5).
