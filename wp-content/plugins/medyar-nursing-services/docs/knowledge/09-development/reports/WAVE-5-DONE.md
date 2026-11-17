# WAVE-5 DONE — M8 عمق ادمین + M9 cutover

تاریخ: 2026-11-18 · پلاگین `0.6.1`

## هدف موج
CSV گزارش‌ها، فیلتر رصد، صف سهم/برداشت (لینک/خواندن)، خاموش‌کردن `MNS_UI_STATIC`، hardening سبک، مستند status.

## تحویل

| مورد | نتیجه | جزئیات |
|------|--------|--------|
| CSV | PASS | `MNS_Export_Csv` از مالی (orders/invoices) و قابل فراخوانی از monitor page |
| فیلتر رصد | PASS | وضعیت / تاریخ / پرستار / منبع / جستجو در List_Table + پین needs_review/unassigned |
| صف برداشت / سهم | PASS (خواندنی) | تب share در finance؛ hook اعتبار سهم |
| Cutover UI static | PASS | `MNS_UI_STATIC` پیش‌فرض `false` در `medyar-nursing-services.php` |
| مستند | PASS | `RUN-E-DONE.md` + گزارش‌های WAVE-1…5 |
| نسخه | PASS | `0.6.1` برای bust کش assets |

## محدودیت / ناظر
- AC مرورگر کامل E2E و پاک‌سازی E2E ⇒ ناظر (`RUN-E-DONE` PARTIAL برای T-E.6).
- تعرفه/شهربندی/مصرفی عمیق brief هنوز از settings registry مدیریت می‌شود؛ UI تخصصی جدا ساخته نشد.
- Harden کامل امنیت/عملکرد M9 به‌صورت مرور کد در تحویل موج؛ audit مستقل توصیه می‌شود.

## Commit / push
هیچ.
