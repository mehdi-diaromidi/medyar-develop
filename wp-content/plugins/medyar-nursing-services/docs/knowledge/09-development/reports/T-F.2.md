# T-F.2 — شروع پرداخت (API + UI)

تاریخ: 2026-11-19 · محیط: develop

## API

`POST /mns/v1/invoices/{number}/pay` · `permission_patient` · rate limit

- مبلغ فقط از ردیف فاکتور؛ ورودی مرورگر نادیده
- غیرمالک ⇒ ۴۰۴؛ غیر `unpaid` / `dispute` ⇒ `ERR_BAD_STATE`
- سفارش باید `invoiced`
- تلاش `pending` کمتر از ۱۵ دقیقه ⇒ همان `redirect_url` (بدون انبوه تلاش)
- `callback_url` ثابت: `rest_url('mns/v1/payments/return')`
- پاسخ: `Cache-Control: private, no-store`؛ خروجی `{redirect_url}`
- خطای درگاه ⇒ attempt `failed` + پیام فارسی

## UI

`page-invoice-view.php` دکمه `[data-mns-invoice-pay]` → API با `?_envelope=1` و nonce؛ هدایت به `redirect_url`.  
ادمین/پیش‌نمایش: رفتار قبلی. ساختار/CSS بدون تغییر.

## فایل‌ها

- `includes/payments/class-mns-payments.php` (`start_pay`)
- `includes/rest/class-mns-rest-payments.php`
- `public/views/patient/page-invoice-view.php`

## تأیید

AC-M6-1 PASS (`amount_locked;owner_404;bad_state;reuse_ok`) — بدون پرداخت واقعی.
