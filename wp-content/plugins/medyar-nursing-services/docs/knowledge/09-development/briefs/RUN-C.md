# RUN-C — اجرای خودکار پیوسته: بستن شکاف‌های برش اول (A-13 زنده، آپلود نسخه، دسترسی پرستار، تست‌های واقعی) — این فایل را به‌تنهایی به Cursor بده

> سبک اجرا دقیقاً مثل RUN-A/RUN-B: **بدون توقف برای تأیید، پشت‌سرهم**، خودت آپلود و بررسی می‌کنی، گزارش می‌نویسی؛ ناظر (Claude) ناهمزمان بازبینی می‌کند. **commit/push نکن.**

## ۰. اول بخوان (بدون کد) و در ۱۰ خط خلاصه کن
`PROJECT-LOGIC.md` · `09-development/` فایل‌های `README, 01..06` · `08-task-cards.md` (**به‌ویژه بخش‌های «یادداشت‌های ناظر»**) · `.cursor/rules/mns-backend.mdc` · `briefs/RUN-A.md` §۱–۳ (پروتکل) و `briefs/RUN-B.md` §۲ (درس‌ها؛ **همه معتبرند**) · `reports/RUN-B-DONE.md` · کد: `includes/services/class-mns-orders.php`, `class-mns-assignment.php`, `class-mns-patient-orders-api.php`, `class-mns-nurse-orders-api.php`, `class-mns-files.php`, `includes/rest/class-mns-rest-patient.php`, `includes/class-mns-selftest-scenarios.php`, `public/views/patient/page-new-order.php`, `assets/js/public.js` (`initOrderForm`).

## ۱. پروتکل
مثل RUN-A §۱ (بکاپ در `.deploy-backup/<کارت>/`، ماژول، بازبینی خودت، `deploy.ps1`، بررسی زنده، بازگردانی، گزارش). **در پوشهٔ ریشه یا هرجای بیرون از پلاگین هیچ فایلی نساز** (در RUN-A و RUN-B نسخهٔ `class-mns-backend.php` در ریشهٔ ریپو جا ماند؛ تکرار نشود). فایل `public/views/nurse/page-ratings.php` را **لمس نکن** (کار ناتمام دیگری است).

## ۲. درس‌های جدید ناظر از RUN-B (علاوه بر RUN-B §۲)
1. **`MNS_Time::slot_known()` را همه‌جا بگذار** (تاریخ‌های `Y-m-d` هم با `$d->format('Y-m-d') === $date` سخت‌گیر شوند).
2. **Idempotency در `MNS_REST::idempotency_key()` به کاربر محدود شده**؛ کلیدها را مستقیم از هدر نخوان.
3. **هر صفحهٔ ادمین که منوی اسکلت را جایگزین می‌کند** باید `remove_all_actions( get_plugin_page_hookname( SLUG, MNS_Admin_Menu::SLUG_DASHBOARD ) )` بزند، وگرنه صفحه دوبار رندر می‌شود.
4. **دادهٔ self-test هرگز نباید برای کاربر واقعی دیده شود** (`list_active` فیلتر `mns_selftest*` دارد؛ ولی پاک‌سازی `finally` همچنان واجب است، همهٔ جدول‌ها: orders, order_log, order_edits, notifications, files, patients, nurse_*، کاربران `mns_selftest_*`).
5. خطاهای غیر-2xx در این هاست به صفحهٔ HTML تبدیل می‌شوند؛ **همهٔ fetchهای JS باید `?_envelope=1` داشته باشند** (در JS موجود رعایت شده).
6. پیش از هر ویرایش فایلی که ممکن است ناظر هم ویرایش کرده باشد (مثل `class-mns-rest.php`, `class-mns-orders.php`, `class-mns-time.php`, `class-mns-rest-patient.php`, `class-mns-services.php`, ادمین‌های nurses/settings/orders)، **نسخهٔ فعلی روی دیسک را بخوان**؛ تغییرات ناظر را بازنویسی نکن.

## ۳. کارت‌ها به ترتیب

**T-C.1 — پاک‌سازی بقایا و سلامت دادهٔ تست.**
در شروع `MNS_Selftest::run_all` یک `purge_leftovers()` اجرا کن که هر چیز با علامت `mns_selftest` را (سرویس‌های `slug LIKE 'mns\_selftest%'`، کاربران `user_login LIKE 'mns\_selftest\_%'` و ردیف‌های وابسته‌شان) پاک کند. ردیف سرویس `mns_selftest_svc` که الان در دیتابیس develop مانده با همین مسیر پاک شود. هیچ سناریوی موجود نباید دیگر چیزی جا بگذارد؛ بعد از اجرا بشمار و در `detail` گزارش بده (`leftover=0`).

