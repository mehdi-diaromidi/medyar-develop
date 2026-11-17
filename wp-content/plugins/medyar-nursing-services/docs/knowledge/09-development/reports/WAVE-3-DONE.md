# WAVE-3 DONE — M5 مالی

تاریخ: 2026-11-18 · پلاگین `0.6.1` · schema `MNS_Schema::LATEST = 2`

## هدف موج
جداول invoices/payments، صدور فاکتور، پرداخت دستی (+ مسیر زرین‌پال به‌عنوان method)، سهم پرستار، منوی مالی v1، صفحات بیمار از دادهٔ زنده.

## تحویل

| مورد | نتیجه | جزئیات |
|------|--------|--------|
| Migration schema v2 | PASS | `invoices` · `payments` (+ جداول موج ۴ در همان نسخه) |
| صدور / پرداخت | PASS | `MNS_Invoices::issue_for_order` · `record_payment` · transition `invoice`/`pay` |
| سهم پرستار → MTW | PASS (hook) | `do_action( 'mns_nurse_share_credited', … )` پس از پرداخت؛ لیست سهم در تب finance |
| ادمین مالی | PASS | `MNS_Admin_Finance` تب‌های unpaid / share / reports (الگوی ARP) |
| بیمار invoices | PASS | `page-invoices.php` مسیر `is_real_panel_user` از DB |

## محدودیت
- درگاه زرین‌پال واقعی (redirect/callback) هنوز method ثبت دستی است؛ یکپارچه‌سازی کامل PSP جداگانه.
- واریز خودکار کیف پول وابسته به وجود/اتصال MTW در محیط.

## Commit / push
هیچ.
