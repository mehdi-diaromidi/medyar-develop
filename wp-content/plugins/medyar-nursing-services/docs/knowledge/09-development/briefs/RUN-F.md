# RUN-F — پرداخت آنلاین فاکتور با زرین‌پال (M5) — این فایل را به‌تنهایی به Cursor بده

> سبک اجرا مثل RUN-A…E: پشت‌سرهم، پروتکل `briefs/RUN-A.md` §۱–۳، درس‌های `RUN-B.md` §۲ و `RUN-C.md` §۲. **commit/push نکن.** این کار با **پول واقعی** سروکار دارد؛ احتیاط‌های §۱ الزامی است.

## ۰. اول بخوان (بدون کد) و در ۱۰ خط خلاصه کن
`05-integrations.md` (بخش زرین‌پال) · `01-backend-decisions.md` · `includes/services/class-mns-invoices.php` (`issue_for_order`, `record_payment` — **اتمی و محافظت‌شده‌اند؛ تغییرشان نده جز آنچه §۳ می‌گوید**) · `public/views/patient/page-invoice-view.php` · `class-mns-patient-invoices.php` · الگوی خواندنی (فقط بخوان، **ویرایش نکن**): `plugins/medyar-toman-wallet/includes/class-zarinpal-gateway.php` و `class-payment-handler.php`.

## ۱. خطوط قرمز (پول)
1. **مبلغ همیشه از ردیف فاکتور در دیتابیس** خوانده می‌شود، هرگز از ورودی کاربر/مرورگر/آدرس بازگشت.
2. **verify فقط سمت سرور**، با مبلغ ذخیره‌شدهٔ همان تلاش (attempt)، نه مبلغی که زرین‌پال یا کاربر در URL می‌فرستد. اگر مبلغ verify با مبلغ تلاش نخواند ⇒ پرداخت را نپذیر و لاگ کن.
3. **ثبت پرداخت دقیقاً یک‌بار** (callback تکراری، دو تب، رفرش، ۱۰۱=قبلاً verify شده ⇒ بدون ثبت دوم). فقط از `MNS_Invoices::record_payment` استفاده کن (آن خودش اتمی است).
4. **هیچ تماس واقعی به زرین‌پال در self-test.** درگاه پشت interface است؛ تست‌ها از یک درگاه جعلی (`MNS_Payment_Gateway_Fake`) استفاده می‌کنند (از طریق فیلتر `mns_payment_gateway`، فقط وقتی `MNS_Selftest` در حال اجراست).
5. **کد مرچنت را هرگز چاپ/لاگ/در پاسخ نگذار.** منبع: `get_option('mtw_zarinpal_merchant_id')` وگرنه `get_option('woocommerce_WC_ZPal_settings')['merchantcode']` (همان منطق MTW). اگر خالی بود ⇒ خطای فارسی «درگاه پرداخت پیکربندی نشده» و پرداخت شروع نشود.
6. **هیچ پرداخت واقعی نزن** و کارت/پرداخت آزمایشی واقعی انجام نده. تست زندهٔ پرداخت را مالک انجام می‌دهد.
7. پلاگین `medyar-toman-wallet` و تم را **ویرایش نکن**. فقط کپی منطقی.
8. هر پاسخ REST/redirect: `Cache-Control: private, no-store`. URL بازگشت هیچ داده حساسی (مبلغ، توکن، شمارهٔ کارت) را در query نگذارد.

## ۲. کارت‌ها

