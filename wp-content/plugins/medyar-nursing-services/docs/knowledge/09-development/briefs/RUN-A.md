# RUN-A — اجرای خودکار پیوسته: بقیهٔ M0 + کل M1 (این فایل را به‌تنهایی به Cursor بده)

> **این‌بار بدون توقف برای تأیید کار کن.** مالک نمی‌خواهد بین کارت‌ها مزاحمش شوی. ناظر (Claude) بعداً و به‌صورت ناهمزمان گزارش‌هایت را بازبینی می‌کند و اگر لازم شد اصلاح می‌کند. تو **همهٔ کارت‌های زیر را پشت‌سرهم** می‌سازی، خودت آپلود می‌کنی، خودت بررسی ساده می‌کنی و گزارش می‌نویسی.

## ۰. اول بخوان (بدون کد) و در ۱۰ خط خلاصه کن
`PROJECT-LOGIC.md` · `09-development/` فایل‌های `README, 01, 02, 03, 04, 05, 06` · `08-task-cards.md` (کارت‌های T-0.3 تا T-1.5) · rule فعال `.cursor/rules/mns-backend.mdc` · کد موجود T-0.1/T-0.2 (`includes/class-mns-backend.php`, `includes/db/*`, `includes/backend/*`).

## ۱. پروتکل (برای هر کارت، به همین ترتیب)
1. **بخوان** کارت در `08-task-cards.md` + جزئیات این فایل (§۴) + قواعد `R-xxx` و جدول‌ها (`02`, `03`, `04`).
2. **قبل از ویرایش** هر فایلِ *موجود* (نه جدید): یک کپی از نسخهٔ فعلی در `.deploy-backup/<کارت>/` (مسیر نسبی حفظ شود؛ این پوشه gitignored است) بگذار.
3. **بساز.** هر قابلیت جدید یک **ماژول** است که از `MNS_Backend::modules()` لود می‌شود (الگوی `MNS_Backend_Db`). هیچ `add_action` پراکنده خارج از ماژول‌ها. کلاس‌ها را در نقشهٔ `MNS_Autoloader` ثبت کن.
4. **بازبینی خودت** (PHP نداری): خط‌به‌خط بخوان؛ تعادل `{}()[]`، `;`، کاما، `ABSPATH` guard، بدون `?>`، فقط PHP ≤ 8.0، هر `prepare` درست، خروجی escape، nonce+cap، بدون `@/eval`. هر شک ⇒ ساده‌تر بنویس.
5. **آپلود:** فقط فایل‌های همین کارت (افزوده/تغییر) داخل `wp-content/plugins/medyar-nursing-services/` با:
   `powershell -NoProfile -ExecutionPolicy Bypass -File .\deploy.ps1 <مسیرهای نسبی از ریشهٔ ریپو...>` (از ریشهٔ ریپو اجرا کن؛ **`.ftp.env` را هرگز نخوان/چاپ نکن**؛ هیچ فایل بیرون از پوشهٔ پلاگین آپلود نشود؛ خروجی باید برای هر فایل `OK` باشد).
6. **بررسی زنده (خودکار، بدون ورود):** همهٔ این URLها باید HTTP 200 بدهند و در بدنه نباید `Fatal error`/`Parse error`/`critical error` باشد:
   `/`, `/nursing-nurse-panel/?mns_panel=nurse&mns_page=dashboard`, `/nursing-patient-panel/?mns_panel=patient&mns_page=dashboard`, `/nursing-patient-panel/?mns_panel=patient&mns_page=new-order`, `/nursing-nurse-signup/` (روی `https://develop.medyarco.com`). (مثلاً با `curl -s -o NUL -w "%{http_code}"` و جستجوی متن در بدنه.)
