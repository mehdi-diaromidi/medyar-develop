# WAVE-1 DONE — شِل ادمین ARP + بستن شکاف M3 ops

تاریخ: 2026-11-18 · پلاگین `0.6.1` · محیط: `develop.medyarco.com`

## هدف موج
همهٔ صفحات ادمین موجود روی استاندارد همکار (ARP)؛ منوهای stub عملیاتی با دادهٔ زنده.

## کارت‌ها / تحویل

| بخش | نتیجه | مسیرهای کلیدی |
|------|--------|----------------|
| ۱.۱ لایهٔ مشترک ادمین | PASS | `admin/class-mns-admin-ui.php` · `assets/css/admin.css` · `assets/js/admin.js` · `removable_query_args` |
| ۱.۲ پیشخوان زنده | PASS | `admin/views/dashboard.php` · `MNS_Admin_Stats` · KPI لینک‌دار + postbox |
| ۱.۳ رصد مرکزی P-22/P-23 | PASS | `MNS_Admin_Monitor` · `MNS_Orders_Table` · جزئیات + timeline + اکشن‌ها |
| ۱.۴ تخصیص + تلفنی P-21 | PASS | `MNS_Admin_Orders` (پیشنهاد ۵تایی/force) · `MNS_Admin_Manual` |
| ۱.۵ پرستاران/تنظیمات/خدمات/Tools | PASS | تب‌های ARP در settings · `mns-wrap` روی nurses/services/selftest/e2e/db-status/reset |
| ۱.۶ RUN-E ادمین/مرورگر | PARTIAL | `RUN-E-DONE.md` نوشته شد؛ T-E.6 مرورگر کامل وابسته به لینک ناظر |

## خروجی مورد انتظار پلن
- هیچ submenu عملیاتی stub نیست (monitor/assign/manual/finance/settings با callback واقعی جایگزین شده‌اند).
- ظاهر = استاندارد همکار (`wrap mns-wrap`, KPI, postbox, List_Table, tabs, notice whitelist).
- رصد / پیشخوان / تلفنی روی دادهٔ slice-1.

## حباب منو
`MNS_Admin_Menu::bubble_counts()` از `MNS_Admin_Stats::bubbles()` (DB)، نه fixture hub.

## محدودیت / باقی‌مانده
- تأیید بصری کامل مرورگر ادمین در این موج به‌صورت smoke روی FTP انجام شد؛ AC عمیق T-E.6 نیازمند `.e2e/links.json` ناظر است.
- `page-ratings.php` طبق قید دست نخورده.

## Commit / push
هیچ (طبق قید پلن).
