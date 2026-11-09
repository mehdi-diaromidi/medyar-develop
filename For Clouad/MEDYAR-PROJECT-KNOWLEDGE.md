# دانش کامل پروژهٔ مدیار (Medyar) — مرجع واحد برای هوش مصنوعی و توسعه‌دهنده

> آخرین به‌روزرسانی: ۱۹ آبان ۱۴۰۵ (۹ نوامبر ۲۰۲۶)
> این فایل همه‌چیزهایی است که تا این لحظه دربارهٔ پروژه می‌دانیم: زیرساخت، ابزار، پلاگین‌ها، قالب، پچ‌ها، پیامک، پروژهٔ پرستاری (MNS)، طراحی‌ها، تصمیم‌ها، ترجیحات کاربر، باگ‌ها، کارهای باز.
> **هیچ رمز، کلید API یا اطلاعات ورود در این فایل نیست و نباید اضافه شود.** هر جا اطلاعات محرمانه لازم است، فقط محل نگهداری‌اش ذکر شده.

---

## فهرست

0. خلاصهٔ یک‌دقیقه‌ای
1. محیط‌ها، آدرس‌ها، سرور
2. ساختار ریپوی محلی
3. ابزار کار و روند استقرار (Deploy) و راستی‌آزمایی
4. قوانین و ترجیحات کاری کاربر (Skills، Rules، تصمیم‌ها)
5. قالب dr-plus
6. فهرست پلاگین‌ها (سفارشی و ثالث)
7. پلاگین‌های سفارشی — جزئیات
8. نقشهٔ وابستگی بین پلاگین‌ها
9. پیامک: مالکیت، صف، mu-pluginها
10. پچ‌های مستقیم روی کد ثالث (باید بعد از آپدیت دوباره اعمال شوند)
11. پروژهٔ سامانهٔ خدمات پرستاری — بریف کسب‌وکار
12. پلاگین `medyar-nursing-services` (MNS) — مرجع فنی
13. طراحی‌ها و بریف‌های UI (پوشهٔ `desgin`)
14. وضعیت فعلی صفحات پنل بیمار (پیاده‌سازی‌شده)
15. تاریخچهٔ کار و نسخه‌ها
16. باگ‌ها، علت‌ها و درس‌ها (Gotchas)
17. TODOها، سؤال‌های باز کارفرما، ناهماهنگی اسناد
18. مشاهدات امنیتی و سلامت سرور
19. واژه‌نامه

---

## 0. خلاصهٔ یک‌دقیقه‌ای

- **مدیار** یک سایت وردپرسی فارسی/راست‌چین در حوزهٔ سلامت است: نوبت‌دهی پزشک/متخصص (قالب Doctor Plus)، چت بیمار‑متخصص، آزمایشگاه، داروخانه، پروندهٔ پزشکی، کیف پول تومانی، برنامهٔ همکاران (Affiliate)، صف پیامک، و پروژهٔ در حال ساخت **سامانهٔ خدمات پرستاری در منزل** (پلاگین `medyar-nursing-services` با پیشوند `MNS`).
- سایت اصلی: `https://medyarco.com` — سایت توسعه: `https://develop.medyarco.com`.
- ریپوی محلی: `c:\Projects\medyar-develop` (فقط `wp-content` + ابزار؛ **ریشه git نیست**). استقرار با FTP از طریق `deploy.ps1`.
- توسعه‌دهنده: **M.Mehdi Diaromidi** (گیت‌هاب: `mehdi-diaromidi`). زبان مکالمه فارسی، کد و شناسه‌ها انگلیسی.
- کار فعلی: بازطراحی پنل بیمار پرستاری مطابق PNGهای طراحی (داشبورد، سفارش‌های من، فاکتورها و پرداخت‌ها، ثبت سفارش). فرانت فعلاً **استاتیک و مبتنی بر fixture** است (`MNS_UI_STATIC=true`)، بک‌اند هنوز وصل نشده.
- آخرین نسخهٔ MNS: **0.5.13**.

---

## 1. محیط‌ها، آدرس‌ها، سرور

| مورد | مقدار |
|---|---|
| سایت اصلی (production) | `https://medyarco.com` |
| سایت توسعه | `https://develop.medyarco.com` |
| مسیر ورود ادمین (wps-hide-login) | `/medlogin/` |
| کاتالوگ خدمات پرستاری | `/nursing-services/` (تک‌خدمت: `?mns_service=<slug>`) |
| پنل بیمار پرستاری | `/nursing-patient-panel/?mns_panel=patient&mns_page=<slug>` |
| پنل پرستار | `/nursing-nurse-panel/?mns_panel=nurse&mns_page=<slug>` |
| عضویت پرستار | `/nursing-nurse-signup/` |
| هاب ادمین پرستاری | `/wp-admin/admin.php?page=mns-dashboard` (و `mns-monitor`، `mns-assign`، …) |
| پنل همکاران (ARP) | `/partner/?arp_page=dashboard` (زیرصفحه‌ها: `submit`, `referrals`, `commissions`, `invited`, `profile`) |
| صفحهٔ نمونهٔ متخصص (الهام تقویم) | `/specialist/drmaedehtaheri/` |
| شکستن کش هنگام تست | `?mns_nocache=1` و/یا `&v=<نسخه>` + بالا بردن `MNS_VERSION` (WP Rocket و LiteSpeed فعال‌اند) |

**سرور:**
- میزبانی اشتراکی (cPanel-مانند)، FTP روی پورت 21، کاربر FTP مختص `develop.medyarco.com`. اطلاعات اتصال فقط در `.ftp.env` (gitignored) — **هرگز چاپ/لاگ/کامیت نشود**.
- پیشوند جداول دیتابیس: **`Dj3i_`** (در `medical-records` به‌صورت هاردکد هم استفاده شده).
- روی سرور (نه در ریپوی محلی): پوشهٔ `wp-content/mu-plugins` با mu-pluginهای مدیار (بخش ۹)، `uploads`، `languages`، `cache`، `litespeed`، `wflogs`، `duplicator-backups`، `chat-uploads`.
- **۹ نوامبر ۲۰۲۶: سهمیهٔ دیسک FTP پر شد** (خطای `552 Exceeded storage allocation`). علت: zipهای بکاپ حجیم (بخش ۱۸). آپلود `public.css` نیمه‌کاره ماند و فایل روی سرور ۰ بایت شد. **رفع شد:** با اجازهٔ کاربر ۴ zip داخل `wp-content` (`plugins.zip`, `plugins-2.zip`, `plugins-3.zip`, `themes.zip` ≈ ۹۳۵MB) حذف و فایل‌ها دوباره آپلود شدند (اندازهٔ ریموت = محلی، صفحات 200 بدون خطا). اگر دوباره 552 دیدی، فوراً اندازهٔ فایل ریموت را چک کن.
- مرجع پشتیبان پچ‌ها (طبق سند پچ‌ها): `d:\medyar-main\`.

**گیت:**
- ریشهٔ `c:\Projects\medyar-develop` ریپوی git **نیست**.
- `wp-content/plugins/medyar-nursing-services/` ریپوی جدا دارد: `https://github.com/mehdi-diaromidi/medyar-nursing-services` (شاخه‌ها: `main`، `feature/front-static`). کامیت‌ها با متغیرهای محیطی `GIT_AUTHOR_*`/`GIT_COMMITTER_*` (بدون تغییر git config).
- `medyar-sms-queue` هم ریپوی `github.com/mehdi-diaromidi/medyar-sms-queue` (`main`) دارد.
- `token-optimizer/` یک کلون ثالث با `.git` خودش است.

---

## 2. ساختار ریپوی محلی

```
c:\Projects\medyar-develop
├─ .cursor/rules/document-patches-after-update.mdc   ← قانون: هر پچ روی کد ثالث باید در docs/ مستند شود
├─ .vscode/sftp.json        ← تنظیمات افزونهٔ SFTP (حاوی اطلاعات ورود؛ gitignored؛ محتوایش را نخوان/چاپ نکن)
├─ .ftp.env                 ← FTP_HOST / FTP_USER / FTP_PASS / FTP_ROOT / FTP_PORT (محرمانه)
├─ .gitignore               ← .ftp.env, deploy.sh, deploy.ps1, .vscode/sftp.json
├─ deploy.ps1 / deploy.sh   ← آپلود فایل‌های داده‌شده با FTP
├─ desgin/                  ← (با همین املای غلط) طراحی‌ها و بریف‌های UI — بخش ۱۳
│   ├─ png/ثبت سفارش/ , png/سفارش های من/ , png/فاکتورها و پرداخت ها/
│   └─ ui doc/ثبت سفارش.txt , سفارش های من.txt , فاکتورها و پرداخت ها.txt
├─ docs/                    ← اسناد پچ، پیامک، چت، بریف پروژهٔ پرستاری
├─ For Clouad/              ← همین فایل
├─ For Cursor/              ← (خالی)
├─ token-optimizer/         ← پکیج ثالث بهینه‌ساز توکن برای دستیارهای AI (نامرتبط با سایت)
└─ wp-content/
    ├─ plugins/             ← ۳۷ پلاگین (۱۰ سفارشی/پروژه‌ای) + چند zip سرگردان
    └─ themes/dr-plus/      ← قالب والد Doctor Plus (بدون child theme)
```

اسناد `docs/`:
- `MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md` — فهرست اصلی همهٔ پچ‌ها (بخش ۱۰)
- `THEME-PATCHES-AFTER-UPDATE.md` — پچ‌های قالب ۱ تا ۱۰
- `MEDYAR-SMS-BOOKING-AND-OWNERSHIP.md` — مالکیت پیامک‌ها
- `MEDYAR-SMS-QUEUE.md` — صف پیامک
- `CHAT.md` — پلاگین medyar-chat-pro
- `گزارش بررسی و برنامه زمانبندی پروژه سامانه خدمات پرستاری.md` — بریف اصلی پروژهٔ پرستاری (۲۱ اکتبر ۲۰۲۶)

---

## 3. ابزار کار، Deploy و راستی‌آزمایی

