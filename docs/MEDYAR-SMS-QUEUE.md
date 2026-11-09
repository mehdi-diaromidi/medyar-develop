# صف پیامک Medyar — `medyar-sms-queue`

> فهرست کل پچ‌ها: [MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md](./MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md) — پچ قالب: [THEME-PATCHES-AFTER-UPDATE.md](./THEME-PATCHES-AFTER-UPDATE.md) پچ ۹

## چرا؟

قبلاً هر بخش پیامک را یا داخل همان درخواست کاربر (مسدودکننده، تا ۱۵ ثانیه برای هر پیامک) یا در یک صفِ option ساده می‌فرستاد. پیامکِ ناموفق دوباره تلاش نمی‌شد، کرون پشتیبان به بازدید سایت وابسته بود و گزارشی از رسیدن هر پیامک وجود نداشت.

این پلاگین یک **صف جدولی واحد** برای همهٔ پیامک‌های اصلی Medyar است:

- **هر ردیف = یک پیامک به یک شماره** → تلاش مجدد دقیق، بدون ارسال دوباره به بقیهٔ گیرنده‌ها
- **فرم‌ها فوراً موفق می‌شوند**؛ ارسال در درخواست جداگانه انجام می‌شود
- **تلاش مجدد خودکار**: حداکثر ۳ بار با فاصله ۳۰ ثانیه، ۲ دقیقه و سپس «ناموفق»
- **سه راه اجرا**: درخواست پس‌زمینه (loopback) ← کرون واقعی سرور ← WP-Cron
- **گزارش در پیشخوان**: منوی «صف پیامک»

## مسیر و ساختار

```text
wp-content/plugins/medyar-sms-queue/
├── medyar-sms-queue.php                         bootstrap + activation
├── includes/class-msq-plugin.php                ثبت هوک‌ها + WP-CLI (wp msq process)
├── includes/class-msq-install.php               جدول wp_msq_jobs + کلید کرون
├── includes/class-msq-queue.php                 insert / claim اتمیک / retry / stale / purge / search
├── includes/class-msq-client.php                API عمومی (enqueue, replay, send_now)
├── includes/class-msq-worker.php                loopback + endpoint کرون + WP-Cron + drain
├── includes/class-msq-transports.php            رجیستری transport
├── includes/class-msq-transport-faraz.php       ارسال Faraz (پترن با کلید دست‌نخورده / متن آزاد)
├── includes/class-msq-phone.php                 نرمال‌سازی 09XXXXXXXXX
├── includes/integrations/class-msq-integration-drplus.php   پیامک رزرو قالب (فیلتر pre_send + replay)
├── includes/admin/class-msq-admin.php           صفحهٔ «صف پیامک»
├── includes/admin/views/page-queue.php
└── assets/css/admin.css
```

