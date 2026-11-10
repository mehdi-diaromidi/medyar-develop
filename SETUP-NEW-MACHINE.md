# راه‌اندازی پروژه روی سیستم جدید

بدون هیچ رمز یا توکنی. فایل‌های محرمانه را جدا و امن منتقل کنید.

## ۱. دریافت کد
```bash
git clone https://github.com/mehdi-diaromidi/medyar-develop.git
cd medyar-develop
git config user.name "M.Mehdi"
git config user.email "m.mehdi@medyarco.com"
```
Repository خصوصی است: با `gh auth login` یا Personal Access Token وارد شوید.
فقط پلاگین‌های مدیار و قالب `dr-plus` در گیت هستند (هستهٔ وردپرس، دیتابیس و آپلودها نیستند).

## ۲. فایل‌های محلی که در گیت نیستند (از سیستم قبلی منتقل کنید)
- `.ftp.env`: اطلاعات FTP (محرمانه؛ با کانال امن، هرگز commit نشود)
- `deploy.ps1` / `deploy.sh`: اسکریپت آپلود دستی فایل‌های تغییرکرده
- `For Cursor/` و `token-optimizer/` (اختیاری)

## ۳. تنظیم Claude Code (nova و حافظه)
کپی آماده در همین مخزن است: `docs/claude-setup/`.

nova (مهارت شخصی؛ فقط وقتی بگویید «nova» اجرا می‌شود):
```
docs/claude-setup/nova/  →  %USERPROFILE%\.claude\skills\nova\
```

حافظهٔ پروژه:
```
docs/claude-setup/memory/  →  %USERPROFILE%\.claude\projects\<نام-پوشهٔ-پروژه-با-خط‌تیره>\memory\
```
نام پوشهٔ `projects\...` از مسیر پروژه ساخته می‌شود (مثلاً `C:\Projects\medyar-develop` ← `C--Projects-medyar-develop`). یک بار Claude Code را در پوشهٔ پروژه باز کنید تا این پوشه ساخته شود، بعد فایل‌ها را داخل `memory` بگذارید.

اگر nova یا حافظه را در سیستم قدیمی تغییر دادید، نسخهٔ `docs/claude-setup/` را هم به‌روز کنید.

## ۴. قواعد کار
- تا نگفته‌اید commit/push نمی‌شود.
- Push به `main` با GitHub Actions به هاست develop دیپلوی می‌شود (جزئیات: `DEPLOYMENT.md`). آپلود دستی فقط با `deploy.ps1` (در PowerShell، نه Bash).
- صفحهٔ ثبت سفارش بیمار (`mns_page=new-order`) مرجع تأییدشدهٔ ظاهر و UX برای همهٔ صفحه‌هاست.
- cache سایت یک‌ساله است؛ نسخهٔ asset ها با mtime فایل عوض می‌شود.