### 3.1 `deploy.ps1`
- استفاده: `.\deploy.ps1 wp-content/plugins/.../file.php other/file.css`
- `.ftp.env` کنار اسکریپت را می‌خواند (`KEY=VALUE`، کامنت با `#`). الزامی: `FTP_HOST`, `FTP_USER`, `FTP_PASS`؛ اختیاری: `FTP_ROOT`, `FTP_PORT` (پیش‌فرض 21).
- برای هر مسیر: `\`→`/`، بررسی وجود فایل محلی، ساخت پوشه‌های ریموت با `MakeDirectory` (خطای «وجود دارد» نادیده)، آپلود باینری Passive با `FtpWebRequest` (Proxy=null)، چاپ `OK <path>`.
- **هشدار:** آپلود مستقیم روی فایل مقصد است؛ اگر وسط کار خطا بدهد (مثل 552)، فایل ریموت **خالی/ناقص** می‌ماند. (پیشنهاد آینده: آپلود به نام موقت و سپس Rename.)

### 3.2 `deploy.sh`
- اگر `lftp` باشد: `mkdir -p` و `put -O` با SSL خاموش؛ وگرنه fallback به `pwsh -File deploy.ps1` یا `powershell.exe`.

### 3.3 محیط ویندوز
- شل: PowerShell ویندوز → از `;` به‌جای `&&` استفاده کن.
- `php` روی PATH نیست → `php -l` محلی ممکن نیست (پس از آپلود، صفحه را در مرورگر چک کن).
- مسیرهای فارسی (`desgin/png/ثبت سفارش`) در PowerShell با `-LiteralPath` بی‌مشکل‌اند.

### 3.4 حلقهٔ راستی‌آزمایی (مطابق skill «ftp-auto»)
1. Lint (`php -l` اگر موجود).
2. آپلود فقط فایل‌های تغییرکرده. هرگز `wp-config.php`، `.env`، `.ftp.env`، `.git`، `node_modules`، بکاپ‌ها، لاگ‌ها.
3. در مرورگر داخلی Cursor (تب باز): hard reload با `mns_nocache=1&v=…`؛ تأیید UI، نبود خطای PHP/صفحهٔ سفید، نبود خطای کنسول و 404/500. فرم/AJAX را واقعاً اجرا کن.
4. شکست → رفع علت ریشه‌ای → آپلود → تأیید؛ بعد از ۳ شکست گزارش بده.
5. شکست آپلود → یک بار تلاش مجدد → گزارش.
- هرگز «تمام شد» بدون تأیید موفق. در طول حلقه ساکت باش، نتیجه را یک خطی گزارش کن.
- نکتهٔ عملی: اسکرین‌شات CDP گاهی هنگ می‌کند → تأیید با `Runtime.evaluate` (getComputedStyle / getBoundingClientRect / وضعیت DOM).
- برای شبیه‌سازی موبایل: `Emulation.setDeviceMetricsOverride` (390×844) و در پایان `clearDeviceMetricsOverride`.

---

## 4. قوانین و ترجیحات کاری کاربر

### 4.1 قانون Cursor (`.cursor/rules/document-patches-after-update.mdc`)
هر تغییری در پلاگین/قالب ثالث (یا هر کدی که با آپدیت پاک می‌شود) باید در همان نوبت در `docs/` مستند شود:
- پچ قالب → `docs/THEME-PATCHES-AFTER-UPDATE.md`
- پچ چندپلاگینی → `docs/MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md`
- پیامک/مالکیت رزرو → `docs/MEDYAR-SMS-BOOKING-AND-OWNERSHIP.md`
- مورد جدید → `docs/<NAME>-PATCHES-AFTER-UPDATE.md` + لینک از ایندکس
- هر مدخل: مسیر هدف، تغییر دقیق (قبل/بعد)، دلیل، روش اعمال مجدد (نشانگر/نام متد/هوک).
- ترجیح: child theme / mu-plugin / پلاگین سفارشی به‌جای پچ مستقیم.

### 4.2 Skillها
- **wordpress-dev:** پاسخ فارسی، کد انگلیسی؛ بی‌مقدمه و بدون خلاصهٔ اضافه؛ فقط کد تغییرکرده با مسیر. API و هوک‌های هسته؛ هرگز ویرایش core/ثالث. امنیت: `wp_unslash`→sanitize، escape دیرهنگام، nonce، `current_user_can`، `$wpdb->prepare`، `permission_callback`، `defined('ABSPATH') || exit;`. پیشوند همه‌چیز. enqueue درست و فقط جای لازم. بدون کوئری در حلقه؛ cache با transient. i18n و RTL. ووکامرس فقط با CRUD/هوک. تغییر حداقلی، بدون ری‌فکتور نامرتبط. عملیات مخرب فقط با تأیید صریح.
- **ftp-auto:** بخش 3.4.
- **from-screen-ux:** «دقیقاً همان طرح، نه تفسیر یا بهبود». هر المان طرح باشد، المان اضافه نباشد. متن حرف‌به‌حرف با ارقام فارسی. آیکون SVG درون‌خطی (خط ~1.7–1.8، سرگرد، شبکه 24). دسکتاپ 1440 (PNG با ضریب 1.5)، موبایل 390. شکست جدول→کارت در «سفارش‌های من» ≈900px. رفتارها: ویزارد ۴ مرحله‌ای، بررسی دسترسی AJAX سه‌حالته (سبز/زرد/قرمز)، تب/فیلتر/جستجو، لغو با تأیید + عملیات اتمیک، منوی ⋯، حالت‌های خالی. بک‌اند ناموجود → mock + TODO. حلقهٔ تأیید تا صفر اختلاف؛ فیلد ≥16px، هدف لمسی ≥44px، بدون اسکرول افقی، کنسول بدون خطا. روی FTP: بکاپ قبل از تغییر، هیچ فایلی حذف نشود.
  - توکن‌های داخل skill (#0a6cb6 و …) **جایگزین شده‌اند** با توکن‌های سایت به تصمیم کاربر (رنگ اصلی **#0272b1**).
- **ui0ux / ui-ux** (یکسان): نقش Principal Product Designer؛ شبکهٔ 8pt؛ بدنه ≥16px موبایل؛ کنتراست ≥4.5:1؛ WCAG 2.2 AA؛ همهٔ حالت‌ها (خالی، بارگذاری، جزئی، خطا، موفق، آفلاین، اولین استفاده، سرریز)؛ لمس 44px؛ حرکت 100–400ms با `prefers-reduced-motion`.

### 4.3 ترجیحات و تصمیم‌های صریح کاربر (از تاریخچهٔ گفتگو)
**روند:**
- اول UI استاتیک با دادهٔ نمونه، بعد بک‌اند.
- استقرار و تأیید خودکار بدون اجازه گرفتن («تا ته ته برو بدون تایید»)؛ اما برای ریزتغییرات ظاهری گاهی می‌گوید «بدون تست انجام دهید».
- قبل از پیشروی، از اعمال واقعی تغییر مطمئن شو.
- سرعت مهم است («زودتر تمومش کن»).
- گرافیک/تصویر خودت نساز؛ placeholder هم‌اندازه بگذار.

**ظاهر:**
- مرجع UX = پلاگین **affiliate-referral (ARP)**: پوستهٔ پنل، سایدبار، هدر صفحه (`arp-refs__head`)، هدر کارت (`arp-card__head`)، دکمهٔ outline (`arp-btn-outline`)، فیلتر (`arp-refs-filters`)، جدول (`arp-table`)، صفحه‌بندی (`arp-refs-pager`)، داشبورد موبایل `partner/?arp_page=dashboard`.
- توکن‌ها (فونت، رنگ، شعاع، سایه، فاصله) را عوض نکن؛ رنگ اصلی سایت `#0272b1`.
- RTL، تاریخ شمسی، ارقام فارسی، «تومان».
- پوستهٔ پنل حفظ شود: سایدبار دسکتاپ / drawer موبایل / bottom-nav.
- طرح PNG قانون است؛ اگر PNG ناوبری/فونت ندارد، از داشبورد/ARP پیروی کن.
- ناوبری همهٔ صفحات پنل دقیقاً مثل داشبورد.

**سفارش‌ها/فاکتورها:**
- فیلتر «بر اساس بیمار» **حذف** (هم سفارش‌ها هم فاکتورها).
- کد سفارش فقط **۵ رقم** (سبک `MD-48215`).
- ردیف دسکتاپ: همهٔ اطلاعات در **یک خط**.
- چیپ «دوره‌ای · نوبت…» نه؛ **تاریخ** را نشان بده.
- سوابق: جعبهٔ جدا با **جدول + صفحه‌بندی ARP** (نه «نمایش بیشتر»).
- فیلترهای ARP زیر نوار جستجو با منطق JS خود ARP.
- موبایل: padding افقی روی `#mns-main` مثل ARP.

**ثبت سفارش:**
- گزینهٔ جنسیت «فرقی ندارد» **اضافه نشود** (فقط خانم/آقا) تا کارفرما تصمیم بگیرد.
- شمارهٔ پرستار به بیمار نشان داده نشود؛ هماهنگی از طریق پشتیبانی.

---

## 5. قالب dr-plus

- **Doctor Plus** نسخهٔ 2.9.1.0 (`DRPLUS_VERSION` = `2.9.1.2`)، از RTL-Theme (MohammadAmin Noorani & MohammadJafar Khajeh)، text domain `drplus`، تجاری و بخشی **رمزگذاری‌شده** (مثلاً `inc/Utils/utils-chat.php`، `inc/wallet/ThemeInit.php`، `inc/SaveBookingProcess.php`).
- **قالب والد است؛ child theme وجود ندارد.** پس هر تغییر مستقیم = پچ (بخش ۱۰).
- بوت: `functions.php` → ۱۷ فایل `functions/*` → `drplus_init` روی `init` (priority 0) → `inc/Includes.php` (`DrPlus\Includes::main()`) → utils، post typeها، Elementor theme builder، Redux (`Redux/Options.php`)، AJAX، مدل‌ها (جداول در `after_switch_theme`)، `inc/wallet/ThemeInit.php`.
- رفع محلی انتهای `functions.php`: `drplus_chat_alias_fix()` (alias `DrPlus\Utils\Chat` → `Chat`).
- منوها: `main-menu`, `mobile-menu`, `footer-menu`, `account-menu`. منوی هدر سایت: صفحه اصلی، مجله سلامت، متخصصان، معرفی آزمایشگاه، مدیار؛ دکمهٔ «درخواست نوبت»؛ آیکون حساب و سبد.
- زیرسیستم‌ها:
  - **رزرو/نوبت:** `functions/booking.php`، `inc/SaveBookingProcess.php`، `inc/Utils/utils-booking.php`، `templates/booking/*` (specialist, time, info, checkout, receipt). جداول `drplus_booking`, `drplus_times`, `drplus_reminder_log`. سفارش‌های رزرو در ووکامرس با متای `_booking_data` (محصول «Book Product»).
  - **متخصصان:** CPT `specialist`، جداول `drplus_specialists` (فیلد `subtitle` = «پرستار» برای پرستاران)، `drplus_specialist_cache`، روابط بیمارستان/بیمه/تخصص.
  - **چت:** جداول `drplus_chat_sessions`, `drplus_chat_messages`؛ REST `drplus_chat/v1`؛ فایل‌ها در `wp-content/chat-uploads/`؛ ویدئو Skyroom.
  - **SMS:** `DrPlus\SMS\SMS::send()` (پترن) و `send_custom_text()`؛ درگاه‌ها: Asanak, Farapayamak, Farazsms, **FarazsmsNew** (`farazsms_new`، API `api.iranpayamak.com`، هدر `Api-Key`)، Kavenegar, Melipayamak, …
  - **کیف پول Sheyda:** namespace `Sheyda\Wallet`، جداول `sheyda_wallet_balances`, `_ledger`, `_locks`, `_withdrawals` — با پلاگین `medyar-toman-wallet` پل و جایگزین شده.
  - OTP (`drplus_otp`)، اعلان‌ها، علاقه‌مندی‌ها، پلن اشتراک، بیمارستان‌ها، onboarding، ویجت‌های Elementor.
- **فونت‌ها:** IRANYekanX / IRANYekanXFANum (پیش‌فرض `--main-font: "IRANYekanXFANum"`)، AnjomanFANum (`--secondary-font`)، Vazirmatn، Dana، Rokh، Abi؛ آیکون‌فونت `drplus-icon` (Iconly).
- **متغیرهای CSS قالب:** `--primary-100` پیش‌فرض قالب `#1DBAB5` ولی در سایت مدیار از Redux روی **`#0272b1`** تنظیم شده؛ `--secondary-100 #114B5F`؛ `--red #d60043`؛ `--gray-100..400` (`#F7F7F7`, `#E6E6E6`, `#DBDBDB`, `#A7A7A7`)؛ `--text-main #383838`؛ شعاع‌ها: `--global-border-radius-1` 24px، `-2` 16px، فیلد 16px، دکمه 16/12px.
- **WooCommerce overrides:** `woocommerce/myaccount/*` و تب‌های سفارشی (appointments, chats, notifications, tickets, wishlist, specialist-dashboard/…). داشبورد حساب کاربری را پلاگین `medyar-myaccount-dashboard` جایگزین می‌کند.
- Date picker قالب: `drplus-pdp` / `drplus-pd` (babakhani) — با `persianDate` سراسری تداخل دارد؛ ARP/MNS/Chat آن را در صفحات خود dequeue می‌کنند و از `pw-datepicker-js` (Persian WooCommerce) استفاده می‌کنند.
- **ریسه‌های رفتاری قالب که مهم‌اند:**
  - قانون قالب `section, aside { display:block }` بر `[hidden]` غلبه می‌کند → در MNS: `.mns-dash [hidden], .is-hidden { display:none !important }`.
  - دیالوگ‌ها `display:flex` دارند → `[hidden]` باید `!important` باشد.

