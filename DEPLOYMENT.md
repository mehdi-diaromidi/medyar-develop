# Deploy خودکار Medyar با GitHub Actions

با هر Push به Branch `main`، فایل‌های تغییرکردهٔ `wp-content` (افزونه‌ها و قالب‌های داخل Repository) از طریق **FTPS** روی هاست آپلود می‌شوند.

- Workflow: `.github/workflows/deploy.yml`
- اسکریپت Deploy: `.github/scripts/deploy.sh` (بر پایهٔ `lftp`)
- Repository: <https://github.com/mehdi-diaromidi/medyar-develop> (Private)

---

## ۱. سیستم چطور کار می‌کند

1. Push به `main` (یا اجرای دستی از تب **Actions**) Workflow را اجرا می‌کند.
2. کد با کل تاریخچه Checkout می‌شود و `lftp` نصب می‌شود.
3. اتصال به هاست تست می‌شود: ورود، باز کردن مسیر مقصد و اطمینان از وجود پوشهٔ `wp-content` در آن (اگر نباشد، Deploy متوقف می‌شود تا فایل‌ها در مسیر اشتباه آپلود نشوند).
4. فایل `.deploy-revision` روی هاست (در ریشهٔ مقصد) خوانده می‌شود؛ این فایل شناسهٔ آخرین Commit دیپلوی‌شده را نگه می‌دارد.
   - اگر وجود داشته باشد ← فقط فایل‌های **اضافه/تغییرکرده** بین آن Commit و Commit فعلی آپلود می‌شوند.
   - اگر وجود نداشته باشد (اولین Deploy) ← همهٔ فایل‌های `wp-content` داخل Repository آپلود می‌شوند.
5. هر فایل اول با نام موقت آپلود و بعد Rename می‌شود، تا سایت هیچ‌وقت یک فایل PHP نیمه‌کاره را اجرا نکند.
6. بعد از موفقیت، `.deploy-revision` به Commit فعلی به‌روز می‌شود. اگر آپلود شکست بخورد این فایل تغییر نمی‌کند و اجرای بعدی همان فایل‌ها را دوباره می‌فرستد.

### چه چیزی آپلود می‌شود

فقط فایل‌های **Track‌شده در Git** زیر `wp-content/`. خود `.gitignore` به‌صورت Whitelist تعیین می‌کند چه چیزی وارد Repository شود:

| مسیر | توضیح |
|------|-------|
| `wp-content/plugins/affiliate-referral` | اختصاصی Medyar |
| `wp-content/plugins/medyar-chat-pro` | اختصاصی Medyar |
| `wp-content/plugins/medyar-myaccount-dashboard` | اختصاصی Medyar |
| `wp-content/plugins/medyar-nursing-services` | اختصاصی Medyar |
| `wp-content/plugins/medyar-sms-queue` | اختصاصی Medyar |
| `wp-content/plugins/medyar-toman-wallet` | اختصاصی Medyar (شامل `assets/vendor`) |
| `wp-content/plugins/lab-booking-system` | سفارشی پروژه |
| `wp-content/plugins/medical-records` | سفارشی پروژه |
| `wp-content/plugins/pharmacy-pro-system` | سفارشی پروژه |
| `wp-content/themes/dr-plus` | قالب پچ‌شده (شامل `inc/Libs/vendor` و `inc/wallet/Libs/vendor`) |

**هرگز آپلود نمی‌شوند** (یا اصلاً وارد Git نمی‌شوند): `.git`، `.github`، `docs/`، `DEPLOYMENT.md`، `.ftp.env`، `.env*`، `.vscode/` (شامل `sftp.json` با رمز)، `deploy.ps1`، `deploy.sh`، `wp-config.php`، `*.zip`، `*.sql`، `*.log`، `error_log`، `node_modules/`، پوشه‌های `desgin`، `token-optimizer`، `For Cursor`، `For Clouad` و سایر افزونه‌های third-party.

> افزودن افزونه یا قالب جدید به Deploy: یک خط `!/wp-content/plugins/<نام>/` به `.gitignore` اضافه کنید، Commit و Push کنید.

`.gitattributes` تبدیل انتهای خط (CRLF/LF) را خاموش می‌کند تا فایل‌ها، به‌خصوص فایل‌های رمزگذاری‌شدهٔ قالب `dr-plus`، بایت‌به‌بایت همان نسخهٔ محلی باشند.

### فایل‌هایی که از Repository حذف می‌شوند