**T-F.1 — Migration نسخهٔ ۳ و درگاه.**
- `MNS_Schema::LATEST` را به `3` ببر؛ فقط افزایشی؛ SQL نسخهٔ ۱ و ۲ را **ویرایش نکن**؛ `sql_v3()` جدول `mns_payment_attempts`: `id`, `invoice_id`, `account_user_id`, `authority varchar(64) UNIQUE`, `amount bigint` (تومان، کپی از فاکتور در لحظهٔ شروع), `status varchar(16)` (`pending|paid|failed|expired|paid_duplicate|amount_mismatch`), `ref_id varchar(64) NULL`, `created_at`, `updated_at`, `verified_at NULL`, `meta longtext NULL` + ایندکس `(invoice_id,status)`.
- interface `MNS_Payment_Gateway` با `initiate(array $attempt): array`, `verify(string $authority, int $amount, string $status): array`, `inquiry(string $authority): array`.
- `MNS_Payment_Gateway_Zarinpal` (کپی منطقی از MTW): v4 `request.json` / `verify.json` / `inquiry.json`، `StartPay/{authority}`، `currency=IRT` و مبلغ به تومان **همان‌طور که MTW می‌فرستد** (مقایسه کن و در گزارش بنویس). **بدون `card_pan` اجباری** و بدون `metadata.card_pan`؛ `metadata` فقط `mobile` و `order_id`. timeout ۳۰ث. کد خطا→پیام فارسی (از MTW کپی کن). خطاهای -۱۴/-۱۸ (دامنهٔ بازگشت با دامنهٔ ثبت‌شده نمی‌خواند) را جداگانه با پیام روشن برگردان: روی develop ممکن است مرچنت فقط برای دامنهٔ اصلی ثبت شده باشد؛ اگر شد، فقط گزارش بده (مرچنت را تغییر نده).
- `MNS_Payment_Gateway_Fake` برای تست.

**T-F.2 — شروع پرداخت (API).**
`POST /mns/v1/invoices/{number}/pay` با `permission_patient`: فاکتور باید مال همان حساب باشد (غیرمالک ⇒ ۴۰۴)، `status='unpaid'` (غیر آن ⇒ `ERR_BAD_STATE`)، سفارش در `invoiced`. rate limit. اگر برای همان فاکتور تلاش `pending` کمتر از ۱۵ دقیقه وجود دارد همان را برگردان (جلوگیری از انبوه تلاش‌ها)؛ وگرنه تلاش جدید با مبلغ فاکتور، درگاه را صدا بزن، `authority` را ذخیره کن، خروجی `{redirect_url}`. callback_url ثابت: `rest_url('mns/v1/payments/return')`. خطای درگاه ⇒ تلاش `failed` و پیام فارسی.
دکمهٔ «پرداخت آنلاین» در `page-invoice-view.php` (`[data-mns-invoice-pay]`) برای کاربر واقعی به این API وصل شود (`?_envelope=1`، nonce) و کاربر را به `redirect_url` ببرد؛ در حالت ادمین/پیش‌نمایش رفتار قبلی. ساختار/CSS بدون تغییر. فاکتور `dispute` ⇒ دکمه قفل (سمت سرور هم رد شود).

**T-F.3 — بازگشت از درگاه (callback) و نهایی‌سازی.**
`GET /mns/v1/payments/return?Authority=…&Status=…` (public؛ بدون نیاز به کوکی/لاگین؛ اعتبار فقط از lookup `authority` در `mns_payment_attempts`):
- تلاش ناموجود/نامعتبر ⇒ redirect به صفحهٔ فاکتورها با پیام خطای عمومی (بدون افشای اطلاعات).
- `Status != OK` ⇒ تلاش `failed`، redirect با پیام «پرداخت لغو شد».
- `Status = OK`: در `MNS_DB::tx` + قفل: تلاش باید `pending` باشد (guarded UPDATE `pending→…` با `1 === rows`)؛ verify با مبلغ تلاش؛ موفق (۱۰۰ یا ۱۰۱) ⇒ `MNS_Invoices::record_payment( $invoice_id, 'zarinpal', $ref_id, 0 )` (actor سیستم) و تلاش `paid` + `ref_id`؛ اگر فاکتور دیگر `unpaid` نیست (پرداخت هم‌زمان از راه دیگر) ⇒ تلاش `paid_duplicate`، `ref_id` را ذخیره و **لاگ هشدار برای بازپرداخت دستی** (در `MNS_Log`)، فاکتور دوباره ثبت نشود. مبلغ نامنطبق ⇒ `amount_mismatch` و لاگ. بعد redirect به `view_url($number, ['mns_result'=>'success'])` یا خطا. هیچ پاسخ بدون no-store.
- callback تکراری بعد از `paid` ⇒ بی‌اثر و redirect موفق.
**cron (هر ۱۵ دقیقه):** تلاش‌های `pending` قدیمی‌تر از ۳۰ دقیقه ⇒ `inquiry`؛ اگر زرین‌پال «پرداخت‌شده/قابل‌verify» گفت همان مسیر نهایی‌سازی را اجرا کن، وگرنه `expired`. با `wp_next_scheduled`، و حذف در deactivate.

