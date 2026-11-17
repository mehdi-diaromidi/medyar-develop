# WAVE-4 DONE — M6–M7 surveys / loyalty / recurring

تاریخ: 2026-11-18 · پلاگین `0.6.1`

## هدف موج
نظرسنجی، مهر وفاداری، برنامهٔ دوره‌ای؛ برداشتن soon-gate برای کاربر واقعی؛ اتصال دادهٔ زنده به صفحات بیمار.

## تحویل

| مورد | نتیجه | جزئیات |
|------|--------|--------|
| Surveys | PASS | جدول `surveys` · `MNS_Surveys` · باز شدن پس از `end` · map برای UI بیمار |
| Loyalty | PASS | `loyalty_stamps` · `MNS_Loyalty` · مهر پس از پرداخت فاکتور · `page-loyalty.php` زنده |
| Recurring | PASS | `recurring_plans` · `MNS_Recurring::for_account` · `page-recurring.php` زنده |
| Soon-gate | PASS | مسیر واقعی بدون `soon_for_real` برای invoices/loyalty/recurring/surveys و مراحل پرستار |
| Ratings پرستار | SKIP | `page-ratings.php` طبق قید پلن دست نخورده |

## محدودیت
- ثبت امتیاز پرسشنامه از UI هنوز mock/فرم fixture برای ادمین پیش‌نمایش؛ لیست open/done زنده است.
- ساخت برنامهٔ دوره‌ای از ادمین UI کامل (CRUD) در این موج فقط خواندن سمت بیمار است.

## Commit / push
هیچ.
