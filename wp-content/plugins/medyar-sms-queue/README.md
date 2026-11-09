# Medyar SMS Queue

صف پایدار پیامک برای وردپرس Medyar — هر پیامک در جدول ثبت می‌شود، در پس‌زمینه ارسال می‌شود، در صورت خطا خودکار دوباره تلاش می‌شود و گزارش کامل آن در پیشخوان قابل مشاهده است.

A durable SMS queue for WordPress: every SMS is persisted as a database row, sent by a background worker, retried automatically on failure, and logged in wp-admin.

---

## ویژگی‌ها

- **یک ردیف = یک پیامک به یک شماره** — تلاش مجدد دقیق بدون ارسال دوباره به بقیهٔ گیرنده‌ها
- **پاسخ فوری فرم‌ها** — ارسال Faraz هرگز درخواست کاربر را معطل نمی‌کند
- **تلاش مجدد خودکار** — حداکثر ۳ بار (۳۰ ثانیه، ۲ دقیقه، سپس «ناموفق»)
- **claim اتمیک** — چند ورکر هم‌زمان هیچ‌وقت یک پیامک را دوبار نمی‌فرستند
- **سه مسیر اجرا** — loopback غیرمسدودکننده ← کرون واقعی سرور ← WP-Cron
- **ضد تکرار** — حالت‌های `skip` / `pending` / `replace` با `dedupe_key`
- **اولویت** — پیامک ادمین‌ها قبل از کاربران ارسال می‌شود
- **transport قابل توسعه** — Faraz داخلی + ثبت transport سفارشی
- **صفحهٔ مدیریت** — آمار وضعیت‌ها، سلامت ورکر/کرون، فیلتر، ارسال دوباره و لغو
- **WP-CLI** — `wp msq process`

## نیازمندی‌ها