---

## 6. فهرست پلاگین‌ها

### 6.1 سفارشی/پروژه‌ای
| پلاگین | نسخه | سازنده | کاربرد کوتاه |
|---|---|---|---|
| `medyar-nursing-services` (MNS) | 0.5.13 | Medyar | سامانهٔ خدمات پرستاری در منزل (در حال ساخت) |
| `affiliate-referral` (ARP) | 1.2.0 (DB 1.0.5) | M.Mehdi Diaromidi | طرح همکاران مدیار، کمیسیون، معرف‌ها؛ **مرجع UI** |
| `medyar-sms-queue` (MSQ) | 1.0.0 | M.Mehdi Diaromidi | صف پایدار پیامک با retry |
| `medyar-toman-wallet` (MTW) | 1.0.0 | M.Mehdi Diaromidi | کیف پول تومانی، شارژ زرین‌پال، برداشت، پرداخت ووکامرس |
| `medyar-chat-pro` (MCP) | 1.1.0 (ثابت 1.2.0) | M.Mehdi Diaromidi | ارتقای چت بیمار‑متخصص قالب |
| `medyar-myaccount-dashboard` (MMAD) | 1.3.2 | M.Mehdi Diaromidi | داشبورد حساب کاربری + پیامک ادمین رزرو + کارمزد مدیار |
| `lab-booking-system` (Lab Pro) | 1.5 | Sajjad Dehghanian | درخواست آزمایش مهمان + OTP + زرین‌پال + نتیجه |
| `pharmacy-pro-system` (Pharmacy Pro) | 1.0 | Sajjad Dehghanian | کلون Lab برای نسخهٔ داروخانه |
| `medical-records` | 1.7 | — | پروندهٔ پزشکی ادمین‌محور + endpoint «پرونده‌های من» |
| `RTL-CareUnit` | 2.8.12 | RTL-Theme | لایسنس/آپدیت محصولات راست‌چین (ثالث، ionCube) |

