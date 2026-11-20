# RUN-F-DONE — پرداخت آنلاین فاکتور با زرین‌پال (M5)

تاریخ: 2026-11-19 · پلاگین: `0.7.0` · **بدون commit/push** · **بدون پرداخت واقعی**

## خلاصه کارت‌ها

| کارت | وضعیت |
|------|--------|
| T-F.1 Migration v3 + gateway | DONE — `reports/T-F.1.md` |
| T-F.2 start pay API/UI | DONE — `reports/T-F.2.md` |
| T-F.3 return + finalize + cron | DONE — `reports/T-F.3.md` |
| T-F.4 admin + SMS | DONE — `reports/T-F.4.md` |
| T-F.5 nurse → wallet | DONE — `reports/T-F.5.md` |
| T-F.6 AC-M6 self-test | DONE — `reports/T-F.6.md` |

## فایل‌های اصلی (غیر موقت)

- `includes/db/class-mns-schema.php` (`LATEST=3`, `sql_v3` → `payment_attempts`)
- `includes/payments/class-mns-payment-gateway.php` (+ Zarinpal / Fake)
- `includes/payments/class-mns-payments.php` (**اصلاح ناظر حفظ شد**: `paid_error`, `list_attention`, `handle_return`/`skipped`)
- `includes/payments/class-mns-nurse-wallet.php`
- `includes/rest/class-mns-rest-payments.php` (فایل اصلی؛ autoloader → همین)
- `includes/class-mns-selftest-pay.php`
- `admin/class-mns-admin-finance.php`
- `public/views/patient/page-invoice-view.php` + `public/class-mns-patient-invoices.php` (`zarinpal` label)

## Schema روی develop

- گزینه/نمایش ادمین: **نسخهٔ schema: 3 / آخرین: 3**
- جدول `Dj3i_mns_payment_attempts` موجود

## فرضیات

- مبلغ فاکتور و attempt به **تومان** است؛ زرین‌پال با `currency=IRT` مثل MTW (بدون `card_pan`).
- مرچنت از `mtw_zarinpal_merchant_id` یا Woo ZPal؛ در لاگ/پاسخ چاپ نمی‌شود.
- تست زنده با پول واقعی را مالک انجام می‌دهد.

## نتیجهٔ self-test

Suite مالی روی develop: **PASS=13 FAIL=0**؛ همهٔ **AC-M6-1…8** PASS با درگاه Fake. جزئیات: `T-F.6.md`.

## پاک‌سازی فایل‌های موقت

| مسیر | محلی | سرور FTP |
|------|--------|----------|
| `…/mns-runf-probe.php` | حذف | DELETED (250) |
| `…/runf-exec-rf1.php` | حذف | DELETED (250) |
| `…/runf-marker-rf1.txt` | حذف | DELETED (250) |
| `…/includes/rest/class-mns-rest-payments-v3.php` | حذف | از قبل نبود (550) |

### Grep پس از پاک‌سازی (داخل پلاگین)

الگوی `mns-runf-probe|runf-exec-rf1|runf-marker|rest-payments-v3|mnsRunf|runf-mu|/runf-marker` → **No matches found**.

Autoloader: `'MNS_REST_Payments' => 'includes/rest/class-mns-rest-payments.php'`.

### تأیید HTTP پس از حذف

| URL | نتیجه |
|-----|--------|
| `/wp-content/plugins/medyar-nursing-services/mns-runf-probe.php` | غیر ۲۰۰ (fetch fail / unavailable) |
| `…/runf-marker-rf1.txt` | **404** |
| `…/runf-exec-rf1.php` | **404** |
| `/nursing-patient-panel/` | **200** |
| `/nursing-nurse-panel/` | **200** |

## پرسش‌های باز برای مالک

- تست واقعی StartPay روی develop اگر دامنهٔ callback با مرچنت ثبت‌شده نخواند (`-14`/`-18`)
- پیکربندی پترن پیامک `payment_ok` در صف پیامک

**توقف RUN-F.**