**T-C.2 — آپلود نسخه (الان برای سفارش‌های دارای نسخه شدنی نیست).**
`validate_essentials` در `MNS_Orders` برای سرم‌های نیازمند نسخه `essentials.rx_file_id` می‌خواهد، ولی هیچ مسیری برای آپلود نسخه توسط بیمار نیست. بساز:
- `POST /mns/v1/patient/files` (multipart، فیلد `file`، `kind=prescription`) با `permission_patient`؛ از `MNS_Files::store_upload` با `$is_photo=true` (عکس؛ حجم از `fil_photo_max`)؛ خروجی `{file_id}`؛ rate limit؛ پاک‌سازی فایل‌های یتیم (فایل‌های `prescription` بدون سفارش بعد از ۲۴ ساعت: cron روزانه).
- در `initOrderForm`: هنگام انتخاب فایل نسخه (`[data-mns-prescription-file]`)، فایل را آپلود کن (با `?_envelope=1`، nonce)، `file_id` را نگه دار و در ثبت سفارش در `essentials.rx_file_id` بفرست؛ نمایش پیشرفت/خطا مثل UI موجود (بدون تغییر ظاهر). اگر آپلود شکست خورد پیام فارسی مناسب و جلوگیری از ثبت.
- هنگام ساخت سفارش، `order_id` فایل را روی ردیف `mns_files` ثبت کن (در همان تراکنش یا بلافاصله بعد از موفقیت) تا cron پاک نکند.

**T-C.3 — دسترسی پرستار به نسخه (R-PRV-1/2).**
پرستارِ تخصیص‌یافته فقط تا وقتی سفارش `assigned/in_progress` است بتواند فایل `prescription` همان سفارش را از `GET /mns/v1/files/{id}` بخواند؛ بعد از `service_ended`/لغو/تغییر پرستار **دیگر نه**. در `MNS_Files::can_read` فقط یک شاخهٔ مشخص اضافه کن (نه شل‌کردن عمومی). فایل مدارک پرستار (national_card…) هرگز برای پرستار دیگر/بیمار باز نشود. سناریوی تست: پرستار تخصیص‌یافته ✓، پرستار دیگر ✗ (404)، بیمار مالک ✓، بیمار دیگر ✗.

**T-C.4 — A-13 زنده در صفحهٔ «ثبت سفارش».**
الان `page-new-order.php`/`initOrderForm` با دادهٔ ثابت `data-mns-availability` (fixture: `red_area_gender`, `yellow_areas`…) تصمیم می‌گیرد. جایگزین کن (فقط وقتی `data-mns-live="1"`؛ در حالت dev/ادمین/fixture رفتار قبلی بماند):
- بعد از انتخاب منطقه + جنسیت + تاریخ + بازه (و هر تغییر آن‌ها)، با debounce ~۳۰۰ms `GET /mns/v1/availability/check?...&_envelope=1` بزن؛ پاسخ را به همان رفتار UI موجود (پیام سبز/زرد/قرمز زیر کارت جنسیت، بازهٔ پیشنهادی در زرد، قفل «ادامه» در قرمز) نگاشت کن؛ **ساختار و CSS تغییر نکند.**
- پاسخ‌های قدیمی (race) نادیده گرفته شوند (شمارندهٔ درخواست)؛ خطای ۴۲۲/۴۲۹/شبکه ⇒ پیام ملایم و **ثبت را قفل نکن** (سرور هنگام ثبت دوباره می‌سنجد).
- هیچ‌جا تعداد یا نام پرستار نمایش داده نشود.
- فهرست `alt` (اگر هست) را فقط به‌عنوان پیشنهاد بازه/روز نشان بده.