### 6.2 ثالث
woocommerce 11.2.1 · elementor 4.3.5 · elementor-pro 4.2.1 · gravityforms 2.9.22.1 (فرم #6 = پرستاری در منزل قدیمی) · GravityForms-master 2.3.0 (درگاه زرین‌پال گرویتی) · persian-gravity-forms 3.0.3 · gfstylespro 3.1.4 · jet-tabs 2.3.4 · contact-form-7 6.1.7 · redux-framework 4.5.16 · seo-by-rank-math 1.0.280 · wp-rocket 3.23.5.1 · wordfence 9.0.2 · wps-hide-login 1.9.19 · login-as-user 1.7.5 · user-switching 1.12.2 · user-role-editor 4.66.2 · query-monitor 4.0.7 · duplicator 5.0.6 · cron-task-viewer-redux 1.0.2 · wp-security-audit-log 5.6.7 · zarinpal-woocommerce-payment-gateway-old 5.0.16 (`WC_ZPal`) · post-duplicator 3.0.16 · classic-editor 1.7.0 · classic-widgets 0.3 · persian-woocommerce 10.0.5 (`PW()`، `persian-datepicker`) · **faraz-sms** 3.22.293 (بدون پچ) · **persian-woocommerce-sms** 7.2.3 (**پچ‌شده**، بخش ۱۰).

---

## 7. پلاگین‌های سفارشی — جزئیات

### 7.1 affiliate-referral (ARP) — «طرح همکاران مدیار»
- پیشوند `arp_` / کلاس‌های `Arp_*`؛ ثابت‌ها `ARP_VERSION`, `ARP_DB_VERSION`, `ARP_PLUGIN_DIR`, `ARP_PLUGIN_URL`.
- کاربر واردشده درخواست همکاری می‌دهد → ادمین تأیید/رد → همکار بیمار معرفی می‌کند → با رسیدن معرفی به وضعیت تولید کمیسیون، کمیسیون **فقط به کیف پول تومانی** واریز می‌شود. زیربرنامهٔ «معرف/معرف ویژه» با کد ۸ حرفی.
- جداول `{prefix}arp_`: `job_types`, `services`, `commission_rules`, `referral_statuses` (seed: `waiting_contact` پیش‌فرض، `in_progress`, `patient_accepted`, `completed` تولید کمیسیون، `patient_withdrawn`, `failed_contact`), `referrals`, `commissions` (state: pending/approved/paid/reversed), `settlements`, `settlement_items`, `referral_logs`.
- نقش `affiliate` («همکار») فقط پس از تأیید ادمین؛ cap `manage_arp` برای ادمین.
- فرانت: page templateها «پنل طرح همکاران (مدیار)» و «ثبت‌نام طرح همکاران (مدیار)»؛ URL پیش‌فرض `/partner/`؛ `?arp_page=`.
- AJAX: `arp_submit_referral`, `arp_save_profile`, `arp_upload_avatar`, `arp_remove_avatar`, `arp_panel_list`, `arp_process_sms_queue_async` (HMAC).
- منوی ادمین «همکاران مدیار» (`arp-dashboard`) با زیرمنوهای applications, invited, referrals, affiliates, finance, settings.
- پیامک: `Arp_Faraz_Sms_Gateway` (پترن‌ها در optionهای `arp_*_sms_pattern`) → اگر MSQ آماده، صف با `channel=arp`؛ وگرنه outbox خود ARP.
- پل کیف پول: `Arp_Wallet_Bridge` روی `arp_commission_created` → `Wallet_Manager::add_balance(..., 'admin_add')` با کد تراکنش `ARP-C-{id}`؛ برگشت `ARP-C-{id}-REV`.
- **کیت UI که MNS از آن استفاده می‌کند** (`assets/css/public.css` و `assets/js/public.js`):
  - توکن‌ها `--arp-primary` (= #0272b1)، `--arp-bg`, `--arp-card`, `--arp-radius` …
  - پوسته `.arp-dash` (سایدبار + drawer)، select سفارشی `select[data-arp-select]` → `.arp-select__trigger/menu/option`، تقویم شمسی `[data-arp-jdate]` با `$.fn.persianDatepicker` (تم `pdp-arp`)، فرم فیلتر `.arp-refs-filters` با ارسال خودکار AJAX، جدول `.arp-table`، صفحه‌بندی `.arp-refs-pager`.
  - سند کامل UI: `affiliate-referral/UI-UX.md` (۴۷KB).

### 7.2 medyar-sms-queue (MSQ)
- جدول `{prefix}msq_jobs`: channel, event, transport (`faraz`|`drplus`|`mmad_pattern`), priority (ادمین 10، عادی 20، کم 30), phone, body, pattern_code, payload(JSON vars/data), meta, dedupe_key, status (`pending`/`reserved`/`sent`/`dead`/`canceled`), attempts, max_attempts=3, available_at, locked_at, last_error, …
- API: `MSQ_Client::enqueue()`, `is_ready()`, `replay()`, `send_now()`. حالت‌های dedupe: `skip` (هرگز دوباره)، `pending` (فقط تا وقتی قبلی در صف است)، `replace` (آخری برنده).
- اجرا: درخواست loopback غیرمسدودکننده در `shutdown` (اول IP سرور/127.0.0.1 برای دور زدن CDN) → کران واقعی سرور `admin-ajax.php?action=msq_cron&key=<کلید در option msq_cron_key>` (هر دقیقه؛ الزامی) → WP-Cron `msq_cron_tick` → WP-CLI `wp msq process`.
- Backoff: 30s، 120s، 600s، 1800s؛ سپس `dead`. پاکسازی روزانه: sent بعد از ۶۰ روز، dead/canceled بعد از ۱۲۰ روز.
- ادغام با قالب: فیلتر `drplus/sms/pre_send` (پچ قالب ۹) پیامک‌های `reserve_notification.*` را می‌گیرد → `channel=booking`, `transport=drplus`؛ worker داخل `MSQ_Client::replay()` دوباره `\DrPlus\SMS\SMS::send()` را با فلگ‌های bypass اجرا می‌کند.
- منوی ادمین «صف پیامک» (`medyar-sms-queue`).

### 7.3 medyar-toman-wallet (MTW)
- موجودی: user meta **`wallet_toman_balance`** (عدد صحیح تومان). همهٔ تغییرات اتمیک (`START TRANSACTION` + `SELECT … FOR UPDATE`) در `helpers/atomic-balance.php`.
- `Wallet_Manager::get_balance()` اگر جدول Sheyda باشد موجودی «در دسترس» Sheyda را برمی‌گرداند؛ `MTW_Sheyda_Balance_Bridge` دو طرف را همگام می‌کند.
- جداول: `{prefix}wallet_transactions` (amount، `balance_type`=toman، `operation_type`: charge, withdraw, purchase, refund, admin_add, admin_reduce, booking_payout, booking_payout_reversal؛ `status`: pending/processing/completed/canceled؛ `transaction_code` یکتا)، `{prefix}wallet_payment_transactions`.
- درگاه زرین‌پال REST v4 (merchant در option `mtw_zarinpal_merchant_id` یا تنظیمات `WC_ZPal`)؛ endpoint بازگشت `compelete-transactions` (همین املای غلط در کد).
- ووکامرس: endpoint حساب `wallet`، درگاه `wallet` (پرداخت کامل)، پرداخت جزئی در checkout (fee منفی).
- برداشت: IBAN `IR`+۲۴ رقم، حداکثر ۲ درخواست در ۲۴ ساعت، حداقل 10000.
- REST: `GET medyar/v1/balance`. شورت‌کد `[medyar_bank_cards]`. منوی ادمین «کیف پول مدیار» (`medyar-wallet`).
- واریز درآمد متخصص هنگام تکمیل سفارش رزرو (`MTW_Booking_Specialist_Payout`)؛ فیلتر `mtw_booking_specialist_payout_amount` (MMAD کارمزد مدیار را کم می‌کند).
- پیامک کیف پول: اول MSQ (`channel=wallet`)، بعد ARP gateway، بعد faraz.
- `_mtw_probe.php` در ریشهٔ پلاگین: پروب دیباگ قابل دسترس از وب — باید حذف شود.

### 7.4 lab-booking-system (Lab Pro) و pharmacy-pro-system
- **Lab:** شورت‌کد `[lab_booking]`؛ SPA چندمرحله‌ای (نسخه دارم/ندارم → آزمایش‌های روتین با سبد / مشاوره → اطلاعات → OTP → موفق). جداول `{prefix}lab_tests`, `{prefix}lab_orders` (token ۸ کاراکتری، status: `pending`→`waiting_payment`→`paid`→`completed`). ادمین قیمت می‌گذارد → لینک پرداخت پیامک → زرین‌پال (تومان×10=ریال) → آپلود نتیجه → پیامک نتیجه. پیگیری `/?lab_track={token}`. آپلودها `uploads/lab_secure/`.
- `Lab_Admin_Sms_Settings` («تنظیمات ← پیامک ادمین درخواست‌ها»، `options-general.php?page=lab-admin-sms`): پیامک ادمین برای lab، **nurse** (هوک `gform_after_submission_6` گرویتی فرم پرستاری فعلی) و phar.
- **Pharmacy:** شورت‌کد `[phar_booking]`، جدول `{prefix}phar_orders` (insurance_type/code، pres_files، invoice_items، usage_notes، delivery_notes). وابسته به Lab برای پیامک ادمین. فایل اصلی آن emojiهای وردپرس را برای کل سایت غیرفعال می‌کند.
- پیامک مشتری در هر دو مستقیم به `api.iranpayamak.com` (بدون صف).

### 7.5 medical-records
- نقش `patient` («بیمار»)؛ cap `manage_medical_records`. جدول `{prefix}medrec_records` (visit_date, reason, doctor, nurse, vital_signs, diagnosis, treatment_plan, medications, …). متای کاربر برای مشخصات، بیمه، تماس اضطراری، گروه خونی، سوابق تکرارشونده. endpoint ووکامرس `my-cases` («📋 پرونده‌های من»). منوی ادمین «پرونده پزشکی» (`medrec-main`). جدول متخصصان قالب را با پیشوند هاردکد `Dj3i_` می‌خواند.

### 7.6 medyar-chat-pro (MCP)
- ارتقای چت قالب بدون پچ کلاس رمزگذاری‌شده: وضعیت گفتگو (open, waiting_specialist, waiting_patient, answered, closed)، پاسخ/ریپلای، ضبط صدا، پیش‌نمایش فایل، outbox آفلاین، تایپینگ، هشدار ۵ دقیقه به پایان اسلات و تمدید ۵/۱۰/۱۵/۲۰ دقیقه، یادداشت خصوصی، پاسخ سریع، ارجاع، پروفایل بیمار (از `medrec_records`)، audit، rate limit، سرو امن فایل `?mcp_file=`.
- اسکریپت قالب `drplus-chat` را با `mcp-chat.js` جایگزین می‌کند. همه درخواست‌ها با `?_envelope=1` (CDN بدنهٔ 4xx/5xx را عوض می‌کند).
- REST `medyar-chat/v1`؛ جداول `{prefix}mcp_session_meta`, `message_meta`, `reactions`, `notes`, `referrals`, `audit`؛ capها `mcp_manage_chats`, `mcp_view_medical`؛ منوی «گفت‌وگوهای پزشکی» (`mcp-chats`). جزئیات کامل: `docs/CHAT.md`.

### 7.7 medyar-myaccount-dashboard (MMAD)
- جایگزینی `woocommerce/myaccount/dashboard.php` (priority 999) با داشبورد مدیار (بنر، آمار، موجودی کیف پول، کارت‌های دسترسی سریع).
- «تنظیمات سفارشی رزرو» (`medyar-admin-reserve-sms`): پیامک ادمین رزرو/لغو (option `medyar_admin_reserve_sms`، transport صف `mmad_pattern`) و «درآمد مدیار» (کارمزد درصدی/ثابت از پرداخت متخصص + گزارش CSV).

---

## 8. نقشهٔ وابستگی

| فراخواننده | مقصد | سازوکار |
|---|---|---|
| ARP | کیف پول | `Wallet_Manager::add_balance/reduce_balance` با کد `ARP-C-{id}` |
| ARP، Wallet، MMAD، ادغام قالب | MSQ | `MSQ_Client::enqueue` (کانال‌های arp / wallet / booking) |
| Wallet SMS، Lab admin SMS | ARP | `Arp_Faraz_Sms_Gateway::deliver()` |
| Pharmacy | Lab | `Lab_Admin_Sms_Settings::notify_admins('phar')` |
| Lab | Gravity Forms | `gform_after_submission_6` (پرستاری فعلی) |
| ARP، MSQ، Wallet، Lab | faraz-sms | توابع `farazsms_*` |
| Lab/Pharmacy مشتری | IranPayamak | مستقیم REST |
| Wallet، Lab، Pharmacy | زرین‌پال v4 | کلاینت جدا در هر پلاگین |
| MMAD | Wallet، دفتر Sheyda | `mtw_booking_specialist_payout_amount`, `sheyda/wallet/ledger/booking_payout/new` |
| Chat Pro | medical-records | خواندن `medrec_records` |
| MNS | ARP (اختیاری) | استفادهٔ مجدد CSS/JS لیست/فیلتر/جدول/pager در صفحات orders، invoices، invoice-view؛ بدون ARP به select/تاریخ بومی برمی‌گردد |
| MNS | Wallet | نمایش «موجودی شما» در سایدبار از `Wallet_Manager`/Sheyda (fallback fixture) |
| ARP، Wallet | هدر قالب | فیلتر `drplus/account_menu_items` (پچ قالب ۱) |

---

## 9. پیامک: مالکیت، صف، mu-pluginها

| پیامک | مالک | ارسال |
|---|---|---|
| تأیید رزرو (`book`) بیمار/متخصص | mu-plugin `medyar-reserve-book-sms.php` → `Booking::send_reserve_event_sms()` | صف، booking/drplus |
| لغو رزرو (`book_canceled`) | قالب `Booking::cancel_booking()` (پچ ۳) | صف |
| یادآور رزرو | کران قالب `CheckBookingMessages.php` (پچ ۵) | صف |
| پیامک ادمین رزرو/لغو | MMAD | صف `mmad_pattern`، اولویت ادمین |
| پیامک خودکار سفارش ووکامرس برای سفارش رزرو | **ارسال نمی‌شود** (mu-plugin skip + پچ‌های PWSMS) | — |
| پیامک سفارش فیزیکی ووکامرس | فقط یک مالک: Faraz `native` یا PWSMS (`medyar_pwsms_sync_sms_owner()`) | مستقیم |
| کیف پول (واریز/برداشت) | MTW `Wallet_SMS` | صف wallet؛ درج داخل تراکنش DB، اجرا بعد از COMMIT |
| ARP | `Arp_Faraz_Sms_Gateway` | صف arp |
| ادمین آزمایشگاه/پرستار/داروخانه | `Lab_Admin_Sms_Settings` | مستقیم (بدون صف) |
| OTP / فراموشی رمز / سایر کلیدهای قالب | قالب | مستقیم |

**mu-pluginهای روی سرور** (در ریپوی محلی نیستند):
- `medyar-skip-pwsms-booking-orders.php` v1.5.0 — `medyar_pwsms_is_booking_order()`، `medyar_pwsms_sync_sms_owner()`، متای `_medyar_skip_pwsms`، کوتاه‌کردن متغیرهای پترن IranPayamak (`price` ≤ 25)، `$GLOBALS['medyar_block_sms_http']`.
- `medyar-reserve-book-sms.php` v1.3.3 — ارسال یک‌بارهٔ `book` با قفل transient + متای `_medyar_reserve_book_sms_sent`؛ `medyar_reserve_normalize_phone()`.
- `medyar-minicart-remove-loading.php`، `medyar-booking-clear-cart.php` v1.1.0 (پاک‌کردن state رزرو پس از پرداخت)، `medyar-checkout-ui-tweaks.php`، `medyar-wp-rocket-wc-fragments.php` (`rocket_cache_wc_empty_cart` → false).
- فلگ‌های bypass سراسری: `medyar_reserve_sending_book|cancel|reminder|admin`، `medyar_block_sms_http`.
- mu-pluginهای دیباگ پیامک عمداً حذف شده‌اند؛ دوباره اضافه نشوند.
- خطای «داده درخواستی یافت نشد» در Faraz معمولاً یعنی متغیر پترن از سقف طول بیشتر است.

---

## 10. پچ‌های مستقیم روی کد ثالث

### 10.1 قالب dr-plus (`docs/THEME-PATCHES-AFTER-UPDATE.md`)
| # | فایل | تغییر | نشانگر جستجو |
|---|---|---|---|
| 1 | `inc/Utils/utils-user.php` → `User::get_account_menu_items()` | `return apply_filters( 'drplus/account_menu_items', $account_btn_items, is_user_logged_in() );` | `drplus/account_menu_items` |
| 2 | `templates/header/template-header-action-account_btn.php` | `dir="ltr"` برای نام عددی/ماسک‌شده و ایمیل، `esc_html` | `$account_btn_name_is_ltr` |
| 3 | `inc/Utils/utils-booking.php` | `cancel_booking()` + `send_reserve_event_sms()` + `prepare_reserve_sms_context()` + `gateway_send_failed()` + `maybe_send_book_sms_for_order()` | `send_reserve_event_sms`, `medyar_reserve_sending_cancel` |
| 4 | `functions/booking.php` | حذف هوک پیامک رزرو قالب (مالک: mu-plugin) | نبود هوک SMS |
| 5 | `inc/Cronjobs/CheckBookingMessages.php` | جایگزینی کامل؛ ترمیم کران یادآور | `drplus_medyar_ensure_reminder_cron` |
| 6 | `inc/AJAX/Auth.php` → `lost_password()` | رمز ۸ رقمی عددی `wp_rand(10000000, 99999999)` + تشخیص خطای ارسال | `wp_rand( 10000000, 99999999 )` |
| 7 | `inc/sms/Gateways/FarazsmsNew.php` → `_send_by_pattern` | خطای HTTP/API → `WP_Error('farazsms_pattern_failed')` | `farazsms_pattern_failed` |
| 8 | `inc/AJAX/Search.php` → `exec()` | محافظ `is_array` برای `exclude_post_types` (رفع 500 در PHP 8) | `is_array( $options['exclude_post_types'] )` |
| 9 | `inc/sms/Gateways/SMS.php` → `send()` | فیلتر `drplus/sms/pre_send` برای صف | `Medyar patch: lets medyar-sms-queue` |
| 10 | `templates/chats/template-chats-list.php` | فیلتر `drplus/chats/list_sessions` + زمان `list_time` | `MEDYAR PATCH` |
| — | `woocommerce/myaccount/dashboard.php` | فقط stub؛ UI در MMAD | — |

### 10.2 پلاگین‌ها (`docs/MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md`)
| # | هدف | تغییر | نشانگر |
|---|---|---|---|
| 2-A | `persian-woocommerce-sms/src/Orders.php` | `is_drplus_booking_order()` + فیلتر `pwoosms_skip_order_sms` | `is_drplus_booking_order` |
| 2-B | `…/src/Helper.php` → `send_sms` | رد سفارش رزرو → `'skipped_drplus_booking_order'` | `skipped_drplus_booking_order` |
| 2-C | `…/src/Bot.php` → `send_async` | رد اعلان bot برای رزرو | `medyar_pwsms_is_booking_order` |
| 3 | faraz-sms | **هیچ پچی؛ پچ نکن** | — |
| 4/6 | `medyar-toman-wallet/includes/class-woocommerce-checkout-wallet.php` | مبلغ کامل در پیامک سفارش با پرداخت کیف پول | `sms_body_use_full_wallet_total` |
| 10 | lab-booking-system + pharmacy-pro-system | نام‌گذاری مجدد توابع JS سراسری (`labSubmitFinalOrder`/`pharSubmitFinalOrder`) و scope به `#lab-booking-app`/`#phar-booking-app` | همین نام‌ها |
| 12 | نقاط ادغام MSQ | ARP gateway، wallet SMS، MMAD، قالب پچ ۹ | `msq_ready`, `queue_capture`, `mmad_pattern` |
| 14 | `wp-content/.htaccess` | بلاک `*.log` با 403 | — |

**ناهماهنگی‌های اسناد پچ:** دو «پچ ۱۰» متفاوت (lab/pharmacy در ایندکس، chat-list در سند قالب)؛ ایندکس قالب را ۱–۸ یا ۱–۹ می‌نویسد ولی سند قالب ۱–۱۰ دارد؛ `CHAT.md` می‌گوید فایل قالب ویرایش نشده ولی پچ قالب ۱۰ برای همان پلاگین است؛ نسخهٔ لازم mu-plugin در یک سند 1.4.0+ و در دیگری 1.5.0+.

---

## 11. پروژهٔ سامانهٔ خدمات پرستاری — بریف کسب‌وکار
(منبع: `docs/گزارش بررسی و برنامه زمانبندی پروژه سامانه خدمات پرستاری.md` — با وجود عنوان، فاز/زمان‌بندی/ریسک ندارد؛ بریف کارکردی است.)

### 11.1 جریان کلی
بیمار سفارش ثبت می‌کند → سیستم پرستار تخصیص می‌دهد → پرستار بعد از خدمت چک‌لیست و گزارش ثبت می‌کند → فاکتور با تعرفهٔ سیستم محاسبه و لینکش پیامک می‌شود → بیمار آنلاین (زرین‌پال) پرداخت می‌کند → کیف پول پرستار **یک‌بار** شارژ می‌شود → پرسشنامه، امتیاز، تخفیف، وفاداری.

اصول: پنل ادمین داخل wp-admin؛ پنل بیمار/پرستار اختصاصی؛ **سفارش بدون حساب نداریم** (ورود OTP)؛ مبلغ‌ها فقط با قواعد سیستم (پرستار مبلغ دلخواه وارد نمی‌کند)؛ فرم‌ها در کد (بدون فرم‌ساز)؛ نقش «مسئول بالینی» نداریم.

### 11.2 نقش‌ها
- **ادمین:** رصد همهٔ سفارش‌ها، تأیید پرستار، تخصیص، ویرایش فاکتور پرداخت‌نشده (نسخه‌دار)، اعلان‌های فوری، ثبت سفارش تلفنی، ثبت دستی پرداخت نقدی/کارت‌به‌کارت، گزارش‌ها، تنظیمات.
- **پرستار:** عضویت (فرم واحد: مشخصات، جنسیت، کارت ملی، کارت نظام پرستاری، بیمارستان، برای آقایان کارت پایان خدمت/معافیت، پذیرش قرارداد با نسخه)؛ وضعیت عضویت: در حال بررسی/تأییدشده/ردشده (با دلیل و ارسال مجدد)/تعلیق؛ پروفایل (شهر/منطقه، ماتریس خدمات، برنامهٔ هفتگی، کلید «پذیرش درخواست»)؛ ثبت شروع، اعلام پایان، چک‌لیست اختصاصی هر خدمت، گزارش پایان (بعد از ثبت غیرقابل‌ویرایش)، علامت «مورد فوری»؛ کیف پول و برداشت؛ «امتیازهای من» (فقط میانگین).
- **بیمار:** داشبورد، سفارش‌ها، «بیماران من»، جزئیات سفارش و پرستار، فاکتورها/پرداخت/دانلود، درخواست مجدد، انتخاب همان پرستار، ثبت نظر، کارت وفاداری و کد تخفیف، خدمات دوره‌ای.

### 11.3 وضعیت‌های سفارش
ثبت‌شده → تخصیص‌یافته → در حال انجام → (مرحلهٔ دارو) → پایان خدمت → فاکتور صادر شده → پرداخت‌شده → تکمیل‌شده؛ و لغوشده، نیازمند بررسی.
- پایان خدمت را خود پرستار اعلام می‌کند (کد تأیید ندارد). بدون گزارش پایان «تکمیل‌شده» نمی‌شود. سهم پرستار فقط بعد از پرداخت بیمار.

### 11.4 قواعد کلیدی
- **صف انتظار نداریم:** یا همان لحظه پرستار پیدا می‌شود یا اعلام می‌شود نیست.
- **جنسیت پرستار انتخابی بیمار قطعی است.**
- تخصیص: حالت خودکار/دستی در تنظیمات. فیلتر واجد شرایط (تأییدشده، مجاز برای خدمت، شهر/منطقه، هم‌جنس با درخواست، آزاد در زمان، کلید پذیرش روشن) + رتبه‌بندی وزن‌دار (پرستار قبلی بیمار، امتیاز، عدالت توزیع، سابقهٔ لغو).
- قیمت: پایه یا بازه در صفحهٔ خدمت؛ عوامل نهایی: نوع خدمت، مدت حضور، شهر/منطقه، اقلام، خدمات اضافه؛ **ایاب‌وذهاب ردیف جدا ندارد**.
- مرحلهٔ دارو: پرستار عکس/نام دارو، نسخه یا تماس با پزشک معرفی‌شده؛ عدم تأیید → «نیازمند بررسی» + پیامک فوری ادمین.
- **پرداخت بعد از خدمت با لینک پیامکی** (یا پنل)، مهلت همان لحظه؛ پرداخت‌نشده → سهم پرستار شارژ نمی‌شود.
- اعتراض: ادمین فقط فاکتور پرداخت‌نشده را ویرایش می‌کند (نسخه‌دار + لاگ). بازگشت وجه، مالیات و مودیان خارج از محدوده.
- خدمات دوره‌ای: هر نوبت سفارش مستقل؛ **پرداخت قبل از خدمت**؛ تفاوت → فاکتور مکمل (یا طبق بریف فاکتورها: مابه‌التفاوت به کیف پول).
- پرسشنامه: ۵ ستاره در سه دسته (علمی، اخلاق و خوشرویی، نظم) + متن؛ عمومی فقط ستاره/نشان مثبت.
- تخفیف سفارش اول (یک‌بار برای هر موبایل)؛ کارت وفاداری: هر خدمت تکمیل‌شده یک مهر، پس از ۵ مهر (قابل تنظیم) کد تخفیف.
- خارج از محدوده: بازگشت وجه، مالیات، صف انتظار، نقشه (فقط شهربندی)، فرم‌ساز، تیکت/چت (پشتیبانی فقط تلفنی)، کد تأیید پایان، سفارش بدون حساب.
- شش دستهٔ خدمت: سرم‌تراپی، تزریقات، پانسمان، سوندگذاری و مرتبط، مراقبت از بیمار، سایر.

### 11.5 پیامک‌های پروژهٔ پرستاری
عضویت/تأیید/رد پرستار (ادمین، پرستار) · ثبت سفارش (بیمار، ادمین‌ها) · تخصیص/تغییر پرستار (بیمار، پرستار) · پایان خدمت + لینک پرسشنامه (بیمار، ادمین‌ها) · مورد فوری/عدم تأیید پزشک (ادمین‌ها) · صدور فاکتور و یادآوری (بیمار) · پرداخت موفق (بیمار، ادمین‌ها) · لینک پرداخت نوبت دوره‌ای (بیمار) · سفارش بدون پرستار (ادمین‌ها).

### 11.6 تحویلی‌های کارفرما (هنوز نرسیده)
فهرست خدمات با قیمت/شرایط؛ فیلدهای سفارش، چک‌لیست و گزارش هر خدمت؛ شهربندی؛ اقلام و خدمات اضافه با تعرفه؛ مدارک عضویت و متن قرارداد؛ پزشکان مشاور و قواعد دارو؛ قواعد نشان، تخفیف اول، وفاداری؛ شمارهٔ ادمین‌های اعلان.

---

## 12. پلاگین MNS — مرجع فنی

مسیر: `wp-content/plugins/medyar-nursing-services/` · پیشوند `MNS_`/`mns_`/`mns-` · text domain `medyar-nursing-services` · PHP 8.0+ · WP 5.8+.

### 12.1 ثابت‌ها و بوت
`MNS_VERSION '0.5.13'` · `MNS_DB_VERSION '0.0.0'` · `MNS_PLUGIN_FILE/DIR/URL/BASENAME` · `MNS_UI_STATIC true` (اگر از قبل تعریف نشده؛ `false` در wp-config همیشه برنده). `MNS_DEV_TOOLS` فقط چک می‌شود (false = خاموش). اختیاری `MNS_DELETE_DATA_ON_UNINSTALL`.
بوت: `MNS_Autoloader::register()` → activation/deactivation → `plugins_loaded` → `MNS_Plugin::instance()->init()` (maybe_upgrade → roles → textdomain → admin → public → assets → rewrite).
**هیچ جدول دیتابیسی ندارد.** `MNS_Rewrite` خالی است (URL تمیز نداریم؛ همه از `$_GET`).

### 12.2 کلاس‌ها (autoloader map)
- `includes/`: `MNS_Plugin`, `MNS_Activator`, `MNS_Roles`, `MNS_Config` (+ تابع `mns_assumption()`), `domain/MNS_Order_Status`.
- `admin/`: `MNS_Admin`, `MNS_Admin_Menu`, `MNS_Admin_Ui`.
- `public/`: `MNS_Public`, `MNS_Assets`, `MNS_Rewrite`, `MNS_Pages`, `MNS_Page_Templates`, `MNS_Static_Fixtures`, `MNS_Format`, `MNS_Copy`, `MNS_Dev`, `MNS_UI`, `MNS_Patient_Orders`, `MNS_Patient_Invoices`.
- متدهای مهم:
  - `MNS_Pages::panel_url($panel,$page)`, `patient_slugs()`, `nurse_slugs()`, `current_panel()`, `current_page()`, `catalog_url()`, `catalog_service_url()`.
  - `MNS_Format::digits()` (ارقام فارسی)، `number()` (جداکنندهٔ `٬`)، `toman()`، `mobile()`، `g2j()`/`j2g()`، `month_names()` (۱..۱۲)، `weekday_names()` (شنبه‌اول)، `jdate()`, `rel()`, `human()`, `js_calendar()`.
  - `MNS_UI::page_header()`, `alert()`, `alert_card()`, `section_head()`, `list_item()`, `status_badge()`, `order_timeline()`, `empty_state()`, `error_state()`, `skeleton()`, `support_call()`, `support_phone()` (پیش‌فرض `02188607015`، قابل override با `mns_settings[support_phone]`)، `support_tel_href()`, `stamp_card()`, `bottom_nav()`, `boot_icons()`, `partial()`.
  - `MNS_Copy::t('key')` (دیکشنری متون با کلیدهای نقطه‌دار)، `MNS_Dev::is('state')`, `MNS_Static_Fixtures::get('patient/orders')`, `MNS_Config::setting()`.
  - آیکون‌ها: تابع `mns_icon($name)` در `public/views/partials/icons.php` (SVG 24، stroke 1.8): home, plus, list, users, invoice, calendar, star, heart, grid, user, user-check, wallet, clock, logout, external, chevron, chevron-down, check, check-circle, x, x-circle, alert, info, phone, copy, lock, camera, upload, file, trash, edit, map-pin, activity, pill, flag, shield, search, more, timer, repeat, bandage, droplet, filter, headphones, download, card, user-plus, file-text, doc-list, gift.

### 12.3 مسیریابی
- page templateها (`public/templates/`): `mns-patient.php` («MNS — پنل بیمار»)، `mns-nurse.php`، `mns-catalog.php`، `mns-nurse-signup.php`.
- در `mns-patient.php` متغیر `$page` باید **بعد از** `get_header()` مقداردهی شود (وردپرس `$page` سراسری را بازنویسی می‌کند). حالت چاپ فاکتور (`invoice-view` + `mns_print`) خروجی مستقل `invoice-print.php` می‌دهد.
- اسلاگ‌های بیمار: `dashboard`, `new-order`, `orders`, `order-view`, `patients`, `invoices`, `invoice-view`, `recurring`, `loyalty`, `surveys` (+ `ui-kit` در حالت dev). نامعتبر → `not-found`.
- اسلاگ‌های پرستار: `dashboard`, `orders`, `order-view`, `availability`, `ratings`, `profile` (+ `ui-kit`).
- برچسب‌های منو (fixture `patient/nav.php`): داشبورد، ثبت سفارش، سفارش‌های من، بیماران من، فاکتورها و پرداخت‌ها، خدمات دوره‌ای، کارت وفاداری و تخفیف، پرسشنامه‌ها، پنل مدیار (خارجی)، خروج از حساب.
- bottom-nav موبایل بیمار: داشبورد، سفارش‌ها، سفارش جدید (اصلی)، فاکتورها، بیشتر. روی `new-order` و `ui-kit` مخفی.
- پارامترها: `mns_panel`, `mns_page`, `mns_state`, `mns_order`, `mns_invoice`, `mns_patient`, `mns_service`, `mns_tab`, `mns_p` (صفحه)، `mns_print`, `mns_autoprint`, `mns_result` (success/failed/pending), `mns_dispute`, `mns_paid`, `mns_repeat`, `mns_same_nurse`, `mns_recurring`, `mns_survey`, `mns_form`، فیلترها `q`, `from`, `to`, `service`, `recurring`, `type`, `sort`, `order`.
- nonceها: `mns_public`, `mns_order_cancel`, `mns_invoice_dispute`.

### 12.4 حالت‌های Dev (`?mns_state=`)
فعال فقط وقتی: `MNS_DEV_TOOLS` ≠ false، `ui_static` روشن، کاربر واردشده با `manage_options`. سوییچر شناور در فوتر + لینک UI kit.
- پایه: `default`, `loading`, `empty`, `error`, `long`.
- dashboard: `unpaid`, `active`, `survey`, `new_user` · order-view: همهٔ ۱۰ وضعیت · **new-order: `success`, `no_nurse`** · patients: `form` · invoice-view: `dispute`, `paid`, `success`, `failed`, `pending`, `guest`, `not_owner` · invoices/orders: `filter-empty`, `tab-empty` · loyalty: `full` · surveys: `submitted` · nurse dashboard: `offline` · nurse order-view: `assigned`, `in_progress`, `service_ended`, `needs_review` · signup: `form`, `pending`, `rejected`, `suspended`, `approved`.

### 12.5 Fixtureها (`public/static-fixtures/`)
- patient: `nav`, `profile` (display_name «سارا محمدی»، phone، wallet_balance)، `dashboard`, `new_order`, `orders`, `order_view`, `invoices`, `patients_list`, `recurring`, `loyalty`, `surveys`.
- nurse: `nav`, `profile`, `profile_full`, `dashboard`, `orders`, `order_view`, `availability`, `ratings`.
- catalog: `categories` (۶ دسته)، `services`, `service_sample`.
- admin: `admin/static-fixtures/hub.php`.
- تاریخ‌های fixture نسبت به «اکنون» محاسبه می‌شوند.

### 12.6 وضعیت‌ها (`MNS_Order_Status`)
| کلید | برچسب | tone | تب بیمار |
|---|---|---|---|
| `submitted` | ثبت‌شده | neutral | active |
| `assigned` | تخصیص‌یافته | success | active |
| `in_progress` | در حال انجام | primary | active |
| `medication` | مرحلهٔ دارو | primary | active |
| `service_ended` | پایان خدمت | warning | active |
| `invoiced` | فاکتور صادر شده | warning | unpaid |
| `paid` | پرداخت‌شده | success | done |
| `completed` | تکمیل‌شده | success | done |
| `cancelled` | لغوشده | muted | cancelled |
| `needs_review` | نیازمند بررسی | danger | active |
- `is_cancelable()` فقط submitted/assigned. `stages()`: ثبت، پرستار، خدمت، پرداخت، تکمیل.
- `actions($row)` → `{primary, menu, cancel_note}` مطابق جدول بریف «سفارش‌های من» (بخش 13.3).
- تب‌های سفارش: all همه، active فعال، unpaid منتظر پرداخت، done تکمیل‌شده، cancelled لغوشده. `PER_PAGE=10`.
- فاکتور: وضعیت‌ها `unpaid` پرداخت‌نشده، `dispute` اعتراض در حال بررسی، `paid` پرداخت‌شده؛ نوع‌ها `normal` عادی، `prepay` پیش‌پرداخت، `supplement` مکمل؛ تب‌ها all/unpaid/paid.

### 12.7 نقش‌ها، تنظیمات، فرض‌ها، ادمین
- نقش `mns_nurse` («پرستار»): `read`, `mns_view_nurse_panel`. capها: `manage_mns`, `mns_manage_orders`, `mns_manage_nurses`, `mns_view_nurse_panel`, `mns_view_patient_panel` (subscriber/customer دارند).
- option `mns_settings`: `assignment_mode='manual'`, `ui_static=true`, `assumptions_ver=1`, (`support_phone`). option `mns_db_version`.
- فرض‌های موقت `includes/config/assumptions.php`: `nurse_must_accept_assignment=false`, `nurse_share_mode='percent'`, `nurse_share_percent=70`, `invoice_after='checklist'`, `survey_after='service_end'`, قیمت شب/تعطیل/فوری خاموش، `patient_cancel_until='in_progress'`.
- فیلتر تنها: `mns_dev_states`.
- منوی ادمین «خدمات پرستاری» (`mns-dashboard`, cap `manage_mns`, position 27): پیشخوان، رصد مرکزی (`mns-monitor`)، تخصیص، عضویت پرستاران، سفارش تلفنی، مالی و گزارش، تنظیمات — همه stub.

### 12.8 Assets
- `MNS_Assets::enqueue_public()` فقط روی templateهای MNS: `mns-public` (css/js، نسخه `MNS_VERSION`) + `mnsPublic` (ajaxUrl, restUrl `mns/v1`, nonce, uiStatic, devTools, i18n, calendar, supportTel).
- روی بیمار `orders`/`invoices`/`invoice-view`: **کیت لیست ARP** (`arp-public` css/js) + `pw-datepicker-js` از Persian WooCommerce؛ dequeue `drplus-pdp`/`drplus-pd`؛ `arp-public` وابستگی `mns-public` می‌شود تا MNS برنده باشد.
- `public.css` (~۶۹۵۰ خط):
  - توکن‌ها: `--mns-primary: var(--primary-100, #0272b1)`, `--mns-primary-soft`, `--mns-primary-hover #015a8c`, `--mns-navy #114b5f`, `--mns-bg #f7f7f7`, `--mns-card #fff`, `--mns-text #383838`, `--mns-heading #373434`, `--mns-muted #a6a6a6`, `--mns-muted-strong #747474`, `--mns-border #e6e6e6`, `--mns-radius 16px`, `--mns-radius-shell 24px`, `--mns-success #16a34a`/`#dcfce7`, `--mns-danger #d60043`/`#fde8ec`, `--mns-warn #b7791f`/`#fff5e0`, `--mns-field-min-h 50px`, `--mns-touch-min 44px`, `--mns-space-1..8` (4…48px), `--mns-sidebar-width 306px`, `--mns-font: var(--main-font, Tahoma…)`.
  - بریک‌پوینت‌ها: 1100، 1023، 991، 767 (موبایل)، 640، 480، min 768؛ container query `mns-olist` (min-width 740px) برای جدول/کارت لیست.
  - بخش‌ها: پوسته/سایدبار، کامپوننت‌های فاز ۱، هدر صفحه (آینهٔ `arp-refs__head`)، داشبورد، My orders (`.mns-orders`, `.mns-olist`, `.mns-order`)، Invoices (`.mns-inv*`, `.mns-invd*`)، Order wizard (`.mns-owizard*`, `.mns-osum*`, `.mns-patient-cards`, `.mns-date-rail`, `.mns-slot-chips`, `.mns-nurse-gender`, `.mns-avail--green|yellow|red`, `.mns-qty`, `.mns-upload-zone`, `.mns-review-edit`, `.mns-otp`, `.mns-oconfirm*`, `.mns-owizard__mob-bar`).
- `public.js` (IIFE، بدون وابستگی؛ boot روی DOMContentLoaded یا فوراً — سازگار با تأخیر JS در WP Rocket): `initDrawer`, `initIranMobile`, `initCustomSelects`, `initMockForms` (فرم سفارش را رد می‌کند), `initSwitches`, `initAvailabilityCalendar`, `initStars`, `initRowToggles`, **`initOrderForm`**, `initMockActions`, `initDevTools`, `initAlerts`, `initDialogs` (دیالوگ را به body منتقل می‌کند)، `initCopyButtons` (`[data-mns-copy]`), `initLiveSearch`, **`initOrdersList`** (بازخوانی درجا با fetch+DOMParser و جایگزینی `[data-mns-swap]`، pushState، منوی ⋯ popover/bottom-sheet، لغو mock)، **`initInvoiceView`** (کیف پول، پرداخت، اعتراض).

---

## 13. طراحی‌ها و بریف‌های UI (`desgin/`)

### 13.1 ساختار (مرتب‌شده بر اساس صفحه — ۹ نوامبر ۲۰۲۶)
```
desgin/png/
├─ ثبت سفارش/
│   medyar-desktop-1-patient.png         (2400×2592 — مرحلهٔ ۱ بیمار)
│   medyar-desktop-2-time-place.png      (2400×2792 — زمان و مکان)
│   medyar-desktop-3-service-details.png (2400×2568 — جزئیات خدمت)
│   medyar-desktop-4-review.png          (2400×2472 — بررسی و ثبت)
│   medyar-desktop-5-confirmation.png    (2400×2288 — تأیید)
│   medyar-order-mobile.png              (۴ گوشی + «سه حالت بررسی دسترسی»)
├─ سفارش های من/
│   medyar-my-orders-desktop.png (2160×2666) , medyar-my-orders-mobile.png
└─ فاکتورها و پرداخت ها/
    medyar-invoices-desktop.png , medyar-invoices-mobile.png (لیست، جزئیات، اعتراض، نتیجه + «سایر حالت‌ها»)
    medyar-invoice-detail-desktop.png , medyar-invoice-a4.png
desgin/ui doc/  ثبت سفارش.txt · سفارش های من.txt · فاکتورها و پرداخت ها.txt
```
(نسخه‌های تکراری `(1)` و «New folder» با هش یکسان حذف شدند. فونت PNGها DejaVu است — فونت واقعی سایت استفاده شود.)

### 13.2 بریف «ثبت سفارش»
- ویزارد ۴ مرحله‌ای: **بیمار → زمان و مکان → جزئیات خدمت → بررسی و ثبت** + صفحهٔ تأیید. ادامه فقط با پر بودن فیلدهای اجباری.
- موبایل: نوار ثابت پایین (نام خدمت، بازهٔ قیمت، دکمهٔ ادامه). دسکتاپ: کارت خلاصهٔ ثابت در ستون کناری (RTL: فرم راست، خلاصه چپ).
- ورود از صفحهٔ خدمت با خدمت از پیش انتخاب‌شده؛ چیپ «خدمت: سرم‌تراپی (تغییر)».
- مرحلهٔ ۱: کارت‌های «خودم» / بیماران قبلی / «افزودن بیمار جدید» (accordion: نام، گروه سنی کودک/بزرگسال/سالمند، جنسیت)؛ شماره تماس از حساب + «پرستار و ادمین با همین شماره تماس می‌گیرند»؛ کاربر قدیمی: «خودم» پیش‌انتخاب.
- مرحلهٔ ۲: شهر + منطقه (قابل جستجو)؛ آدرس چندخطی («پلاک، واحد، نشانه»)؛ ردیف ۷ روز (امروز، فردا…) + «تاریخ دیگر»؛ چیپ بازه‌های ساعت؛ جنسیت پرستار دوگزینه‌ای + «این انتخاب قطعی است…»؛ **بررسی دسترسی AJAX** (بدون تعداد/نام پرستار): سبز «برای این زمان و منطقه پرستار موجود است» · زرد «در این بازه پرستاری نداریم. نزدیک‌ترین زمان‌های موجود:» + ۲–۳ چیپ · قرمز «فعلاً برای این منطقه و جنسیت پرستار نداریم» + تماس با پشتیبانی و **ادامه غیرفعال**. سیستم هرگز تغییر جنسیت پیشنهاد نمی‌دهد.
- مرحلهٔ ۳: فیلدهای ضروری هر خدمت از آرایهٔ تنظیمات کد؛ چیپ/رادیو؛ آپلود نسخه (اختیاری، دوربین موبایل)؛ توضیحات.
- مرحلهٔ ۴: خلاصه با «ویرایش» کنار هر بخش؛ بازهٔ قیمت + عوامل؛ قاعدهٔ پرداخت صریح؛ سوییچ «این خدمت تکرار شود»؛ دکمهٔ ثبت با حالت «در حال ثبت» (ضد ثبت دوبل).
- OTP: پیشنهاد در انتهای مسیر (طرح دسکتاپ: OTP ۴ رقمی در مرحلهٔ بررسی).
- تأیید: کد پیگیری بزرگ + کپی، تایم‌لاین (ثبت‌شده، تخصیص‌یافته، در حال انجام، تکمیل)، پیامک تأیید، پشتیبانی، «پیگیری سفارش».
- حالت‌های خاص: پیش‌نویس در مرورگر (با try/catch)؛ خطای شبکه با حفظ فیلدها؛ تداخل لحظه‌ای → برگشت به مرحلهٔ ۲ با پیام زرد.

### 13.3 بریف «سفارش‌های من»
- بخش‌ها: عنوان + تعداد + «ثبت درخواست جدید»؛ (فیلتر بیمار — **به تصمیم کاربر حذف شد**)؛ تب‌ها با شمارنده؛ جستجو + «فیلتر بیشتر»؛ لیست.
- تب‌ها: همه (گروه‌ها: «نیازمند اقدام»، «فعال»، «سوابق») · فعال (مرتب بر زمان مراجعه) · منتظر پرداخت (نقطه/شمارندهٔ نارنجی) · تکمیل‌شده · لغوشده.
- جستجو با کد پیگیری یا نام خدمت (ارقام فارسی/عربی → لاتین سمت سرور). فیلتر: بازهٔ تاریخ شمسی، نوع خدمت، فقط دوره‌ای؛ state در query string.
- کارت موبایل ۵ ردیفه؛ جدول بالای ~۹۰۰px.
- اقدام‌ها: ثبت‌شده/تخصیص‌یافته → «جزئیات» + ⋯ (لغو سفارش، پیگیری درخواست، تماس با پشتیبانی) · در حال انجام → جزئیات (بدون لغو) · منتظر پرداخت → «پرداخت آنلاین» + (مشاهده فاکتور، اعتراض به مبلغ) · اعتراض → جزئیات (پرداخت قفل) · تکمیل بدون نظر → «ثبت نظر» + (فاکتور، درخواست مجدد، همان پرستار) · تکمیل با نظر → «درخواست مجدد» · لغوشده → «درخواست مجدد».
- لغو: فقط قبل از «در حال انجام»؛ دیالوگ «سفارش لغو شود؟» + دلیل اختیاری + «بله، لغو شود»/«انصراف»؛ سمت سرور `UPDATE … WHERE status IN ('submitted','assigned')`؛ اگر پرستار شروع کرده: «پرستار خدمت را شروع کرده، برای لغو با پشتیبانی تماس بگیرید».
- «همان پرستار»: بررسی دسترسی و جنسیت؛ عدم دسترسی → «این پرستار در این زمان آزاد نیست» + «ادامه با انتخاب خودکار».
- خالی: «ثبت اولین درخواست» + شش دسته؛ فیلتر بی‌نتیجه: «سفارشی با این فیلتر پیدا نشد» + «پاک‌کردن فیلتر»؛ تب خالی: پیام مثبت؛ بارگذاری skeleton؛ خطا «بارگذاری نشد» + تلاش دوباره.
- چک‌لیست پیکسلی: `wp-content/plugins/medyar-nursing-services/docs/ui-checklist-my-orders.md`.

### 13.4 بریف «فاکتورها و پرداخت‌ها»
- لینک پیامکی بدون ورود باز نمی‌شود (OTP → بازگشت به همان فاکتور). اعتراض → پرداخت قفل. مابه‌التفاوت پیش‌پرداخت دوره‌ای → خودکار به کیف پول. فاکتور فقط لوگو (بدون آدرس بیمار).
- بخش‌ها: عنوان؛ نوار نارنجی «۲ فاکتور پرداخت‌نشده دارید» + مجموع (یک فاکتور → دکمهٔ پرداخت؛ چند → «مشاهده پرداخت‌نشده‌ها»؛ **«پرداخت همه» نداریم**)؛ کارت «اعتبار کیف پول» + تراکنش‌ها؛ تب‌ها همه/پرداخت‌نشده/پرداخت‌شده؛ جستجو (شماره فاکتور/کد پیگیری)؛ فیلتر (تاریخ، خدمت، نوع فاکتور).
- اقدام‌ها: پرداخت‌نشده → «پرداخت آنلاین» + (مشاهده فاکتور، اعتراض به مبلغ، مشاهده سفارش) · اعتراض → «مشاهده فاکتور» + (مشاهده سفارش، تماس با پشتیبانی) · پرداخت‌شده → «دانلود فاکتور» + (مشاهده، چاپ، مشاهده سفارش).
- جزئیات: نوار «این فاکتور اصلاح شده است (نسخه ۲)»؛ ردیف‌ها (خدمت اصلی، خدمات اضافه، اقلام، دارو/مشاوره، تخفیف‌ها منفی)؛ «مبلغ نهایی قابل پرداخت» درشت‌ترین عدد؛ دکمهٔ «پرداخت آنلاین ۱٬۲۵۰٬۰۰۰ تومان» (چسبیده پایین در موبایل)؛ «فاکتورهای مرتبط» برای دوره‌ای.
- اعتراض: sheet موبایل، چیپ ردیف‌ها (اختیاری) + توضیح اجباری.
- پرداخت: دکمه «در حال انتقال به درگاه»؛ سرور نسخه/عدم اعتراض/پرداخت‌نشده را چک می‌کند؛ «فاکتور بازبینی شد، مبلغ جدید را ببینید»؛ گزینهٔ «استفاده از اعتبار کیف پول (۸۰٬۰۰۰ تومان)» (منوط به تأیید کارفرما).
- نتیجه: موفق («پرداخت با موفقیت انجام شد» + دانلود + بازگشت به سفارش‌ها) · ناموفق («پرداخت انجام نشد» + تلاش دوباره) · نامشخص («در حال بررسی پرداخت» + به‌روزرسانی وضعیت).
- A4: لوگو، «فاکتور خدمات پرستاری»، شماره، تاریخ، وضعیت، اطلاعات، ردیف‌ها، جمع، پرداخت؛ بدون تلفن/آدرس/متن قانونی.

---

## 14. وضعیت فعلی صفحات پنل بیمار (develop، استاتیک)

- **پوسته:** سایدبار ۳۰۶px آینهٔ ARP (آواتار، نشان «پنل بیمار»، نام، موبایل LTR، «موجودی شما»، آیکون‌های تزئینی، منو با hover بدون border)؛ drawer موبایل (backdrop زیر nav)؛ bottom-nav؛ کارت پروفایل آبی موبایل.
- **داشبورد:** هم‌ترازی با اسکرین‌شات و ARP (alert card پرداخت‌نشده، کاشی تکرار، list-item، کارت مهر وفاداری «۳ / ۵»، آمار).
- **سفارش‌های من:** `class-mns-patient-orders.php`، `page-orders.php`، partialها `order-row|order-actions|order-cells|order-table-row|orders-pager`؛ تب‌ها با شمارنده؛ جستجو + فیلترهای ARP؛ لیست فعال (کارت/ردیف تک‌خطی با container query)؛ سوابق در جدول ARP + pager؛ منوی ⋯ (popover دسکتاپ/bottom-sheet موبایل)؛ لغو mock با دیالوگ؛ کد ۵ رقمی.
- **فاکتورها و پرداخت‌ها:** `class-mns-patient-invoices.php`، `page-invoices.php`، `page-invoice-view.php`، `invoice-print.php` (A4)، partialهای `invoice-row|invoice-table-row`؛ نوار پرداخت‌نشده، کارت کیف پول، جزئیات با پرداخت/کیف پول/اعتراض، صفحات نتیجه، چاپ.
- **ثبت سفارش (نسخهٔ 0.5.12–0.5.13):** `page-new-order.php` بازنویسی کامل:
  - عنوان «ثبت درخواست پرستار»، چیپ «خدمت: سرم‌تراپی در منزل — تغییر»، stepper با آیکون (user, map-pin, file-text, shield).
  - مرحلهٔ ۱ «سرم‌تراپی برای چه کسی است؟»: کارت‌ها (افزودن بیمار جدید، فاطمه کریمی، حسن محمدی، «خودم — سارا محمدی» پیش‌انتخاب)، پنل بیمار جدید، شماره تماس.
  - مرحلهٔ ۲ «کجا، کی و با کدام پرستار؟»: شهر/منطقه (تهران: ونک، جردن، سعادت‌آباد، نیاوران؛ کرج: گوهردشت، عظیمیه)، آدرس، ردیف ۷ روز (امروز/فردا/پس‌فردا)، «انتخاب تاریخ دیگر» (فعلاً فیلد متنی)، بازه‌ها ۸–۱۰ … ۱۸–۲۰، کارت «پرستار خانم/آقا»، بنر دسترسی mock: **زرد اگر منطقه «گوهردشت»، قرمز اگر «نیاوران» + آقا** (ادامه غیرفعال، لینک پشتیبانی واقعی)، بقیه سبز؛ چیپ‌های جایگزین 12–14، 14–16، 16–18.
  - مرحلهٔ ۳ «اطلاعاتی که پرستار برای سرم‌تراپی لازم دارد»: نوع سرم (پیش‌فرض نرمال سالین)، تعداد ± (پیش‌فرض ۲)، دسترسی رگ (معمولی)، سابقهٔ حساسیت (ندارد)، آپلود نسخه، توضیحات.
  - مرحلهٔ ۴ «بررسی نهایی و ثبت»: ردیف‌ها با «ویرایش» (پرش به مرحله)، سوییچ تکرار (تناوب + تاریخ پایان)، OTP ۴ رقمی (mock: هر ۴ رقم)، متن قاعدهٔ پرداخت، «ثبت درخواست».
  - تأیید: کارت سبز «درخواست شما ثبت شد»، کد `MD-xxxxx` تصادفی + کپی، تایم‌لاین ۴ مرحله، خلاصهٔ کوچک، «پیگیری سفارش» + پشتیبانی؛ stepper همه done.
  - خلاصهٔ کناری `.mns-osum` (بازهٔ ۸۵۰٬۰۰۰ تا ۱٬۱۰۰٬۰۰۰ تومان؛ ردیف‌ها «در مرحله بعد» تا پر شدن) + «بعد از ثبت چه می‌شود؟»؛ نوار موبایل پایین (64px بالای bottom-nav).
  - پیش‌نویس در `localStorage` با کلید `mns_order_wizard_draft_v1` + بنر «ادامه سفارش / شروع تازه».
  - fixture: `patient/new_order.php` (service، patients، cities، time_slots، service_fields، availability).
- **سایر صفحات** (بیماران من، دوره‌ای، وفاداری، پرسشنامه‌ها، order-view) و پنل پرستار/کاتالوگ/عضویت/ادمین: نسخهٔ فازهای ۳–۶ و UX A–E (هنوز با PNG جدید بازطراحی نشده‌اند).

---

## 15. تاریخچهٔ کار و نسخه‌ها (MNS)

| زمان | کار | نسخه |
|---|---|---|
| ۴ نوامبر | فاز ۰ اسکلت پلاگین (الگوی ARP)، push به گیت‌هاب | 0.1.0 |
| ۴ نوامبر | فاز ۳ فرانت استاتیک، deploy.ps1، ساخت صفحات وردپرس | 0.1.x |
| ۴ نوامبر | رفع باگ‌های ظاهری (`[hidden]`) | 0.1.3 |
| ۵ نوامبر | بازطراحی فازهای ۰–۶: audit، پایه‌ها (Format/Copy/Status/UI/Dev)، کاتالوگ، بیمار، پرستار، عضویت/ادمین | 0.1.4 → 0.1.9 |
| ۶ نوامبر | پرامپت ۲: ممیزی UX در برابر ARP (A–E) | 0.2.0 → 0.2.1 |
| ۶ نوامبر | داشبورد مطابق اسکرین‌شات + سایدبار ARP + موبایل | 0.2.x → 0.4.4 |
| ۷ نوامبر | «سفارش‌های من» از PNG + بریف + اصلاحات کاربر | 0.5.0+ |
| ۷–۸ نوامبر | «فاکتورها و پرداخت‌ها» | → 0.5.11 |
| ۸ نوامبر | «ثبت سفارش» ویزارد جدید | 0.5.12 → 0.5.13 |
| ۹ نوامبر | مرتب‌سازی `desgin/png` + این سند؛ کشف پر شدن دیسک سرور | — |

---

## 16. باگ‌ها، علت‌ها و درس‌ها

| مشکل | علت | راه‌حل |
|---|---|---|
| بخش‌های مخفی نمایش داده می‌شدند | `section{display:block}` قالب بر `[hidden]` | `.mns-dash [hidden], .is-hidden {display:none !important}` |
| دیالوگ با `[hidden]` دیده می‌شد | `display:flex` قالب | `display:none !important` |
| همهٔ صفحات بیمار «پیدا نشد» | وردپرس `$page` سراسری را بعد از `get_header` بازنویسی می‌کند | مقداردهی `$page` بعد از `get_header` |
| سال شمسی اشتباه | `wp_date` ارقام فارسی برمی‌گرداند و تبدیل خراب می‌شد | `MNS_Patient_Invoices::now()` |
| overlay موبایل روی nav | `isolation: isolate` روی `.mns-dash` | انتقال backdrop/دیالوگ به `body`؛ z-index بالا |
| انتقال سایدبار به body → عرض ۱۰۰٪ دسکتاپ | reparent سایدبار | سایدبار را منتقل نکن |
| ردیف فاکتور دسکتاپ چندخطی | `grid-template-areas` در container پهن ریست نمی‌شد | ریست areas/columns در `@container` |
| مهر وفاداری «۵ / ۳» | RTL | `dir="ltr"` |
| کش CSS/JS | WP Rocket/LiteSpeed | بالا بردن `MNS_VERSION` + `mns_nocache=1` |
| پارامتر اشتباه | `mns_view` | `mns_page` |
| invoice-view 404 | نبود در whitelist | افزودن به `patient_slugs()` |
| اسکرین‌شات CDP هنگ | ابزار | تأیید با `Runtime.evaluate` |
| FTP خطای proxy | پروکسی سیستم | `$req.Proxy = $null` |
| `&&` در PowerShell | نسخهٔ PS | `;` |
| BOM در index.php | ادیتور | بازنویسی بدون BOM |
| **آپلود → فایل ریموت ۰ بایت** | سهمیهٔ دیسک پر (552) وسط آپلود | آزادسازی فضا و آپلود دوباره؛ در آینده آپلود موقت + rename |
| خلاصهٔ مکان «انتخاب کنید، ابتدا شهر…» | خواندن متن option placeholder | فقط وقتی `value` دارد |
| تداخل lab/pharmacy | توابع JS سراسری هم‌نام | نام‌گذاری مجدد و scope (پچ ۱۰) |
| پیامک‌های تکراری/اسپم رزرو | چند مالک | mu-plugin + پچ PWSMS + صف |

---

## 17. TODOها، سؤال‌های باز، ناهماهنگی‌ها

### 17.1 کارهای باز فوری
1. بررسی فایل‌های مشکوک ریشهٔ وب develop (بخش ۱۸) و تصمیم دربارهٔ zipهای باقی‌مانده داخل `plugins/` و `themes/`.
2. مقایسهٔ پیکسلی ویزارد ثبت سفارش و فاکتورها با PNG (فعلاً کارکردی تأیید شده).
3. «انتخاب تاریخ دیگر» → تقویم شمسی واقعی (`pw-datepicker` مثل ARP).
4. بازطراحی سایر صفحات (order-view، بیماران من، دوره‌ای، وفاداری، پرسشنامه، پنل پرستار) در صورت دریافت طرح.
5. بک‌اند: جداول، endpointهای AJAX، تخصیص، فاکتور، زرین‌پال، کیف پول، پیامک‌ها.

### 17.2 TODO(backend) در کد MNS
- endpoint واحد AJAX سفارش‌ها (patient, tab, q, from, to, service, recurring, sort, order, page) + شمارنده‌ها + بررسی مالکیت + ایندکس (account, status, created).
- لینک پرداخت توکن‌دار و نسخه‌دار؛ لینک PDF توکن‌دار؛ PDF فارسی سمت سرور با فونت embed.
- بررسی دسترسی + جنسیت برای «همان پرستار».
- اعتراض → وضعیت dispute + اعلان ادمین + قفل پرداخت.
- لغو اتمیک `UPDATE … WHERE status IN ('submitted','assigned')` + آزادسازی اسلات + پیامک.
- verify درگاه فقط سمت سرور با کلید یکتا؛ برگشت مابه‌التفاوت یک‌باره به کیف پول.
- اگر کیف پول کل فاکتور را پوشش دهد، تسویه بدون درگاه.
- خواندن کیف پول واقعی (Wallet_Manager / Sheyda).

### 17.3 تصمیم‌های باز کارفرما
- گزینهٔ جنسیت «فرقی ندارد» مجاز است؟ (فعلاً اضافه نشده)
- OTP در ابتدا یا انتهای ثبت سفارش؟ (فعلاً در مرحلهٔ بررسی)
- خرج اعتبار کیف پول بیمار برای فاکتور؟ پیامک شارژ کیف پول بیمار؟
- نمایش شمارهٔ پرستار به بیمار؟ (فعلاً نه؛ فقط پشتیبانی)
- بازهٔ ثابت یا ساعت آزاد؛ ساعات کاری.
- آپلود نسخه برای کدام خدمات لازم است؟
- تخصیص قطعی یا پذیرش پرستار؟ سهم پرستار درصدی یا ثابت (فرض: ۷۰٪)؟ فاکتور بعد از چک‌لیست یا گزارش؟ پرسشنامه کی؟
- مدت حضور پله‌ای/ساعتی؛ ضریب شب/تعطیل/فوری؛ قیمت شهرها؛ کاتالوگ اقلام.
- قاعده و مهلت لغو بیمار؛ لغو نوبت دوره‌ای پیش‌پرداخت (فقط پشتیبانی یا برگشت به کیف پول؟).
- چند روز جلوتر نوبت‌های دوره‌ای ساخته شوند؛ قواعد نشان؛ ترکیب تخفیف اول و وفاداری؛ انقضای کد.
- متن قرارداد، شهربندی واقعی، SLA سفارش فوری، کیف پول پرستار داخل MNS یا MTW.

### 17.4 ناهماهنگی‌ها
- بریف اصلی: «بازگشت وجه نداریم» ↔ بریف فاکتورها: برگشت مابه‌التفاوت به کیف پول.
- لغو دوره‌ای پیش‌پرداخت در دو بریف UI متفاوت پیشنهاد شده.
- قاعدهٔ لغو در بریف UI قطعی، در بریف اصلی سؤال باز.
- قالب کد پیگیری: UI جدید `MD-48215` ↔ قدیمی `MNS-۱۴۰۵-…`.
- `UI-UX.md` پلاگین توکن‌ها/بریک‌پوینت‌هایی دارد که با کد فرق دارند (success، urgent، 900/1200).
- توکن‌های skill from-screen-ux (`#0a6cb6`…) ↔ تصمیم کاربر: توکن‌های سایت (`#0272b1`).

---

## 18. مشاهدات امنیتی و سلامت سرور

**دیسک (۹ نوامبر ۲۰۲۶، سهمیهٔ FTP پر شد):**
- حذف‌شده با اجازهٔ کاربر: `wp-content/plugins-2.zip` ≈ 334MB، `plugins-3.zip` ≈ 364MB، `plugins.zip` ≈ 161MB، `themes.zip` ≈ 78MB.
- هنوز باقی: `wp-content/plugins/plugins.zip` ≈ 182MB، `elementor.zip` 24MB، `woocommerce.zip` 18MB، `wordfence.zip` 8MB، و zipهای کوچک‌تر.
- `wp-content/themes/dr-plus.zip` ≈ 39MB، `themes/dr-plus/dr-plus-theme.zip` ≈ 19MB.
- `wp-content/duplicator-backups/`.
- zipهای داخل `plugins/`/`themes/` از وب قابل دانلودند (افشای کد تجاری) — بهتر است حذف یا بیرون از webroot منتقل شوند.

**فایل‌های مشکوک در ریشهٔ وب develop (بررسی فوری لازم — احتمال آلودگی):**
`alertpay_adverts`, `index_enc_zend`, `invitation`, `k2usergroup`, `memory.lib`, `news_moderate`, `styles.bak`, `tbl_change`, `toplayout`, `tradelog.inc`, `vcard.class` (هر کدام ~۲.۲–۲.۶MB، نام‌های بی‌ربط به وردپرس)، `Medyar.jpg` (۲.۳MB هم‌اندازهٔ همین‌ها)، `stack.php`، `fvs.txt` (در ریشه و wp-content)، `tmp-probe-faraz-notice*.js`، `error_log`. الگوی نام‌گذاری شبیه dropperهای بدافزار است؛ با Wordfence اسکن و با نسخهٔ سالم مقایسه شود.

**کد:**
- Lab/Pharmacy: `lab_submit`/`phar_submit` بدون nonce و بدون بررسی مجدد OTP سمت سرور؛ آپلود بدون اعتبارسنجی نوع؛ پوشه‌های `lab_secure`/`pharmacy_secure` فقط `Options -Indexes` (فایل با URL مستقیم در دسترس)؛ SQL جستجوی ادمین با درون‌گذاری رشته.
- `medyar-toman-wallet/_mtw_probe.php` پروب دیباگ قابل دسترس از وب.
- `lab-booking-system/error_log` (۸۴KB) در ریشهٔ پلاگین.
- `dr-plus/log.txt` و لاگ‌های دیگر؛ `.htaccess` باید `*.log` را بلاک کند (پچ ۱۴).
- `.vscode/sftp.json` حاوی اطلاعات ورود است (gitignored) — به اشتراک گذاشته نشود.

---

## 19. واژه‌نامه

| اصطلاح | معنی |
|---|---|
| MNS | Medyar Nursing Services — پلاگین خدمات پرستاری |
| ARP | پلاگین affiliate-referral (طرح همکاران مدیار)؛ مرجع UI |
| MSQ | Medyar SMS Queue |
| MTW | Medyar Toman Wallet |
| MCP | Medyar Chat Pro |
| MMAD | Medyar My Account Dashboard |
| PWSMS | پلاگین پیامک حرفه‌ای ووکامرس (persian-woocommerce-sms) |
| Sheyda | کیف پول داخلی قالب Doctor Plus |
| Faraz / IranPayamak | سرویس پیامک (پترن‌ها) |
| پترن | قالب پیامک ازپیش‌تأییدشده با متغیر |
| fixture | دادهٔ نمونهٔ PHP برای UI استاتیک |
| `MNS_UI_STATIC` | حالت فرانت استاتیک |
| کد پیگیری | `MD-xxxxx` (۵ رقم) |
| تخصیص | انتساب پرستار به سفارش |
| فاکتور مکمل / پیش‌پرداخت | انواع فاکتور خدمات دوره‌ای |
| مهر وفاداری | هر خدمت تکمیل‌شده = یک مهر؛ ۵ مهر = کد تخفیف |
| «نیازمند بررسی» | وضعیت استثنا (عدم تأیید پزشک، مورد فوری) |
| desgin | نام پوشهٔ طراحی‌ها (با همین املا) |