**روی هاست حذف نمی‌شوند.** Workflow فقط آپلود می‌کند و هیچ عملیات حذف یا پاک‌سازی پوشه انجام نمی‌دهد. فایل‌هایی که در Git حذف شده‌اند در لاگ و خلاصهٔ اجرا با هشدار **Files removed from Git** فهرست می‌شوند تا در صورت نیاز دستی (با FTP/File Manager) پاکشان کنید.

> حذف خودکار عمداً پیاده‌سازی نشده: اشتباه در مسیر مقصد یا `.gitignore` می‌تواند فایل‌های سایت زنده را پاک کند. اگر بعداً لازم شد، باید جداگانه تصمیم‌گیری و تأیید شود.

---

## ۲. اتصال به هاست

بررسی‌شده روی `3114551454.cloudylink.com` (سرور ProFTPD):

| پروتکل | وضعیت |
|--------|-------|
| SFTP (پورت 22) | در دسترس نیست |
| FTPS ضمنی (پورت 990) | در دسترس نیست |
| **FTPS صریح (AUTH TLS روی پورت 21)** | **پشتیبانی می‌شود ← استفاده می‌شود** |
| FTP ساده | فقط در صورت نیاز (رمز بدون رمزنگاری ارسال می‌شود) |

گواهی TLS سرور **Self-signed** است (`CN=s378.bitcommand.com`، انقضا 2027-05-04)، پس بررسی عادی CA شکست می‌خورد. به‌جای خاموش‌کردن کامل بررسی گواهی، اثرانگشت SHA-256 گواهی **Pin** می‌شود: اگر سرور گواهی دیگری ارائه کند، Deploy قبل از ارسال رمز متوقف می‌شود.

---

## ۳. تعریف Secrets و Variables در GitHub

مسیر: Repository ← **Settings** ← **Secrets and variables** ← **Actions**

### Secrets (تب Secrets ← New repository secret)

| نام | مقدار |
|-----|-------|
| `FTP_SERVER` | `3114551454.cloudylink.com` |
| `FTP_USERNAME` | نام کاربری FTP (همان کاربر فعلی FTP) |
| `FTP_PASSWORD` | رمز FTP |

Secretها در لاگ‌ها ماسک می‌شوند و بعد از ذخیره قابل مشاهده نیستند.

### Variables (تب Variables ← New repository variable)

| نام | مقدار | ضروری؟ |
|-----|-------|--------|
| `FTP_PROTOCOL` | `ftps` | پیش‌فرض `ftps` |
| `FTP_PORT` | `21` | پیش‌فرض 21 (برای sftp: 22) |
| `FTP_SERVER_DIR` | `/` | پیش‌فرض `/` — ریشهٔ وردپرس روی FTP |
| `FTP_TLS_FINGERPRINT` | `BE:B7:B2:48:36:29:5B:19:D9:B2:00:63:9B:D4:1D:33:FA:0F:73:B0:30:C6:F4:C9:8F:D0:45:E2:28:E9:85:02` | **بله** (چون گواهی Self-signed است) |
| `DEPLOY_DRY_RUN` | `true` (در شروع) ← بعداً `false` | پیش‌فرض `true` |
| `FTP_TLS_PROTECT_DATA` | `no` | فقط برای رفع خطا (پایین را ببینید) |
| `SFTP_KNOWN_HOSTS` | خروجی `ssh-keyscan` | فقط اگر روزی SFTP فعال شد |

### مسیر مقصد

ریشهٔ اکانت FTP همان **ریشهٔ وردپرس** است؛ بنابراین `wp-content/plugins/x/file.php` در Repository به `/wp-content/plugins/x/file.php` روی هاست می‌رود (`FTP_SERVER_DIR=/`). اگر اکانت FTP یا هاست تغییر کرد، فقط `FTP_SERVER_DIR` را عوض کنید (مثلاً `/public_html`).

---

## ۴. تست (Dry Run)

تا وقتی `DEPLOY_DRY_RUN` برابر `false` نشود، هر Push فقط **Dry Run** است:

- اتصال، ورود، TLS و مسیر مقصد واقعاً تست می‌شوند.
- فهرست فایل‌هایی که آپلود *می‌شدند* و فایل‌های حذف‌شده از Git نمایش داده می‌شود.
- **هیچ فایلی آپلود نمی‌شود** و `.deploy-revision` تغییر نمی‌کند.

اجرای دستی: تب **Actions** ← **Deploy to host** ← **Run workflow** ← تیک **Dry run** روشن ← **Run workflow**.

نتیجه: روی اجرای Workflow کلیک کنید؛ در **Summary** جدول خلاصه و فهرست فایل‌ها، و در مرحلهٔ **Deploy** لاگ کامل (بخش‌های تاشوی *Connection check*، *Files to upload* و *lftp mirror*) را ببینید.

---