- WordPress 5.8+
- PHP 8.0+
- افزونهٔ [Faraz SMS](https://farazsms.com) برای transport پیش‌فرض `faraz`

## نصب

1. پوشه را در `wp-content/plugins/medyar-sms-queue` قرار دهید.
2. از «افزونه‌ها» فعال کنید (جدول `wp_msq_jobs` ساخته می‌شود).
3. منوی **صف پیامک** را باز کنید و دستور کرون سرور را در cPanel → Cron Jobs (هر دقیقه) اضافه کنید:

```text
* * * * * curl -s "https://example.com/wp-admin/admin-ajax.php?action=msq_cron&key=<KEY>" >/dev/null 2>&1
```

کلید در صفحهٔ صف نمایش داده می‌شود و قابل تعویض است.

## استفاده (API)

```php
if ( class_exists( 'MSQ_Client' ) && MSQ_Client::is_ready() ) {
	MSQ_Client::enqueue( array(
		'channel'    => 'wallet',                        // بخش مالک پیامک
		'event'      => 'withdraw_registered',           // نام رویداد در گزارش
		'phone'      => '09121234567',
		'body'       => 'متن آزاد پیامک',
		'pattern'    => 'PatternCode',                   // اختیاری؛ اگر پر باشد فقط API پترن
		'vars'       => array( 'name' => 'علی', 'amount' => '250,000' ),
		'priority'   => MSQ_Client::PRIORITY_ADMIN,      // 10 ادمین، 20 عادی، 30 کم
		'dedupe_key' => 'wallet:withdraw_registered:tx42:09121234567',
		'dedupe'     => 'skip',                          // skip | pending | replace
		'meta'       => array( 'tx_id' => 42 ),          // روی msq_job_sent_{channel} برمی‌گردد
	) );
}
```

| گزینه | پیش‌فرض | توضیح |
|-------|---------|--------|
| `phone` | — | الزامی؛ هر فرمت ایرانی (فارسی/عربی/+98) نرمال می‌شود |
| `body` / `pattern` / `vars` | — | متن آزاد یا پترن Faraz |
| `transport` | `faraz` | شناسهٔ transport ثبت‌شده |
| `data` | `[]` | دادهٔ اضافه برای transportهای سفارشی |
| `priority` | `20` | عدد کمتر زودتر |
| `delay` | `0` | تأخیر (ثانیه) قبل از ارسال |
| `max_attempts` | `3` | سقف تلاش |

### حالت‌های ضد تکرار

| حالت | رفتار |
|------|--------|
| `skip` | اگر ردیفی با همین کلید در صف یا ارسال‌شده باشد، دوباره ثبت نمی‌شود |
| `pending` | فقط تا وقتی ردیف قبلی هنوز در صف است جلوی تکرار را می‌گیرد |
| `replace` | ردیفِ هنوز در صف با محتوای جدید جایگزین می‌شود (آخرین برنده است) |

### هوک‌ها

| هوک | نوع | زمان |
|-----|-----|------|
| `msq_register_transports` | action | ثبت transport: `MSQ_Transports::register( 'id', callable )` |
| `msq_job_enqueued` | action | بعد از ثبت ردیف |
| `msq_job_sent` / `msq_job_sent_{channel}` | action | بعد از ارسال موفق |
| `msq_job_dead` | action | بعد از تمام شدن تلاش‌ها |
| `msq_should_retry` | filter | تصمیم تلاش مجدد |
| `msq_worker_urls` / `msq_worker_request_args` | filter | تنظیم loopback |
| `msq_drplus_intercept` | filter | فعال/غیرفعال کردن رهگیری پیامک رزرو قالب Dr Plus |

### transport سفارشی

```php
add_action( 'msq_register_transports', function () {
	MSQ_Transports::register( 'my_gateway', function ( array $job ): array {
		$ok = my_gateway_send( $job['phone'], $job['body'] );
		return array( 'ok' => $ok, 'detail' => $ok ? 'success' : 'fail', 'retry' => true );
	} );
} );
```

## یکپارچه‌سازی Dr Plus (رزرو نوبت)

`MSQ_Integration_DrPlus` روی فیلتر `drplus/sms/pre_send` کلیدهای `reserve_notification.*` (ثبت، لغو، یادآور) را در صف ثبت می‌کند و ورکر همان `\DrPlus\SMS\SMS::send()` قالب را بعداً اجرا می‌کند. این فیلتر نیاز به این پچ در ابتدای `DrPlus\SMS\SMS::send()` دارد:

```php
$pre = apply_filters( 'drplus/sms/pre_send', null, $to, $type, $variables );
if ( null !== $pre ) {
	return $pre;
}
```

## ساختار

```text
medyar-sms-queue.php                              bootstrap + activation
includes/class-msq-plugin.php                     ثبت هوک‌ها + WP-CLI
includes/class-msq-install.php                    جدول + کلید کرون
includes/class-msq-queue.php                      insert / claim / retry / stale / purge / search
includes/class-msq-client.php                     API عمومی
includes/class-msq-worker.php                     loopback + کرون سرور + WP-Cron + drain
includes/class-msq-transports.php                 رجیستری transport
includes/class-msq-transport-faraz.php            ارسال Faraz
includes/class-msq-phone.php                      نرمال‌سازی موبایل
includes/integrations/class-msq-integration-drplus.php
includes/admin/class-msq-admin.php                صفحهٔ «صف پیامک»
includes/admin/views/page-queue.php
assets/css/admin.css
```

## جدول `wp_msq_jobs`

| ستون | توضیح |
|------|--------|
| `channel`, `event` | مالک و نوع پیامک |
| `transport`, `priority` | مسیر ارسال و اولویت |
| `phone`, `body`, `pattern_code`, `payload` | گیرنده و محتوا (`payload` = JSON متغیرها و داده) |
| `meta` | JSON دامنه برای هوک‌های بعد از ارسال |
| `dedupe_key` | ضد تکرار |
| `status` | `pending` / `reserved` / `sent` / `dead` / `canceled` |
| `attempts`, `max_attempts`, `available_at`, `last_error` | تلاش مجدد |

نگهداری خودکار: ارسال‌شده‌ها بعد از ۶۰ روز و ناموفق/لغوشده‌ها بعد از ۱۲۰ روز حذف می‌شوند.

## غیرفعال‌سازی

با غیرفعال کردن افزونه، پلاگین‌های وابسته (که `MSQ_Client::is_ready()` را چک می‌کنند) خودکار به ارسال قبلی خود برمی‌گردند.

## لایسنس

GPL-2.0-or-later — © M.Mehdi Diaromidi
