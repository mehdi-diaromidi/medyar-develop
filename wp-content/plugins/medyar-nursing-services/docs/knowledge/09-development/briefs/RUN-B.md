# RUN-B — اجرای خودکار پیوسته: M2 (برنامه و مناطق) + M3 (سفارش، A-13، تخصیص) — این فایل را به‌تنهایی به Cursor بده

> **همان سبک RUN-A:** بدون توقف برای تأیید، پشت‌سرهم، خودت آپلود می‌کنی، خودت بررسی می‌کنی، گزارش می‌نویسی. ناظر (Claude) ناهمزمان بازبینی و اصلاح می‌کند. **commit/push نکن.**

## ۰. اول بخوان (بدون کد) و در ۱۰ خط خلاصه کن
`PROJECT-LOGIC.md` · `09-development/` فایل‌های `README, 01..06` · `08-task-cards.md` (T-2.1 تا T-3.9 **و بخش «یادداشت‌های ناظر»**) · `.cursor/rules/mns-backend.mdc` · `briefs/RUN-A.md` (پروتکل §۱–۳ **عیناً معتبر است**) · گزارش‌های `reports/RUN-A-DONE.md` و `T-1.*.md` · کد موجود: `includes/domain/*`, `includes/services/*`, `includes/rest/*`, `includes/backend/*`.

## ۱. پروتکل و شرط‌های توقف و قواعد سخت
دقیقاً مثل `briefs/RUN-A.md` §۱ (بکاپ در `.deploy-backup/<کارت>/`، ماژول، بازبینی خودت، `deploy.ps1`، بررسی زنده، بازگردانی، گزارش)، §۲ و §۳. در بررسی زندهٔ مرحلهٔ ۶ این URLها را هم اضافه کن:
`/nursing-nurse-panel/?mns_panel=nurse&mns_page=availability`, `/nursing-patient-panel/?mns_panel=patient&mns_page=orders`, `/nursing-nurse-panel/?mns_panel=nurse&mns_page=orders`.
**بعد از هر کارت** صفحهٔ `tools.php?page=mns-selftest` را باز کن/بخوان **اگر بدون ورود نمی‌شود** فقط به گزارش سناریوهای نوشته‌شده اکتفا کن؛ ناظر اجرا می‌کند.

## ۲. درس‌های بازبینی RUN-A (این‌ها را از ابتدا رعایت کن؛ ناظر در RUN-A همین‌ها را اصلاح کرد)
1. **هر `$wpdb->insert/update/delete/query` را چک کن** (`false === $r` ⇒ خطا + rollback/پاک‌سازی). هرگز نتیجه را نادیده نگیر.
2. **تغییر وضعیت فقط با `UPDATE … WHERE status IN (مجاز)` و `1 !== rows` ⇒ `MNS_Error::bad_state`.** هیچ نقشی/دسترسی‌ای پیش از موفقیت این به‌روزرسانی داده نشود.
3. **یکتایی/تکراری را پیش از درج بسنج** و پیام فارسی روشن بده (نه شکست بی‌صدا از UNIQUE).
4. **هر handler `admin_post_*`** باید `MNS_Error` و `\Throwable` را بگیرد و با `wp_die` پیام تمیز بدهد (نه صفحهٔ سفید).
5. فایل‌های آپلودشده‌ای که تراکنش شکست خورد ⇒ از دیسک پاک شوند (`discard_files`).
6. رشته‌های فارسی: `mb_strlen`/`mb_substr` (نه `strlen`).
7. مقدار ورودی‌ای که قبلاً اعتبارسنجی می‌شد (کد ملی، تاریخ، بازه، شناسه) در **هر مسیر دیگری** هم (PATCH، ادمین، REST) همان اعتبارسنجی را بگذراند.
8. کلاس جدید ⇒ در نقشهٔ `MNS_Autoloader` ثبت؛ ماژول جدید ⇒ در `MNS_Backend::modules()`؛ route جدید ⇒ در کلاس REST ماژولی. **کلاس موجودِ هم‌نام را تکرار نکن**: `MNS_Nurse_Schedule`, `MNS_Eligibility`, `MNS_Availability`, `MNS_Order_Machine` در `includes/domain/` هستند؛ `MNS_Services_Seed` (دو خدمت) در `includes/services/` هست؛ `MNS_Files`, `MNS_Sms`, `MNS_Nurse_Access` هستند — **گسترش بده، تکرار نکن.**
9. **self-test واقعی بنویس:** برای هر کارت سناریوهایی که واقعاً مسیر شکست را هم می‌سنجند (نه فقط «کلاس وجود دارد»): رد ورودی بد، وضعیت نادرست، هم‌زمانی منطقی، مالکیت (کاربر A به سفارش کاربر B دسترسی ندارد). داده‌ٔ تست با پاک‌سازی تضمینی (`finally`).