## ۵. فعال‌کردن Deploy واقعی

1. یک Dry Run موفق (سبز) داشته باشید و فهرست فایل‌ها را بررسی کنید.
2. اولین Deploy واقعی **همهٔ** فایل‌های `wp-content` داخل Repository (حدود ۲۳۰۰ فایل، ~۳۰MB) را آپلود و نسخه‌های روی هاست را **بازنویسی** می‌کند. اگر مستقیماً روی هاست فایلی را ویرایش کرده‌اید که در نسخهٔ محلی نیست، قبلش آن را به پروژه منتقل کنید.
3. یکی از این دو:
   - یک‌باره: **Run workflow** با تیک **Dry run** خاموش.
   - دائمی: Variable `DEPLOY_DRY_RUN` را `false` کنید ← از این به بعد هر Push به `main` واقعاً Deploy می‌شود.
4. برگشت به حالت امن: `DEPLOY_DRY_RUN` را `true` کنید (یا حذفش کنید).

بعد از آن روال کار: تغییر کد ← `git add` ← `git commit` ← `git push`.

---

## ۶. امنیت و پایداری

- مجوز Workflow فقط `contents: read` است؛ توکن GitHub در Checkout ذخیره نمی‌شود (`persist-credentials: false`).
- رمز FTP از طریق متغیر محیطی `LFTP_PASSWORD` به lftp داده می‌شود و در خط فرمان یا لاگ ظاهر نمی‌شود.
- `concurrency: deploy-host` اجازهٔ دو Deploy هم‌زمان نمی‌دهد؛ اجرای جدید منتظر می‌ماند و اجرای در حال انجام لغو نمی‌شود.
- حداکثر زمان هر اجرا: ۶۰ دقیقه.
- فقط Actionهای رسمی GitHub (`actions/checkout@v4`) و بستهٔ رسمی `lftp` اوبونتو استفاده شده‌اند.
- فایل `.deploy-revision` در ریشهٔ سایت فقط یک شناسهٔ Commit دارد و اطلاعات محرمانه‌ای در آن نیست.

---

## ۷. عیب‌یابی

| پیام خطا | علت محتمل | راه‌حل |
|----------|-----------|--------|
| `Secret FTP_... is not set` | Secret تعریف نشده یا نامش غلط است | نام‌ها دقیقاً `FTP_SERVER`، `FTP_USERNAME`، `FTP_PASSWORD` |
| `FTPS certificate fingerprint mismatch` | هاست گواهی را تمدید کرده یا اتصال رهگیری شده | اثرانگشت جدید را از پشتیبانی هاست یا با ابزار مطمئن تأیید کنید، بعد `FTP_TLS_FINGERPRINT` را به‌روز کنید |
| `Could not read the FTPS certificate` | سرور در دسترس نیست / پورت اشتباه | `FTP_SERVER` و `FTP_PORT`؛ احتمال مسدودبودن IPهای GitHub در فایروال هاست |
| `Could not connect, log in, or open ...` | رمز/نام کاربری اشتباه، مسیر اشتباه، فایروال | پیام دقیق lftp بالای خطا (مثل `Login incorrect` یا `No such file`) را ببینید |
| `... has no wp-content directory` | `FTP_SERVER_DIR` ریشهٔ وردپرس نیست | مسیر را اصلاح کنید |
| خطای TLS در لیست‌گیری/انتقال داده (مثلاً `Fatal error: gnutls_handshake` یا قطع اتصال داده) | ProFTPD ممکن است TLS session reuse بخواهد | ابتدا دوباره اجرا کنید؛ اگر ادامه داشت `FTP_TLS_PROTECT_DATA=no` بگذارید (کانال فرمان و رمز همچنان رمزنگاری می‌مانند) |
| `Upload failed` | قطعی شبکه / سهمیهٔ دیسک / دسترسی | با **Re-run jobs** دوباره اجرا کنید؛ فایل‌های ناقص با نام موقت می‌مانند و سایت را خراب نمی‌کنند |
| Timeout یا اتصال رد شد | هاست IPهای GitHub Actions (خارج از ایران) را مسدود کرده | از پشتیبانی هاست بخواهید دسترسی FTP از خارج را باز کنند |

لاگ کامل: تب **Actions** ← اجرای موردنظر ← Job **Deploy wp-content** ← مرحلهٔ **Deploy**. برای دانلود لاگ: منوی ⋯ ← **Download log archive**.

اجرای مجدد: در صفحهٔ اجرا ← **Re-run jobs**. برای ارسال دوبارهٔ همه‌چیز، فایل `.deploy-revision` را از ریشهٔ هاست پاک کنید تا اجرای بعدی Full upload شود.
