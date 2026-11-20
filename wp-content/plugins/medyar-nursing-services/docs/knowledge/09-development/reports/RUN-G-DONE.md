# RUN-G-DONE — کاتالوگ جدید، ویزارد، eligibility، snapshot

تاریخ: 2026-11-21 · پلاگین: medyar-nursing-services · محیط هدف: develop.medyarco.com  
commit/push: **انجام نشد** (طبق brief)

## وضعیت کارت‌ها

| کارت | وضعیت | گزارش |
|------|--------|--------|
| T-G.1 | پیاده + آپلود | `T-G.1.md` |
| T-G.2 | پیاده + آپلود | `T-G.2.md` |
| T-G.3 | پیاده + آپلود | `T-G.3.md` |
| T-G.4 | پیاده + آپلود | `T-G.4.md` |
| T-G.5 | پیاده + آپلود | `T-G.5.md` |
| T-G.6 | پیاده + آپلود | `T-G.6.md` |

## Schema / Seed

- `MNS_Schema::LATEST = 4` (`order_items` + فلگ‌های خدمت)
- Seed: ۱۰ دسته / **۴۴** زیرخدمت §۵؛ قیمت‌های آزمایشی §۴؛ فلگ‌های §۲
- Legacy `serum`/`injection`: ردیف موجود، غیرفعال

## فرضیات §۲

همهٔ A-1…A-5 در seed/کد اعمال شدند و در گزارش‌های کارت ذکر شده‌اند.

## QUESTIONهای باز

- `QUESTION-1.md` — ۴۸ vs ۴۴ زیرخدمت
- `QUESTION-2.md` — عمق UI تعاملی چک‌لیست زنده

## Self-test

ثبت‌شده: `AC-G-1` … `AC-G-6` (+ به‌روزرسانی `AC-M3-1`).  
**اجرا روی develop توسط این عامل انجام نشد** (tools.php/upstream محدودیت قبلی؛ ناظر اجرا می‌کند). سناریوهای پوشش: پیش‌فرض همه، محدودیت صریح، manual، multi-item+extra، checklist groups، snapshot بدون PII.

## بررسی زنده (خودکار)

همهٔ URLهای پروتکل RUN-A §۶ → HTTP **200**، بدون Fatal/Parse/critical در بدنه.

## پاکسازی probe

- `wp-content/mu-plugins/mns-runf-probe.php` از دیسک و سرور (FTP DELETE) حذف شد.
- فایل موقت/probe جدیدی برای RUN-G ساخته نشد.

## فایل‌های اصلی (نمونه)

`class-mns-schema.php`, `class-mns-catalog-v4.php`, `class-mns-services*.php`, `class-mns-order-items.php`, `class-mns-orders.php`, `class-mns-eligibility.php`, `class-mns-availability.php`, `class-mns-availability-snapshot.php`, `class-mns-rest-patient.php`, `class-mns-execution.php`, `class-mns-selftest-catalog.php`, `page-new-order.php`, `page-dashboard.php`, `page-checklist.php`, `page-availability.php`, `public.js`, `public.css`, admin services/manual/orders, E2E, role-switch.

## توقف

طبق brief — ناظر self-test و UI مرورگر را بازبینی می‌کند.
