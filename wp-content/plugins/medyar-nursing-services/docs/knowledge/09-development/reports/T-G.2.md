# T-G.2 — Eligibility و A-13 چندخدمت

## خلاصه

- `MNS_Eligibility::resolve_service_ids` + `nurses_for` / `nurse_covers_services`: پیش‌فرض همه، محدودیت صریح، `needs_explicit_nurse`
- `MNS_Availability::check`: `state=manual` برای `manual_assign`
- REST `a13_check`: پارامتر `service_ids` (آرایه یا CSV) + سازگاری با `service` تک‌خدمت
- JS ویزارد: `service_ids` در `/availability/check`
- Self-test: `AC-G-1`, `AC-G-2`, `AC-G-3`

## فایل‌ها

- `includes/domain/class-mns-eligibility.php`
- `includes/domain/class-mns-availability.php`
- `includes/rest/class-mns-rest-patient.php`
- `assets/js/public.js`
- `includes/class-mns-selftest-catalog.php`

## یادداشت

`unassigned_matching_count` هنوز روی `orders.service_id` (خدمت اصلی) فیلتر می‌کند — برای حالت assignment_mode=manual کافی است؛ تطبیق کامل چندخدمتی نیاز تصمیم محصول ندارد چون سفارش‌های multi همان primary را در `orders.service_id` نگه می‌دارند.
