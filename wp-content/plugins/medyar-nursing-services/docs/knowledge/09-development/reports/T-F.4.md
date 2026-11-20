# T-F.4 — ادمین مالی و پیامک پرداخت موفق

تاریخ: 2026-11-19 · پلاگین: `0.7.0`

## صفحهٔ مالی (`MNS_Admin_Finance`)

| بخش | محتوا |
|-----|--------|
| تب سهم پرستار | ستون **روش / پیگیری**: برچسب «زرین‌پال» برای `method=zarinpal` + `tracking` (ref_id) |
| تب «تلاش‌های نیازمند پیگیری» | فقط‌خواندنی از `MNS_Payments::list_attention()` |
| وضعیت‌های فهرست | `paid_duplicate`، `amount_mismatch`، **`paid_error`** (اصلاح ناظر) |
| بازپرداخت خودکار | ندارد (عمدی) |

منبع لیست (پس از اصلاح ناظر در `class-mns-payments.php`):

```sql
WHERE status IN ('paid_duplicate','amount_mismatch','paid_error')
```

## پیامک پرداخت موفق

- فقط پس از `finalize_attempt` با outcome=`success`
- `MNS_Sms::send( MNS_Sms::EVT_PAYMENT_OK, … )` با idempotency `mns:payment_ok:{invoice_id}:{phone}`
- بدون پترن پیکربندی‌شده ⇒ زیرساخت موجود فقط لاگ می‌کند (رفتار `MNS_Sms`)
- بدون شماره ⇒ `sms_payment_ok_no_phone` در لاگ

## فایل‌ها

- `admin/class-mns-admin-finance.php` (ستون روش/پیگیری + تب attention شامل `paid_error`)
- `includes/payments/class-mns-payments.php` (`list_attention`, `sms_payment_ok`) — **بازنویسی نشد**؛ اصلاح ناظر حفظ شد
- `includes/services/class-mns-sms.php` (`EVT_PAYMENT_OK = payment_ok`)