## ۳. کارت‌ها به ترتیب
جزئیات هر کارت در `08-task-cards.md` است؛ این‌جا فقط تکمیل‌ها و **یادداشت‌های ناظر** (الزامی):

**T-2.1** برنامه و مناطق. `MNS_Nurse_Schedule::effective()` موجود است؛ `save_days()` (R-SCH-2 همه‌یا‌هیچ + `lock_row`)، `save_regions()` (R-REG-*)، `MNS_Locations`، `MNS_Nurse_Status::compute()` را اضافه کن. بازهٔ `S-SLOTS` و زمان‌ها از `MNS_Settings`/`MNS_Time`.
**T-2.2** REST API-NRS-4..7 + اتصال صفحهٔ «برنامه و دسترسی» (فقط داده/ذخیره؛ ساختار و CSS بدون تغییر). `class-mns-rest-nurse.php` موجود را **گسترش بده** (route جدید اضافه شود؛ `nurse/me` و عکس را نشکن). `ERR_SLOT_HAS_ORDER` در UI. حالت‌های `?mns_case` فقط dev.
**T-3.1** کاتالوگ: `MNS_Services` (`get/list_active`) روی `MNS_Services_Seed` موجود؛ ادمین ویرایش حداقلی.
**T-3.2** بیماران (R-PAT-1…): CRUD، soft delete، مالکیت (هر REST با بررسی `owner_user_id`؛ پاسخ ۴۰۴ برای غیرمالک، نه ۴۰۳).
**T-3.3** API-A13-1 با **اعتبارسنجی زمان در لایهٔ API**: تاریخ خارج از `[امروز, امروز+S-ORD-HORIZON]`، بازهٔ `closed`، بازه‌ای که شروعش گذشته (`MNS_Time::slot_start_ts`) ⇒ `ERR_VALIDATION`. نرخ‌محدودیت. بدون افشای تعداد/نام پرستار.
**T-3.4** ثبت سفارش: **قفل هم‌زمانی** `GET_LOCK('mns_slot_{date}_{slot}',5)` (یا قفل ردیف نامزدها به ترتیب `user_id`) *پیش از* A-13 داخل تراکنش، آزادسازی در `finally`؛ ایدمپوتنسی؛ کد ۵رقمی **یکتا** (fallback ۶رقمی را هم با بررسی یکتایی بساز)؛ `ERR_OTP_REQUIRED` برای شمارهٔ دیگر؛ آپلود نسخه با `MNS_Files` (+ پاک‌سازی در شکست).
**T-3.5** تخصیص: پیش از `transition('reassign')` وضعیت `approved` و صلاحیت پرستار جدید را بسنج (R-ASG-4: `suspended` ⇒ رد؛ ناواجد ⇒ هشدار + تأیید صریح ادمین). فهرست `IN(...)` با placeholder. ثبت سفارش تلفنی: ساخت حساب برای موبایل جدید.
**T-3.6 / T-3.7** صفحات بیمار/پرستار: whitelist فیلدها (R-PRV-1/2)؛ **هیچ فیلد حساس (کد ملی، مدارک، موبایل بیمار برای پرستار غیرمرتبط) در پاسخ نباشد**؛ اعلان‌ها + cron پاک‌سازی (با `wp_next_scheduled`، و حذف در deactivate اگر ساختار فعلی دارد).
**T-3.8** پیامک #4,#5,#11,#12 از هوک `mns_order_transitioned` (بعد از commit)، dedupe.
**T-3.9** جایگزینی fixtureها: صفحات برش اول از fixture نمی‌خوانند؛ حالت خالی همه‌جا؛ برای ادمین/dev پیش‌نمایش fixture مثل RUN-A بماند.

## ۴. تحویل
بعد از **هر** کارت گزارش `reports/<کارت>.md`. در پایان `reports/RUN-B-DONE.md` (کارت‌ها/وضعیت، فایل‌ها، migrationها، فرضیات، پرسش‌های باز، ریسک‌ها) و **توقف**.