**T-F.4 — ادمین و پیامک.**
در صفحهٔ مالی: ستون روش/شماره پیگیری برای پرداخت زرین‌پال؛ فهرست تلاش‌های `paid_duplicate`/`amount_mismatch` برای پیگیری دستی (فقط‌خواندنی؛ هیچ دکمهٔ بازپرداخت خودکار). پیامک «پرداخت موفق» را فقط با زیرساخت `MNS_Sms` موجود وصل کن (بدون پترن ⇒ فقط لاگ).

**T-F.5 — سهم پرستار → کیف پول (فقط اگر امضا مطمئن است).**
`record_payment` هوک `mns_nurse_share_credited` را می‌زند. یک listener در MNS بنویس که اگر کلاس `Wallet_Manager` (MTW) موجود بود با `add_balance( $nurse_id, $share, <operation_type معتبر MTW>, [...] )` اعتبار بدهد؛ **idempotent** (علامت `credited_at` در `invoices.meta` با guarded UPDATE تا دوبار اعتبار ندهد)، خطا ⇒ لاگ و علامت «نیازمند پیگیری»، نه استثنا. `operation_type` معتبر را از `wallet_validate_operation_type` در MTW بخوان و در گزارش بنویس. اگر نمی‌توانی با اطمینان انجام دهی (امضا/نوع عملیات نامشخص): `reports/BLOCKED-T-F.5.md` و ادامه بده — حدس نزن.

**T-F.6 — self-test (الزامی؛ ناظر نیز اجرا می‌کند).**
در یک کلاس جدید `class-mns-selftest-pay.php` (ثبت در autoloader و `register_all`؛ گروه `money` در `MNS_Admin_Selftest` را با الگوی `AC-M6-*` گسترش بده). همه با درگاه جعلی و `finally` پاک‌سازی (invoices, payments, payment_attempts, orders, کاربران):
- AC-M6-1 شروع پرداخت: مبلغ تلاش = مبلغ فاکتور حتی اگر مرورگر مبلغ دیگری بفرستد؛ فاکتور غیرمالک ⇒ ۴۰۴؛ فاکتور پرداخت‌شده ⇒ `ERR_BAD_STATE`؛ تلاش pending تکراری همان را برمی‌گرداند.
- AC-M6-2 callback موفق ⇒ فاکتور `paid`، سفارش `paid`، **یک** ردیف payments با `method=zarinpal` و `tracking=ref_id`.
- AC-M6-3 callback دوباره (و ۱۰۱) ⇒ هنوز یک پرداخت.
- AC-M6-4 `Status=NOK` ⇒ attempt `failed`، فاکتور `unpaid`.
- AC-M6-5 مبلغ verify نامنطبق ⇒ `amount_mismatch`، فاکتور `unpaid`.
- AC-M6-6 فاکتور هم‌زمان دستی پرداخت شود بعد callback ⇒ `paid_duplicate`، دو پرداخت ثبت نشود.
- AC-M6-7 authority ناشناخته ⇒ redirect عمومی، هیچ تغییری.
- AC-M6-8 sweep: تلاش pending قدیمی با inquiry «ناموفق» ⇒ `expired`.

## ۳. تغییر مجاز در کد موجود
فقط اگر لازم شد: افزودن پارامتر اختیاری actor=0 برای `record_payment`، و `MNS_Patient_Invoices` برای نگاشت `method=zarinpal` به برچسب «زرین‌پال». هر تغییر دیگر در `class-mns-invoices.php` ممنوع مگر با توضیح در گزارش.

## ۴. تحویل
بعد از هر کارت `reports/T-F.n.md`. در پایان `reports/RUN-F-DONE.md` (فایل‌ها، schema v3 و نتیجهٔ migration روی develop، فرضیات: مبلغ تومان/ریال، پرسش‌های باز، **نتیجهٔ اجرای self-test**) و **توقف**. زنده‌بودن پرداخت واقعی (تست با پول) را مالک انجام می‌دهد.