**T-C.5 — سناریوهای self-test واقعی برای ثبت سفارش و تخصیص (مهم‌ترین کارت).**
تا الان هیچ تستی ثبت/تخصیص را نمی‌سنجد. با کاربران و پرستاران تستی (`mns_selftest_*`, با برنامه/منطقه/جنسیت/خدمت مناسب؛ داده‌ای که `Availability` سبز بدهد) و فراخوانی از طریق `rest_do_request` با `wp_set_current_user` (تا مسیر واقعی REST/permission هم آزموده شود)، این‌ها را بنویس؛ هر کدام واقعاً مسیر شکست را هم بسنجد:
- AC-M3-3 ثبت موفق (۲۰۱، کد ۵رقمی، `order_log` ساخته شد، با `assignment_mode=auto` پرستار تخصیص خورد و `mns_order_transitioned` فراخوانی شد).
- AC-M3-4 رد: بازهٔ ناشناخته، تاریخ بد، جنسیت خالی، نشانی خالی، شهر نامعتبر، بیمار غیرمالک (۴۰۴)، `rx_file_id` متعلق به کاربر دیگر، essentials ناقص.
- AC-M3-5 هم‌زمانی: وقتی ظرفیت یک پرستار فقط ۱ سفارش است، ثبت دوم همان بازه ⇒ `ERR_NO_NURSE`/`ERR_SLOT_TAKEN` (می‌توانی در یک درخواست با `GET_LOCK` از اتصال دوم قفل را نگه داری و مطمئن شوی ثبت دوم رد می‌شود).
- AC-M3-6 Idempotency: دو بار با یک کلید ⇒ یک سفارش؛ کلید کاربر دیگر ⇒ سفارش جدید.
- AC-M3-7 شمارهٔ متفاوت ⇒ `ERR_OTP_REQUIRED`.
- AC-ASG-1..6: `auto_assign`، `admin_set_nurse` (تعلیق‌شده رد، ناواجد بدون `force` ⇒ `ERR_CONFIRM`، با `force` موفق)، reassign، لغو ادمین، همه با بررسی `order_log`.
- AC-M3-10..12: بیمار فقط سفارش‌های خودش را می‌بیند (دیگری ۴۰۴)، لغو بیمار فقط در وضعیت‌های مجاز، ویرایش محدود.
- AC-M3-13: پاسخ API پرستار شامل هیچ‌کدام از این‌ها نیست: کد ملی/موبایل حساب بیمار/مبلغ؛ نشانی و تلفن فقط در `assigned/in_progress`.
- AC-M3-14: اعلان‌ها (لغو، تغییر پرستار) ساخته می‌شوند.
- AC-M3-15: SMS رویدادها: بدون پترن ⇒ فقط لاگ (بدون خطا)، dedupe کار می‌کند.
- پاک‌سازی `finally` برای **همهٔ** جدول‌ها (T-C.1).
خروجی هر سناریو `detail` معنادار داشته باشد (نه فقط `ok`).

**T-C.6 — سخت‌کردن‌های کوچک (سریع).**
- `MNS_Patients::ensure_self`: دو درخواست هم‌زمان نباید دو ردیف `self` بسازند (قفل `GET_LOCK('mns_self_{uid}')` یا درج مشروط)؛ سقف تعداد بیماران هر حساب (مثلاً ۲۰) و سقف طول نام (۱۰۰) در `create/update`.
- `MNS_Orders::create_from_request`: `notes` حداکثر ۵۰۰، `address` حداکثر ۵۰۰ نویسه (`mb_substr`)، `contact_phone` اعتبارسنجی فرمت موبایل ایران (`09\d{9}` پس از نرمال‌سازی) وگرنه `ERR_VALIDATION`.
- `MNS_Nurse_Orders_Api::get_for`: `essentials` را whitelist کن (فقط `serum_type`, `allergy`, `allergy_name`, `rx_file_id`، و کلیدهای تعریف‌شده در `service.config.order_fields`)، نه کل JSON.
- `MNS_Time::slot_known` در هر جای دیگر که شناسهٔ بازه از کاربر می‌آید (ویرایش سفارش توسط بیمار، API برنامهٔ پرستار) اعمال شود.

**T-C.7 — شمارهٔ تماس دیگر (OTP) — فقط اگر ممکن باشد.**
`ERR_OTP_REQUIRED` فعلاً فقط رد می‌کند. اگر در قالب/افزونهٔ فعلی (Dr Plus OTP یا MSQ) یک API/هلپر موجود برای ارسال و تأیید کد وجود دارد، یک جریان حداقلی بساز (`POST /patient/phone-otp/send` و `/verify`؛ کد ۵رقمی، انقضا ۵ دقیقه، سقف ۳ تلاش، rate limit، ذخیره با transient/هش؛ پس از تأیید شمارهٔ تأییدشده برای ۳۰ دقیقه برای همان کاربر معتبر باشد و `create_from_request` آن را بپذیرد). اگر چنین هلپری **نیست** یا پیام‌رسانی واقعی لازم است: `reports/BLOCKED-T-C.7.md` بنویس (آنچه پیدا کردی، گزینه‌های پیشنهادی) و ادامه بده؛ **چیزی بیرون از پلاگین نساز.**

## ۴. تحویل
بعد از هر کارت `reports/<کارت>.md`. در پایان `reports/RUN-C-DONE.md` (کارت‌ها/وضعیت، فایل‌ها، فرضیات، پرسش‌های باز، ریسک‌ها، **شمارهٔ PASS/FAIL آخرین اجرای self-test**) و **توقف**.
