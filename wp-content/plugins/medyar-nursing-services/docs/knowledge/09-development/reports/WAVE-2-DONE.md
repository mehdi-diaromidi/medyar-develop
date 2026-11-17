# WAVE-2 DONE — M4 اجرای خدمت

تاریخ: 2026-11-18 · پلاگین `0.6.1`

## هدف موج
گسترش ماشین سفارش برای start/end/needs_review، مراحل checklist/drug/report، REST پرستار، اکشن ادمین روی رصد.

## تحویل

| مورد | نتیجه | جزئیات |
|------|--------|--------|
| `MNS_Order_Machine` | PASS | `start` · `end` · `needs_review` · `complete` (+ invoice/pay برای موج بعد) |
| مراحل JSON | PASS | `MNS_Execution` روی `orders.steps`؛ unlock چک‌لیست/دارو در start، گزارش در end |
| REST پرستار | PASS | `POST /nurse/orders/{code}/start` · `/end` · `/steps/{checklist\|drug\|report}` |
| UI پرستار | PASS | تأیید شروع/پایان در `page-order-view` → REST زنده؛ صفحات checklist/drug/report بدون fixture برای کاربر واقعی |
| ادمین رصد | PASS | شروع/پایان خدمت · آنلاک مرحله · یادداشت staff |
| Cron پایان‌روز | PASS | `MNS_Ops_Cron` → `mns_daily_needs_review` برای `service_ended` |

## باگ‌های رفع‌شده حین موج
- `MNS_DB::transaction` وجود نداشت → alias به `tx`
- `$wpdb->prepare(..., $params)` آرایه‌ای → splat `...$params` در `simple_status_change`

## محدودیت
- فرم کامل checklist/drug/report هنوز UI سبک/empty-state زنده است؛ ذخیره از REST آماده است.
- باز کردن پرسشنامه پس از `end` (hook به Wave 4).

## Commit / push
هیچ.