مخزن کد: [github.com/mehdi-diaromidi/medyar-sms-queue](https://github.com/mehdi-diaromidi/medyar-sms-queue) (شاخهٔ `main`؛ پوشهٔ پلاگین در workspace خودش git repo است).

این پلاگین **اختصاصی Medyar** است و با آپدیت هیچ پلاگین/قالب دیگری پاک نمی‌شود. بعد از آپلود باید یک‌بار از «افزونه‌ها» **فعال** شود (جدول با فعال‌سازی ساخته می‌شود؛ بعد از آن `maybe_upgrade` روی `plugins_loaded` نسخهٔ جدول را چک می‌کند).

## جدول `wp_msq_jobs`

| ستون | توضیح |
|------|--------|
| `channel` | `arp` / `wallet` / `booking` |
| `event` | مثلاً `admin_new_referral`، `withdraw_registered`، `reserve.patient.book` |
| `transport` | `faraz` (پیش‌فرض)، `drplus` (replay قالب)، `mmad_pattern` (ادمین رزرو) |
| `priority` | عدد کمتر زودتر — ادمین `10`، عادی `20` |
| `phone`, `body`, `pattern_code` | گیرنده و محتوا |
| `payload` | JSON: `vars` (متغیرهای پترن) و `data` (دادهٔ transport) |
| `meta` | JSON دامنه؛ روی هوک `msq_job_sent_{channel}` برگردانده می‌شود |
| `dedupe_key` | جلوگیری از تکرار |
| `status` | `pending` / `reserved` / `sent` / `dead` / `canceled` |
| `attempts`, `max_attempts`, `available_at`, `last_error` | تلاش مجدد |

نگهداری: ارسال‌شده‌ها بعد از ۶۰ روز و ناموفق/لغوشده‌ها بعد از ۱۲۰ روز حذف می‌شوند (`msq_purge` روزانه).

## API برای پلاگین‌ها

```php
if ( class_exists( 'MSQ_Client' ) && MSQ_Client::is_ready() ) {
	MSQ_Client::enqueue( array(
		'channel'    => 'wallet',
		'event'      => 'withdraw_registered',
		'phone'      => $mobile,
		'body'       => $text,          // متن آزاد (برای پترن هم در گزارش ذخیره می‌شود)
		'pattern'    => $pattern_code,  // اگر پر باشد فقط API پترن
		'vars'       => $vars,
		'priority'   => MSQ_Client::PRIORITY_ADMIN,
		'dedupe_key' => 'wallet:withdraw_registered:tx' . $tx_id . ':' . $mobile,
		'dedupe'     => 'skip',         // skip | pending | replace
		'meta'       => array( 'ref' => $tx_id ),
	) );
}
```

| حالت dedupe | رفتار |
|-------------|--------|
| `skip` | اگر ردیفی با همین کلید در صف یا ارسال‌شده باشد، دوباره ثبت نمی‌شود |
| `pending` | فقط تا وقتی ردیف قبلی هنوز در صف است جلوی تکرار را می‌گیرد (چرخه‌های تکرارپذیر مثل درخواست مجدد همکاری) |
| `replace` | ردیفِ هنوز در صف با محتوای جدید جایگزین می‌شود (آخرین وضعیت برنده است) |

هوک‌ها:

| هوک | زمان |
|-----|------|
| `msq_register_transports` | ثبت transport سفارشی: `MSQ_Transports::register( 'id', callable )` |
| `msq_job_sent` / `msq_job_sent_{channel}` | بعد از ارسال موفق |
| `msq_job_dead` | بعد از تمام شدن تلاش‌ها |
| `msq_should_retry` | فیلتر تصمیم تلاش مجدد |
| `drplus/sms/pre_send` | (پچ قالب) رهگیری پیامک‌های رزرو |

## نقشهٔ پیامک‌ها → صف

### `affiliate-referral` (channel: `arp`)

مکانیزم: `Arp_Faraz_Sms_Gateway::enqueue()` در صورت فعال بودن صف، کار را همان لحظه **رندر** می‌کند و هر `deliver()` به‌جای Faraz یک ردیف صف می‌سازد (`queue_capture`). متن، پترن و متغیرها دقیقاً همان منطق قبلی ARP است.

| رویداد ARP | event | اولویت | dedupe |
|------------|-------|--------|--------|
| ثبت‌نام همکار → ادمین | `admin_affiliate_signup` | ادمین | pending |
| ثبت‌نام همکار → متقاضی | `applicant_pending` | عادی | pending |
| تأیید / رد | `applicant_decision` | عادی | pending |
| معرفی بیمار → ادمین | `admin_new_referral` | ادمین | skip |
| معرفی بیمار → بیمار | `patient_referral` | عادی | — |
| تغییر وضعیت → همکار | `affiliate_status` | عادی | replace |
| تثبیت پورسانت → همکار | `commission` | عادی | skip |

نشانگرهای یک‌بارهٔ ثبت‌نام (`arp_sms_applicant_pending_sent` / `arp_sms_admin_signup_sent`) مثل قبل قبل از ارسال claim می‌شوند. اگر صف غیرفعال باشد، مسیر قدیمی (option outbox + loopback) خودکار استفاده می‌شود.

### `medyar-toman-wallet` (channel: `wallet`)

مکانیزم: متدهای `sms_*` داخل `with_msq_context()` اجرا می‌شوند؛ `Wallet_SMS::send()` در این حالت `MSQ_Client::enqueue()` می‌زند. لاگ توضیحات تراکنش و اکشن `medyar_wallet_sms_sent` روی `msq_job_sent_wallet` (`Wallet_SMS::on_queue_sent`) اجرا می‌شوند. سایر پیامک‌های کیف پول (معامله/مدارک) بدون context هستند و مثل قبل همگام می‌روند.

| رویداد | event | ترتیب / اولویت |
|--------|-------|----------------|
| ثبت درخواست برداشت → ادمین‌ها | `withdraw_admin` | **اول**، اولویت ادمین |
| ثبت درخواست برداشت → کاربر | `withdraw_registered` | بعد از ادمین |
| تأیید برداشت | `withdraw_completed` | عادی |
| لغو/رد برداشت | `withdraw_canceled` | عادی |
| تکمیل واریز/شارژ | `deposit_completed` | عادی |
| لغو واریز | `deposit_canceled` | عادی |

ثبت برداشت داخل تراکنش DB انجام می‌شود؛ ردیف صف هم داخل همان تراکنش درج می‌شود و ورکر در `shutdown` (بعد از COMMIT) شلیک می‌شود.

### رزرو نوبت (channel: `booking`)

| رویداد | مالک trigger | transport |
|--------|---------------|-----------|
| بعد از رزرو → بیمار و متخصص | mu-plugin `medyar-reserve-book-sms.php` → `Booking::send_reserve_event_sms()` → `SMS::send()` | `drplus` |
| لغو نوبت → بیمار و متخصص | قالب `Booking::cancel_booking()` → `SMS::send()` | `drplus` |
| یادآور (کرون) | قالب `CheckBookingMessages.php` → `SMS::send()` | `drplus` |
| ادمین رزرو (ثبت / لغو) | `medyar-myaccount-dashboard` → `MMAD_Admin_Reserve_SMS::send_event()` | `mmad_pattern` (اولویت ادمین) |

`drplus`: فیلتر `drplus/sms/pre_send` (پچ قالب) فقط کلیدهای `reserve_notification.*` را می‌گیرد و `true` برمی‌گرداند. ورکر همان `\DrPlus\SMS\SMS::send()` را داخل `MSQ_Client::replay()` اجرا می‌کند و فلگ‌های bypass mu-plugin (`medyar_reserve_sending_book|cancel|reminder`) و `medyar_block_sms_http = false` را موقتاً ست می‌کند. پس درگاه، پترن و نگاشت متغیرها همچنان متعلق به قالب است.

## کرون واقعی سرور (الزامی برای قطعیت)

صفحهٔ «صف پیامک» دستور کامل را با کلید نشان می‌دهد. در cPanel → Cron Jobs، **هر دقیقه**:

```text
* * * * * curl -s "https://medyar.org/wp-admin/admin-ajax.php?action=msq_cron&key=<KEY>" >/dev/null 2>&1
```

- کلید در option `msq_cron_key` است و از همان صفحه قابل تعویض است.
- اگر کرون سرور ۵ دقیقه اجرا نشده باشد، کارت «کرون واقعی سرور» زرد و وضعیت «تنظیم نشده» می‌شود.
- WP-Cron هم هر دقیقه (`msq_cron_tick`) اجرا می‌شود ولی به بازدید وابسته است.
- WP-CLI: `wp msq process`

## عیب‌یابی

| علامت | بررسی |
|-------|--------|
| ردیف‌ها `pending` می‌مانند | «آخرین اجرای ورکر پس‌زمینه» و کرون سرور را در صفحهٔ صف ببینید؛ «اجرای صف همین حالا» |
| `dead` با `api_error:` | پترن/متغیر در پنل فراز اشتباه است — بعد از اصلاح «ارسال دوباره» |
| `dead` با `allowlist` | حالت تست محدودسازی پیامک فعال است (`medyar-sms-allowlist`) — تلاش مجدد نمی‌شود |
| `stale_lock` | ورکر وسط ارسال قطع شده؛ تا سقف تلاش‌ها دوباره در صف می‌رود |
| پیامک رزرو هنوز همگام می‌رود | پچ ۹ قالب (`drplus/sms/pre_send`) بعد از آپدیت تم پاک شده است |

## غیرفعال کردن / بازگشت

غیرفعال کردن پلاگین کافی است: ARP به outbox قدیمی برمی‌گردد، کیف پول و ادمین رزرو همگام ارسال می‌کنند و فیلتر قالب بی‌اثر می‌شود (`null` برمی‌گردد).
