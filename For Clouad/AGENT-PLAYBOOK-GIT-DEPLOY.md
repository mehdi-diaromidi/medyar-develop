# دستورالعمل ایجنت — گرفتن ریپوی مدیار و Deploy خودکار

این متن را عیناً به ایجنت‌های دیگر بده. هدف: کلون/اتصال به همان Repository، رعایت همان محدودهٔ Deploy، و بردن تغییرات به سرور **فقط از مسیر GitHub Actions** (نه دور زدن گیت).

---

## ۱. مشخصات ثابت پروژه

| مورد | مقدار |
|------|--------|
| مسیر محلی (مرجع) | `c:\Projects\medyar-develop` |
| Repository | `https://github.com/mehdi-diaromidi/medyar-develop.git` (Private) |
| Branch Deploy | `main` |
| سایت توسعه | `https://develop.medyarco.com` |
| Workflow | `.github/workflows/deploy.yml` («Deploy to host») |
| اسکریپت | `.github/scripts/deploy.sh` (lftp + FTPS) |
| مستند انسانی | `DEPLOYMENT.md` |
| دانش کامل پروژه | `For Clouad/MEDYAR-PROJECT-KNOWLEDGE.md` |

**نویسندهٔ کامیت (بدون تغییر git config سراسری):**
```
GIT_AUTHOR_NAME=M.Mehdi
GIT_AUTHOR_EMAIL=m.mehdi@medyarco.com
GIT_COMMITTER_NAME=M.Mehdi
GIT_COMMITTER_EMAIL=m.mehdi@medyarco.com
```

---

## ۲. شروع کار روی ماشین (یک‌بار)

### ۲.۱ اگر پوشهٔ پروژه از قبل هست

```powershell
cd c:\Projects\medyar-develop
git remote -v
# باید ببینی: origin → https://github.com/mehdi-diaromidi/medyar-develop.git
git fetch origin
git checkout main
git pull origin main
git status
```

### ۲.۲ اگر باید کلون تازه بگیری

```powershell
cd c:\Projects
git clone https://github.com/mehdi-diaromidi/medyar-develop.git
cd medyar-develop
git checkout main
```

احراز هویت از Git Credential Manager / حساب GitHub کاربر انجام می‌شود. رمزها را چاپ/لاگ/کامیت نکن.

### ۲.۳ چیزهایی که در Git نیستند و نباید وارد شوند

- `.ftp.env` (رمز FTP) — اگر لازم شد، کاربر خودش می‌سازد؛ محتوایش را هرگز چاپ نکن.
- `.vscode/sftp.json` (ممکن است `uploadOnSave: true` داشته باشد و گیت را دور بزند).
- `/deploy.ps1` و `/deploy.sh` محلی (gitignored) — راه اصلی Deploy نیستند.
- `token-optimizer/`، `For Cursor/`، افزونه‌های third-party، `wp-config.php`، zipها، sql، log.

---

## ۳. محدودهٔ نسخه‌گذاری و Deploy (سخت)

فقط این‌ها وارد Git می‌شوند و روی هاست آپلود می‌شوند (whitelist در `.gitignore`):

**افزونه‌ها:**
- `wp-content/plugins/affiliate-referral`
- `wp-content/plugins/lab-booking-system`
- `wp-content/plugins/medical-records`
- `wp-content/plugins/medyar-chat-pro`
- `wp-content/plugins/medyar-myaccount-dashboard`
- `wp-content/plugins/medyar-nursing-services`
- `wp-content/plugins/medyar-sms-queue`
- `wp-content/plugins/medyar-toman-wallet`
- `wp-content/plugins/pharmacy-pro-system`

**قالب:**
- `wp-content/themes/dr-plus` (بدون `log.txt`)

**همراه پروژه (Deploy نمی‌شوند روی سایت، ولی در ریپو هستند):** `docs/`، `desgin/`، `For Clouad/`، `.cursor/rules/`، `.github/`، `DEPLOYMENT.md`

**هرگز روی هاست حذف خودکار نکن.** Workflow فقط آپلود می‌کند. فایل حذف‌شده از Git فقط در لاگ Actions گزارش می‌شود.

---

## ۴. بردن تغییر به سرور (روال هر روز)

```powershell
cd c:\Projects\medyar-develop
git status
git add <مسیرهای مرتبط>   # یا git add -A فقط اگر مطمئنی چیز محرمانه نیست
$env:GIT_AUTHOR_NAME='M.Mehdi'
$env:GIT_AUTHOR_EMAIL='m.mehdi@medyarco.com'
$env:GIT_COMMITTER_NAME='M.Mehdi'
$env:GIT_COMMITTER_EMAIL='m.mehdi@medyarco.com'
git commit -m "پیام کوتاه انگلیسی یا فارسی؛ تمرکز روی چرا"
git push origin main
```

بعد از Push:
1. برو به Actions ریپو و منتظر تیک سبز بمان.
2. روی هاست، فایل `/.deploy-revision` باید با SHA همان Commit یکی شود.
3. صفحهٔ مربوط را hard-reload کن (برای MNS: `?mns_nocache=1` و در صورت نیاز bump نسخهٔ asset).