7. **اگر بررسی شکست خورد:** فوراً نسخهٔ پشتیبان فایل‌های *موجودِ* تغییرکرده را از `.deploy-backup/<کارت>/` دوباره آپلود کن؛ فایل‌های جدید ماژولی‌اند و با Safe boot ایزوله می‌شوند (حذفشان لازم نیست). یک‌بار دیگر بررسی ۶ را بزن. اگر هنوز خراب بود ⇒ **متوقف شو** و `reports/BLOCKED-<کارت>.md` بنویس.
8. **گزارش:** `docs/knowledge/09-development/reports/<کارت>.md` با: فایل‌ها (مسیر کامل)، migration (نسخه/جدول‌ها)، فرضیات، مغایرت با سند، پرسش‌های باز، سناریوهای AC که برای self-test نوشتی، نتیجهٔ بررسی ۶. سپس **بدون انتظار** کارت بعد.
9. commit/push **نکن.**

## ۲. شرط‌های توقف (فقط این‌ها؛ غیر از این ادامه بده)
- قاعده‌ای در اسناد نیست/متناقض است یا تصمیم محصول لازم است (برچسب [باز]) و پیش‌فرض امن تنظیم‌پذیر نمی‌شود نوشت.
- نیاز به رمز/merchant/شمارهٔ واقعی یا ویرایش بیرون از پلاگین.
- بررسی زنده بعد از بازگردانی هم خراب است.
- سه بار تلاش ناموفق برای رفع یک مشکل.
در این حالت `reports/BLOCKED-<کارت>.md` (شرح مشکل، آنچه امتحان کردی، گزینه‌های پیشنهادی) بنویس و **فقط اگر کارت‌های بعدی وابسته‌اند توقف کن**؛ کارت‌های مستقل را ادامه بده.

## ۳. قواعد سخت (مثل rule فعال، یادآوری)
بیرون از `wp-content/plugins/medyar-nursing-services/` هیچ تغییری · migration فقط افزایشی و **نسخهٔ جدید** (`MNS_Schema::sql(2)`…؛ `LATEST` را بالا ببر؛ SQL نسخهٔ ۱ را **هرگز ویرایش نکن**) · هیچ `DROP/TRUNCATE` · هیچ مقدار واقعی رمز/merchant · پیامک فقط MSQ · وضعیت سفارش فقط با ماشین وضعیت · مقدارهای قابل‌تغییر در `MNS_Settings`.

## ۴. کارت‌ها به ترتیب و جزئیات تکمیلی (علاوه بر `08-task-cards.md`)

**T-0.3 تنظیمات** — `MNS_Settings` طبق `06-settings-registry.md` (کلیدها و پیش‌فرض‌ها همان‌هاست؛ `S-SLOTS` با ۶ بازه و ساعت‌ها). `definitions()/get()/set()/all()`؛ اعتبارسنجی نوع/محدوده؛ مقدار نامعتبر ⇒ پیش‌فرض. صفحهٔ ادمین «تنظیمات MNS» (زیر منوی «خدمات پرستاری» موجود در `MNS_Admin_Menu`؛ اگر جای مناسب نبود زیر «ابزارها») با فرم گروه‌بندی‌شده، nonce+cap `manage_options`. option `mns_settings` موجود است (`assignment_mode`, `ui_static`…) — **کلیدهای قبلی حفظ شوند**.

**T-0.4 خطا، لاگ، REST پایه** — `MNS_Error` (code, message_fa, http, field, data)، `MNS_Log` (option چرخشی ۲۰۰ خط یا جدول سبک؛ ترجیح: option `autoload=false`)، `MNS_REST` طبق `04-api-contract.md §۱`: envelope، `?_envelope=1`، nonce `wp_rest`، helperهای `permission` (`patient()`, `nurse()`, `admin()`)، rate limit با transient، `Idempotency-Key` با transient ۲۴ ساعته. یک route آزمایشی `GET /mns/v1/ping` (Auth کاربر واردشده؛ خروجی `{ok:true,data:{time}}`) برای تست.

