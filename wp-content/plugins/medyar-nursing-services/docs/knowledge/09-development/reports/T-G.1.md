# T-G.1 — کاتالوگ و مدل داده

تاریخ: 2026-11-21 · محیط: develop.medyarco.com

## خلاصه

| مورد | نتیجه |
|------|--------|
| `MNS_Schema::LATEST` | `4` |
| `sql_v4()` | ستون‌های فلگ/چک‌لیست روی `mns_services` + جدول `mns_order_items` |
| Seed | ۱۰ دسته / **۴۴** زیرخدمت (QUESTION-1 برای «۴۸») |
| Legacy | `serum`/`injection` ردیف می‌مانند، `active=0` |
| Admin خدمات | گروه‌بندی دسته، قیمت، فلگ‌ها، چک‌لیست خطی، برچسب پیش‌نویس |
| Hardcode | E2E/role-switch/admin phone/AC-M3-1 به کاتالوگ جدید؛ fixtureهای استاتیک serum عمداً برای UI preview مانده‌اند |

## فرضیات §۲ اعمال‌شده

- A-1 deactivate legacy · A-2 `requires_rx` · A-3 `needs_explicit_nurse` · A-4 `manual_assign` · A-5 checklist draft ۳–۶ بند

## فایل‌های کلیدی

- `includes/db/class-mns-schema.php`, `class-mns-db.php`
- `includes/services/class-mns-catalog-v4.php`, `class-mns-services-seed.php`, `class-mns-services.php`, `class-mns-order-items.php`
- `admin/class-mns-admin-services.php`
- E2E / role-switch / admin manual+orders / AC-M3-1

## بررسی زنده

URLهای پروتکل RUN-A §۶ → HTTP 200، بدون Fatal/Parse. Migration واقعی و شمارش seed روی develop را ناظر با self-test تأیید کند (tools.php ممکن است upstream-404 باشد).

## انحراف

تیتر brief «۴۸» vs §۵ «۴۴» → QUESTION-1.
