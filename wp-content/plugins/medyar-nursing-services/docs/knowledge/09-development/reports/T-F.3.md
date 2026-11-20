# T-F.3 — بازگشت درگاه و نهایی‌سازی

تاریخ: 2026-11-19 · محیط: develop

## Callback

`GET /mns/v1/payments/return?Authority=&Status=` — عمومی؛ اعتبار از lookup `authority`

| مسیر | رفتار |
|------|--------|
| authority نامعتبر | redirect عمومی خطا |
| `Status != OK` | attempt `failed`؛ «پرداخت لغو شد» |
| `Status=OK` + verify ۱۰۰/۱۰۱ | `record_payment(..., 'zarinpal', ref, actor=0)`؛ attempt `paid` |
| فاکتور دیگر unpaid نیست | `paid_duplicate` + لاگ هشدار بازپرداخت دستی |
| مبلغ نامنطبق | `amount_mismatch` + لاگ |
| replay بعد از paid | بی‌اثر؛ redirect موفق |
| پاسخ | همیشه `private, no-store`؛ بدون مبلغ/کارت در query |

## Cron

Hook `mns_payment_attempts_sweep` هر ۱۵ دقیقه: pending قدیمی‌تر از ۳۰ دقیقه → `inquiry` → finalize یا `expired`.  
ثبت با `wp_next_scheduled`؛ حذف در deactivate.

## تأیید self-test (Fake gateway)

| AC | نتیجه |
|----|--------|
| AC-M6-2 | PASS |
| AC-M6-3 | PASS |
| AC-M6-4 | PASS |
| AC-M6-5 | PASS |
| AC-M6-6 | PASS |
| AC-M6-7 | PASS |
| AC-M6-8 | PASS |