**T-0.5 دامنه: ماشین وضعیت و سرویس‌های خالص** — `MNS_Order_Machine::transition()` (انتقال‌های `create, assign, reassign, cancel, cancel_admin` طبق `03 §۱`؛ `UPDATE … WHERE status IN(…)` + بررسی `rows_affected`؛ `order_log` در همان تراکنش `MNS_DB::tx`؛ هوک `mns_order_transitioned` **بعد از commit**). `MNS_Eligibility::nurses_for(array $scope)` و `MNS_Availability::check(array $scope)` دقیقاً R-ELG-1..4 و R-A13-1..3 (+R-A13-2 رزرو منطقی). `MNS_Nurse_Schedule::effective($nurse,$day,$slot)` (R-ELG-3). **همه خالص و بدون UI/REST.** ورودی/خروجی آرایه؛ زمان‌ها با `MNS_Time` (UTC در DB، `Asia/Tehran` برای تاریخ خدمت؛ تابع `slot_start_ts($date,$slot)` از `S-SLOTS`).

**T-0.6 Self-test و ریست** — صفحهٔ ادمین `tools.php?page=mns-selftest`: دکمهٔ «اجرای همه»؛ رجیستری `MNS_Selftest::register($ac_id, $title, callable)` که هر سناریو `array('ok'=>bool,'detail'=>string)` برمی‌گرداند؛ **داده‌ٔ تست فقط با علامت `mns_selftest`** (کاربر تست = کاربر وردپرس ساخته‌شدهٔ موقت با نام `mns_selftest_*` که در پایان پاک می‌شود، یا ردیف‌هایی که با شناسه‌هایشان پاک می‌شوند) و پاک‌سازی تضمینی (`finally`). خروجی جدول PASS/FAIL و **یک عنصر `<div id="mns-selftest-summary" data-pass="N" data-fail="M">`** (برای خواندن ماشینی). سناریوهای `AC-M0-2,4,5,6` و `AC-ELG-*`, `AC-A13-*` (دادهٔ نمونه: ۳–۴ پرستار تستی با برنامه/منطقه/جنسیت متفاوت). ابزار «بازنشانی MNS» (`tools.php?page=mns-reset`): drop+ساخت دوباره **فقط جدول‌های `mns_`** + پاک‌سازی پوشهٔ فایل خصوصی + option‌های `mns_schema_version`/`mns_migration_log`؛ فرم دومرحله‌ای (تایپ `RESET MNS` + nonce + `manage_options`)؛ **این تنها جایی است که `DROP TABLE` مجاز است، فقط روی نام‌های `MNS_DB::table()`.**

**T-0.7 فایل خصوصی** — `MNS_Files` + REST `GET /mns/v1/files/{id}` طبق `05 §۷` و `R-PRV-4`: ذخیره در `wp-content/uploads/mns-private/{yyyy}/{mm}/` با `.htaccess` (`Require all denied` + `deny from all`) و `index.php`، نام تصادفی، اعتبارسنجی نوع واقعی/حجم، حذف EXIF/GPS برای jpg با GD، حداکثر ۲۰۴۸px؛ ثبت در `mns_files`. دسترسی: مالک یا ادمین. سناریو `AC-M0-8`.

**T-1.1 دامنه: درخواست و تأیید پرستار** — `MNS_Nurse_Applications` (`apply`, `approve`, `reject`, `suspend`, `reinstate`)، `MNS_Contract` (نسخه‌ها؛ seed یک متن **موقت** «متن قرارداد موقت — بعداً جایگزین می‌شود» به‌عنوان نسخهٔ ۱ فعال)، `MNS_Nurse_Access::gate()`؛ R-NRS-1..5؛ کد ملی با checksum؛ ذخیرهٔ مدارک با `MNS_Files`؛ ثبت IP با `inet_pton`؛ هوک‌های `mns_nurse_approved/rejected`. سناریوهای `AC-M1-1..4`.