**اولین Deploy کامل انجام شده است.** Deployهای بعدی incremental هستند (فقط فایل‌های تغییرکرده نسبت به `.deploy-revision`).

---

## ۵. افزودن افزونه/قالب اختصاصی جدید به Deploy

1. کد را زیر `wp-content/plugins/<name>/` (یا themes) بگذار.
2. در `.gitignore` یک خط اضافه کن: `!/wp-content/plugins/<name>/`
3. Commit + Push.
4. جدول محدوده در `DEPLOYMENT.md` را هم به‌روز کن.

---

## ۶. Secrets و Variables گیت‌هاب (نباید دوباره از صفر ساخته شوند مگر خراب شده باشند)

مسیر: Repository → Settings → Secrets and variables → Actions

**Secrets:** `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`  
**Variables:**
- `FTP_PROTOCOL` = `ftps`
- `FTP_PORT` = `21`
- `FTP_SERVER_DIR` = `/`  ← ریشهٔ اکانت FTP = ریشهٔ وردپرس
- `FTP_TLS_FINGERPRINT` = اثرانگشت SHA-256 گواهی self-signed سرور (در `DEPLOYMENT.md` هست)
- `DEPLOY_DRY_RUN` = `false` برای Deploy واقعی؛ `true` فقط شبیه‌سازی

اگر Variable یا Secret را عوض کردی، مقدار رمز را در چت چاپ نکن.

---

## ۷. قوانین اجباری برای ایجنت

1. **Deploy اصلی = Push به `main`.** از `deploy.ps1` / SFTP uploadOnSave فقط وقتی کاربر صریحاً بخواهد؛ وگرنه سرور از Git جلو می‌افتد.
2. **هیچ رمز، کلید API، `.ftp.env`، یا محتوای `sftp.json` را چاپ/کامیت نکن.**
3. **روی هاست فایل/پوشه پاک نکن** مگر کاربر مسیر دقیق را بدهد (مثل همان ۷ فایل ریشه که یک‌بار حذف شدند).
4. **داخل افزونه‌های اختصاصی `.git` تو در تو نساز.** قبلاً `.git`های `medyar-nursing-services` و `medyar-sms-queue` به `C:\Projects\_git-backups\` منتقل شده‌اند.
5. **فایل‌های shell/workflow باید LF باشند** (`.gitattributes`: `*.sh` و `*.yml` با `eol=lf`). CRLF باعث می‌شود `set -o pipefail` روی runner بشکند.
6. **`* -text` در `.gitattributes` را برندار** — فایل‌های رمزگذاری‌شدهٔ `dr-plus` با تبدیل EOL خراب می‌شوند.
7. قبل از ادعا کردن «Deploy شد»، Actions سبز + در صورت نیاز وجود/به‌روز بودن `/.deploy-revision` را چک کن.
8. PowerShell ویندوز: به‌جای `&&` از `;` استفاده کن.
9. پچ روی قالب/پلاگین ثالث را در همان نوبت در `docs/THEME-PATCHES-AFTER-UPDATE.md` یا `docs/MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md` مستند کن (قانون `.cursor/rules/document-patches-after-update.mdc`).
10. اگر Workflow شکست خورد: لاگ Actions را بخوان؛ اشتباهات شناخته‌شده در `DEPLOYMENT.md` بخش عیب‌یابی و در `For Clouad/MEDYAR-PROJECT-KNOWLEDGE.md` بخش Git/Deploy ثبت شده‌اند.

---

## ۸. چک‌لیست قبل از Commit

- [ ] فقط فایل‌های داخل محدودهٔ whitelist تغییر کرده‌اند (یا docs/desgin مجاز).
- [ ] هیچ secret / zip حجیم / wp-config در `git status` نیست.
- [ ] برای CSS/JS پلاگین MNS در صورت نیاز `MNS_VERSION` بالا رفته.
- [ ] پیام Commit واضح است.
- [ ] بعد از Push، Actions را چک کرده‌ای.

---

## ۹. دستورات تشخیص سریع

```powershell
cd c:\Projects\medyar-develop
git status
git log --oneline -5
git remote -v
# مقایسهٔ SHA محلی با مارکر هاست (رمز را چاپ نکن؛ فقط نتیجه)
```

نتیجهٔ سالم بعد از Deploy موفق:
- Actions: success
- `/.deploy-revision` روی هاست = `git rev-parse HEAD`
- صفحهٔ هدف 200 و بدون Fatal/critical error

---

## ۱۰. چه کاری را دوباره انجام نده

- دوباره راه‌اندازی کامل Secrets/Variables از صفر (مگر کاربر بگوید خراب شده).
- Deploy کامل اجباری همهٔ فایل‌ها بدون دلیل (incremental کافی است).
- حذف zipهای باقی‌مانده یا فایل‌های مشکوک ریشه بدون لیست دقیق از کاربر.
- ادغام/بازنویسی کورکورانهٔ تغییرات uncommitted ایجنت دیگر روی همان پوشه — اول `git status` و هماهنگی با کاربر.

پایان دستورالعمل.
