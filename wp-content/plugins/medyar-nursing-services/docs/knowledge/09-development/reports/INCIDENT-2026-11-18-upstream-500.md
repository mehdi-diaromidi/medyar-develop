# INCIDENT — Upstream 500 / Forbidden روی develop (2026-11-18)

## علائم
- IDE browser روی `/my-account/` و گاهی `/wp-admin/` → `Upstream Error - Internal Server Error` / `Forbidden`
- Homepage و جستجو برای همان کاربر لاگین‌شده OK بودند

## علت ریشه‌ای (دو لایه)
1. **بدافزار drop-in** در `wp-content/db.php` (SEO spam به `pointlose.com`): برای بخشی از UA/URI با `header 500; exit()` یا `403` پاسخ می‌داد. فایل حذف و بکاپ با نام `db.php.malware-bak-20261118` گرفته شد (ممکن است هاست بعداً پاکش کند).
2. **بار اضافهٔ ادمین MNS روی فرانت**: `Backend_Orders/Nurses/Catalog/...` کلاس‌های ادمین/`WP_List_Table` را روی storefront هم boot می‌کردند → فشار حافظه روی صفحات سنگین WC. Boot ادمین فقط با `is_admin()` ماند.

## اصلاحات ماندگار در MNS
- `is_admin()` gate برای boot ادمین (orders/monitor/manual/finance/export/nurses/services/settings/selftest/db-status)
- `@ini_set( 'memory_limit', '256M' )` + `wp_raise_memory_limit( 'admin' )` در bootstrap
- Probe موقت حذف شد

## تأیید ایزوله
- با **خاموش‌کردن کامل** پلاگین (rename پوشه به `.disabled-tmp`) همان `/my-account/` برای کاربر لاگین‌شده همچنان `Upstream 500` داد → **علت فعلی 500 از کد MNS نیست.**
- Anonymous `/my-account/` = 302 سالم به login
- Probe PHP: `status=500`, `err=null`, `uid=465`, `memory_limit=512M` (یعنی exit عمدی/لایهٔ میانی، نه fatal واضح MNS)

## اقدام ناظر (خارج از MNS)
- اسکن امنیتی کامل هاست (backdoor ممکن است غیر از `db.php` باشد)
- بررسی Wordfence / WCDN / LiteSpeed برای UA مرورگر اتوماسیون و مسیر `/my-account/`
- اطمینان از نبودن مجدد `wp-content/db.php` مخرب
- تست `/my-account/` در Chrome عادی (غیر Cursor browser) با همان کاربر