**T-1.5 پیامک** — `MNS_Sms` طبق `05 §۴`: ثابت‌های رویداد (۱۲ مورد)، پترن از `S-SMS-<EVT>`، `MSQ_Client::enqueue`، dedupe، اگر `MSQ_Client` نبود/پترن خالی ⇒ فقط لاگ. رویدادهای #1 #2 #3 را به `mns_nurse_*` وصل کن. بعد از commit تراکنش.

**T-1.3 ادمین: درخواست‌ها** — `MNS_Nurses_Table` (WP_List_Table: فیلتر وضعیت، جستجو)، جزئیات، تأیید/رد(دلیل)/تعلیق/بازگردانی (`admin_post_*` + nonce + `mns_manage_nurses`)، نمایش مدارک (از `files/{id}` در تب جدید)، تعیین خدمات مجاز (جدول `nurse_services`؛ فهرست خدمات از `mns_services`—اگر خالی است پیام «هنوز خدمتی تعریف نشده» و seed دو خدمت `serum`,`injection` را **همین‌جا** انجام بده: ستون‌های `slug,category,name,active,price_from,price_to,has_drug_step=1 برای serum`, `config` JSON حداقلی از fixtureهای `catalog/services.php` و `patient/new_order.php` (`order_fields`)) ، تأیید عکس پروفایل، مدیریت نسخهٔ قرارداد. یک منوی «عضویت پرستاران» (cap `mns_manage_nurses`).

**T-1.2 صفحهٔ فرم عضویت** — مطابق `briefs`/`05 §۳` و `01 §۴`: جایگزین کامل `public/views/nurse/signup-stub.php`؛ ساختار صفحهٔ «طرح همکاران» (کارت فرم + کارت «چطور کار می‌کند»؛ مرجع read-only: `affiliate-referral/public/views/signup.php` و CSS `.arp-submit*`)؛ کلاس‌ها `mns-nsu*` با CSS در انتهای `public.css` (توکن‌های `--mns-*`؛ شعاع‌ها/رنگ‌ها مثل بقیهٔ پنل؛ از `.mns-field-block*` استفاده کن)؛ gate لاگین (`/?login=1&redirect_to=…`)؛ سوییچ «ورود کاربر / ورود پرستار» روی `drplus/auth/form/start` (الگوی `Arp_Affiliate_Access::auth_role_switch`، با `class_exists` محافظت)؛ ۴ حالت (فرم/در حال بررسی/ردشده/تعلیق/تأییدشده)؛ `admin_post_mns_nurse_apply` (API-NRS-1) با اعتبارسنجی هر فیلد و بازگشت با خطا؛ آپلود چندفایلی. **ظاهر باید با کیفیت بقیهٔ پنل باشد؛ موبایل بدون اسکرول افقی.** CSS/JS تغییر کند ⇒ فایل‌های `assets/css/public.css` و `assets/js/public.js` هم آپلود شود.

**T-1.4 پروفایل پرستار واقعی** — `GET/PATCH /mns/v1/nurse/me` + عکس (API-NRS-2/3)؛ `public/views/nurse/page-profile.php` فقط وصل داده (ساختار/CSS بدون تغییر)؛ `initNurseProfile` در `public.js` ذخیرهٔ واقعی (همان UX: خطای فیلد، پیام موفقیت)؛ موبایل و جنسیت ویرایش‌ناپذیر؛ gate R-NRS-3 برای همهٔ صفحات پنل پرستار: `pending/rejected` ⇒ صفحهٔ وضعیت، `suspended` ⇒ پیام تعلیق. **فقط وقتی کاربر `mns_nurse` واقعی است از داده واقعی بخوان؛ برای ادمین/پیش‌نمایش (dev) همان fixture بماند** تا صفحات استاتیک دیگر نشکنند.

## ۵. تحویل نهایی RUN-A
وقتی همهٔ کارت‌ها تمام شد: `reports/RUN-A-DONE.md` بنویس (فهرست کارت‌ها و وضعیت، فایل‌ها، migrationها، فرضیات، پرسش‌های باز، ریسک‌ها) و **توقف کن**. در طول کار پیام‌های میانی لازم نیست.
