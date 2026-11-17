# دستورالعمل بازاعمال پچ‌های قالب Dr Plus بعد از آپدیت

این فایل فقط تغییراتی را پوشش می‌دهد که **خارج از پلاگین‌های اختصاصی Medyar** (`affiliate-referral`، `medyar-toman-wallet` و …) اعمال شده‌اند و با **آپدیت قالب `dr-plus`** پاک می‌شوند.

پلاگین‌ها را جداگانه نگه دارید/نسخه‌برداری کنید؛ اینجا نیستند.

| # | فایل قالب | هدف |
|---|-----------|-----|
| 1 | `inc/Utils/utils-user.php` | فعال‌سازی فیلتر منوی حساب برای آیتم «همکار» و کیف پول |
| 2 | `templates/header/template-header-action-account_btn.php` | جلوگیری از برعکس شدن شماره/نام ماسک‌شده در RTL |
| 3 | `inc/Utils/utils-booking.php` | پیامک **ثبت** / **کنسل** رزرو + متدهای کمکی |
| 4 | `functions/booking.php` | هوک پیامک ثبت از تم حذف شده (مالک = mu-plugin) |
| 5 | `inc/Cronjobs/CheckBookingMessages.php` | یادآور رزرو: heal کرون + bypass بلاکر + تشخیص خطای درگاه |
| 6 | `inc/AJAX/Auth.php` | فراموشی رمز: پسورد **عددی** ۸ رقمی (الگوی فراز فیلد numeric می‌خواهد) |
| 7 | `inc/sms/Gateways/FarazsmsNew.php` | خطای HTTP/API الگو را به `wp_error` تبدیل کند |
| 8 | `inc/AJAX/Search.php` | جلوگیری از fatal ۵۰۰ در AJAX جستجو وقتی `exclude_post_types` آرایه نیست |
| 9 | `inc/sms/Gateways/SMS.php` | فیلتر `drplus/sms/pre_send` تا پیامک‌های رزرو در صف `medyar-sms-queue` ثبت شوند |
| 10 | `templates/chats/template-chats-list.php` | ترتیب/زمان فهرست گفت‌وگوها |
| 11 | `woocommerce/myaccount/navigation.php` | جلوگیری از fatal وقتی Sheyda Wallet لود نیست؛ موجودی از `Wallet_Manager` |
| — | mu-plugin `medyar-reserve-book-sms.php` | مالک ثبت + بلاک تکراری (با آپدیت تم معمولاً نمی‌پرد؛ در MEDYAR-REAPPLY) |

مسیر پایه قالب:

```text
wp-content/themes/dr-plus/
```

---

## پچ ۱ — فیلتر `drplus/account_menu_items`

### فایل

```text
wp-content/themes/dr-plus/inc/Utils/utils-user.php
```

### کلاس / متد

- Namespace: `DrPlus\Utils`
- Class: `User`
- Method: `get_account_menu_items()`

### چرا لازم است؟

پلاگین‌ها (مثلاً `affiliate-referral` و `medyar-toman-wallet`) به فیلتر زیر هوک می‌زنند:

```php
add_filter( 'drplus/account_menu_items', …, 10, 2 );
```

بدون `apply_filters` در تم، آیتم‌هایی مثل:

- مهمان: «ورود / عضویت همکار»
- لاگین: «ورود به پنل همکار» / «درخواست پنل همکار»
- لاگین: «کیف پول» (از پلاگین کیف پول)

در پاپ‌اور هدر ظاهر نمی‌شوند.

### کد اصلی تم (قبل از پچ)

در انتهای `get_account_menu_items()` معمولاً فقط این است:

```php
		}
		return $account_btn_items;
	}
```

یعنی بلافاصله بعد از بستن بلاک `else` مهمان (آیتم‌های login/signup) و قبل از متد بعدی `get_avatar_id`.

### کد جایگزین (بعد از پچ)

همان `return` را با این عوض کنید:

```php
		}
		/**
		 * Filter header account popover items (guest + logged-in).
		 *
		 * @param array $account_btn_items Menu items keyed by slug (guest) or list (logged-in).
		 * @param bool  $logged_in         Whether the visitor is logged in.
		 */
		return apply_filters( 'drplus/account_menu_items', $account_btn_items, is_user_logged_in() );
	}
```

### جست‌وجو برای پیدا کردن محل دقیق

در فایل بگردید:

```text
return $account_btn_items;
```

داخل متد `get_account_menu_items` (اولین/تنها `return` همان متد، نزدیک خط ~122).

### تأیید صحت

1. در سورس باید دقیقاً این رشته باشد:

   ```text
   apply_filters( 'drplus/account_menu_items'
   ```

2. به‌عنوان مهمان در هدر، پاپ‌اور حساب باید ۳ آیتم داشته باشد (اگر پلاگین همکار فعال است): ورود، عضویت، ورود / عضویت همکار.

3. به‌عنوان کاربر لاگین‌شده، باید آیتم پنل همکار / کیف پول (بر اساس پلاگین‌ها) دیده شود.

---

## پچ ۲ — `dir="ltr"` برای نام نمایشی عددی در دکمه حساب هدر

### فایل

```text
wp-content/themes/dr-plus/templates/header/template-header-action-account_btn.php
```

### چرا لازم است؟

نام‌های ماسک‌شده مثل `0933***5689` داخل RTL به‌همراه کلاس‌های `line-clamp` / `-webkit-box` بصری برعکس می‌شوند (`5689***0933`). برای رشته‌های فقط‌عددی (بدون حرف) باید `dir="ltr"` روی span گذاشته شود.

### ۲‑الف) بعد از ساخت `$account_btn_attachment` و قبل از `<div class="… header-account-wrap">`

#### محل

بلافاصله بعد از بلاک:

```php
} else if( $args['account-btn-attachment-type'] == 'icon' ) {
	…
}
```

و **قبل از**:

```php
?>
<div class="<?php echo Utils::prepare_html_classes( $wrap_classes ) ?>">
```

#### کد اضافه کنید

```php

// Masked/OTP mobiles ("0933***5689") reverse under RTL + -webkit-line-clamp; force LTR for digit-only names.
$account_btn_name_is_ltr = $account_btn_text !== ''
	&& preg_match( '/\d/u', $account_btn_text )
	&& ! preg_match( '/\p{L}/u', $account_btn_text );
$account_btn_name_attrs = $account_btn_name_is_ltr ? ' dir="ltr"' : '';
```

### ۲‑ب) هر چهار `<span class="header-account-display_name …">`

#### کد اصلی تم (نمونه یک مورد)

```php
<span class="header-account-display_name line-clamp line-clamp-1"><?php echo esc_html( $account_btn_text ) ?></span>
```

چهار بار تکرار می‌شود (logged-in start/end و guest start/end).

#### کد جایگزین برای هر چهار مورد

```php
<span class="header-account-display_name line-clamp line-clamp-1"<?php echo $account_btn_name_attrs; ?>><?php echo esc_html( $account_btn_text ) ?></span>
```

### ۲‑ج) بخش نام/ایمیل داخل منوی پاپ‌اور (اگر تم آن را رندر می‌کند)

#### کد اصلی تم (تقریبی)

```php
			<?php if( $is_user_logged_in && ($args['show_user_name_in_menu'] || $args['show_user_email_in_menu'] ) ) { ?>
				<?php $user = wp_get_current_user() ?>
				<li class="account-item account-user-info">
					<span class="account-item-label">
						<?php if( $args['show_user_name_in_menu'] ) { ?>
							<span class="account-user_name"><?php echo $user->display_name ?></span>
						<?php } ?>
						<?php if( $args['show_user_email_in_menu'] ) { ?>
							<span class="account-user_email"><?php echo $user->user_email ?></span>
						<?php } ?>
					</span>
				</li>
			<?php } ?>
```

#### کد جایگزین

```php
			<?php if( $is_user_logged_in && ($args['show_user_name_in_menu'] || $args['show_user_email_in_menu'] ) ) { ?>
				<?php $user = wp_get_current_user() ?>
				<?php
				$menu_user_name = (string) $user->display_name;
				$menu_user_name_ltr = $menu_user_name !== ''
					&& preg_match( '/\d/u', $menu_user_name )
					&& ! preg_match( '/\p{L}/u', $menu_user_name );
				?>
				<li class="account-item account-user-info">
					<span class="account-item-label">
						<?php if( $args['show_user_name_in_menu'] ) { ?>
							<span class="account-user_name"<?php echo $menu_user_name_ltr ? ' dir="ltr"' : ''; ?>><?php echo esc_html( $menu_user_name ) ?></span>
						<?php } ?>
						<?php if( $args['show_user_email_in_menu'] ) { ?>
							<span class="account-user_email" dir="ltr"><?php echo esc_html( $user->user_email ) ?></span>
						<?php } ?>
					</span>
				</li>
			<?php } ?>
```

نکته: در نسخه پچ‌شده، `display_name` و `user_email` با `esc_html` چاپ می‌شوند (امن‌تر از خروجی خام تم).

### تأیید صحت

1. در فایل باید باشد:

   ```text
   $account_btn_name_is_ltr
   $account_btn_name_attrs
   ```

2. کاربر با `display_name` شبیه `0933***5689` را لاگین کنید؛ در هدر متن باید از چپ با `0933` شروع شود (نه `5689`).

3. در HTML باید چیزی شبیه این دیده شود:

   ```html
   <span class="header-account-display_name line-clamp line-clamp-1" dir="ltr">0933***5689</span>
   ```

---

## چک‌لیست بعد از آپدیت قالب

1. [ ] بکاپ از فایل‌های پچ (یا از کل `dr-plus`) قبل از آپدیت
2. [ ] آپدیت قالب
3. [ ] اعمال **پچ ۱** روی `utils-user.php`
4. [ ] اعمال **پچ ۲** روی `template-header-action-account_btn.php`
5. [ ] اعمال **پچ ۳–۷** (پیامک رزرو/یادآور/فراموشی رمز — پایین‌تر در همین سند)
6. [ ] پاک کردن کش (اگر Litespeed / کش سرور فعال است)
7. [ ] تست مهمان: آیتم «ورود / عضویت همکار»
8. [ ] تست لاگین: آیتم پنل همکار + نمایش درست شماره ماسک‌شده
9. [ ] تست ثبت و لغو نوبت + فراموشی رمز با موبایل

---

## وابسته به پلاگین‌ها (نیازی به پچ تم نیست، فقط برای یادآوری)

این رفتارها داخل پلاگین‌هاست و با آپدیت قالب از بین نمی‌روند؛ با آپدیت/جایگزینی خود پلاگین ممکن است از بین بروند:

| پلاگین | موضوع |
|--------|--------|
| `affiliate-referral` | هوک روی `drplus/account_menu_items` برای آیتم همکار (نیازمند پچ ۱ تم) |
| `medyar-toman-wallet` | هوک کیف پول روی همان فیلتر؛ برگشت درآمد نوبت؛ برگشت مبلغ درگاه به کیف پول هنگام لغو سفارش |
| `medyar-myaccount-dashboard` | پیشخوان حساب + منوی «پیامک ادمین رزرو» |
| `lab-booking-system` | فرم آزمایش؛ پچ JS؛ **تنظیمات ← پیامک ادمین درخواست‌ها** (lab/nurse/phar، متن آزاد + `billing_phone`) — جزئیات در MEDYAR-REAPPLY پچ ۱۰ |
| `pharmacy-pro-system` | پچ JS (جلوگیری از ثبت اشتباه آزمایش در داروخانه) — MEDYAR-REAPPLY پچ ۱۰ |
| mu-plugin `medyar-reserve-book-sms.php` | مالک SMS ثبت رزرو |
| mu-plugin `medyar-skip-pwsms-booking-orders.php` | اسکیپ SMS خودکار سفارش رزرو |

اگر فقط قالب را آپدیت کنید، **پچ‌های ۱ تا ۷** این سند را دوباره بزنید.

---

## پچ ۳ — پیامک ثبت / لغو رزرو (`utils-booking.php`)

### فایل

```text
wp-content/themes/dr-plus/inc/Utils/utils-booking.php
```

### بعد از آپدیت قالب

**کل متدهای زیر را از workspace کپی کنید** (یا کل فایل اگر مطمئنید بقیهٔ فایل دست‌نخورده است):

| متد | نقش |
|-----|-----|
| `cancel_booking()` | انتهای متد: `do_action( 'drplus/booking/appointment_canceled' )` + `send_reserve_event_sms( …, 'book_canceled' )` |
| `prepare_reserve_sms_context()` | ساخت متغیرها + resolve موبایل متخصص |
| `gateway_send_failed()` | تشخیص `Gateway::$wp_error` بعد از ارسال Faraz |
| `send_reserve_event_sms()` | ارسال book / book_canceled؛ در کنسل `medyar_reserve_sending_cancel` + خاموش کردن موقت `medyar_block_sms_http` |
| `maybe_send_book_sms_for_order()` | قفل متا `_medyar_reserve_book_sms_sent` + ارسال book |

### علائم وجود پچ

```text
send_reserve_event_sms
maybe_send_book_sms_for_order
medyar_reserve_sending_cancel
prepare_reserve_sms_context
gateway_send_failed
```

### نکات

- رویداد کنسل باید دقیقاً `book_canceled` باشد (نه fallback به `book`).
- لاگ‌گیری / mu-plugin دیباگ در این پچ نیست.

---

## پچ ۴ — `functions/booking.php` (بدون هوک پیامک ثبت)

### فایل

```text
wp-content/themes/dr-plus/functions/booking.php
```

بعد از آپدیت، اگر تم دوباره هوک `woocommerce_order_status_changed` برای SMS ثبت اضافه کرد، **حذفش کنید**. مالک ارسال ثبت = mu-plugin:

```text
wp-content/mu-plugins/medyar-reserve-book-sms.php
```

فایل تم فعلی فقط breadcrumb / session / wallet ledger دارد؛ هوک SMS ندارد.

---

## پچ ۵ — یادآور پیامک رزرو (`CheckBookingMessages`)

### فایل

```text
wp-content/themes/dr-plus/inc/Cronjobs/CheckBookingMessages.php
```

### چرا لازم است؟

- کرون `drplus_check_send_booking_sms_hook` اگر timestamp گذشته داشته باشد WP-Cron آن را زنده نگه نمی‌دارد.
- بلاکر HTTP سفارش رزرو (`medyar_block_sms_http`) و بلاکر تکراری شماره می‌تواند یادآور را ببندد.
- موفقیت ارسال فقط با `!is_wp_error` کافی نیست؛ باید `Gateway::$wp_error` هم چک شود.

### بعد از آپدیت قالب

کل فایل را از workspace دوباره کپی کنید. علائم وجود پچ:

```text
drplus_medyar_ensure_reminder_cron
medyar_reserve_sending_reminder
drplus_medyar_reminder_send_failed
```

---

## پچ ۶ — فراموشی رمز (`Auth.php`) — پسورد عددی + تشخیص خطای درگاه

### فایل

```text
wp-content/themes/dr-plus/inc/AJAX/Auth.php
```

### متد

`DrPlus\AJAX\Auth::lost_password()`

### ۶‑الف) پسورد عددی ۸ رقمی

به‌جای:

```php
$new_password = wp_generate_password( 8, false );
```

بگذارید:

```php
// Faraz pattern field "password" is numeric-only (API: «فیلد password باید عددی باشد»).
$new_password = (string) wp_rand( 10000000, 99999999 );
```

### ۶‑ب) تشخیص `Gateway::$wp_error` بعد از `SMS::send`

بعد از `$sms_send = SMS::send( … )`، اگر آبجکت درگاه `wp_error` داشته باشد باید `$sms_error` ست شود (در غیر این صورت UI «موفق» نشان می‌دهد ولی پیامک نرفته). نسخهٔ کامل را از workspace کپی کنید.

### علائم وجود پچ

```text
wp_rand( 10000000, 99999999 )
auth.lost_password
```

---

## پچ ۷ — `FarazsmsNew.php` — تشخیص خطای API الگو

### فایل

```text
wp-content/themes/dr-plus/inc/sms/Gateways/FarazsmsNew.php
```

### متد `_send_by_pattern` — جایگزین انتهای متد بعد از `wp_remote_post`

```php
		$request = wp_remote_post( "{$this->base_url}/ws/v1/sms/pattern", $request_args );
		if( is_wp_error( $request ) ) {
			$this->wp_error = $request;
			return $this;
		}
		$code = (int) wp_remote_retrieve_response_code( $request );
		$body = json_decode( (string) wp_remote_retrieve_body( $request ), true );
		if( $code < 200 || $code >= 300 || ( is_array( $body ) && ( $body['status'] ?? '' ) === 'error' ) ) {
			$msg = is_array( $body ) ? (string) ( $body['message'] ?? '' ) : '';
			if( $msg === '' ) {
				$msg = sprintf( 'Faraz pattern HTTP %d', $code );
			}
			$this->wp_error = new \WP_Error( 'farazsms_pattern_failed', $msg );
		}
		return $this;
```

### علامت جستجو

```text
farazsms_pattern_failed
```

---

## پچ ۸ — `Search.php` — AJAX جستجو بدون fatal روی `exclude_post_types`

### فایل

```text
wp-content/themes/dr-plus/inc/AJAX/Search.php
```

### متد

`DrPlus\AJAX\Search::exec()` — شاخه `!$only`

### چرا لازم است؟

`Options::get_options()` برای کلیدهای پیش‌فرض از نوع **آرایه** که در آپشن ذخیره نشده‌اند، به‌خاطر شاخه «Repeaters» مقدار پیش‌فرض را ست نمی‌کند. در نتیجه `$options['exclude_post_types']` می‌تواند `null`/غیرآرایه باشد و در PHP 8+ این‌ها fatal می‌شوند:

- `array_merge( $excludes, $custom_excludes )`
- `array_diff( $post_types, $excludes )`

علائم: درخواست `admin-ajax.php?action=drplus_search` بدون پارامتر `only` → HTTP 500 (Upstream Error). با `only=specialist` / `only=hospital` و غیره ۲۰۰ برمی‌گردد (چون آن شاخه اجرا نمی‌شود).

### قبل از پچ

```php
		if( !$only ) {
			$excludes = $options['exclude_post_types'];
			if( !empty( $this->data['excludes'] ) ) {
				$custom_excludes = json_decode( stripslashes( $this->data['excludes'] ), true );
				$excludes = array_values( array_unique( array_merge( $excludes, $custom_excludes ) ) );
			}
			$post_types = array_diff( $post_types, $excludes );
```

### بعد از پچ

```php
		if( !$only ) {
			// Options::get_options() skips unset array defaults ("Repeaters" branch), so
			// exclude_post_types may be missing/null — array_diff/array_merge fatals on PHP 8+.
			$excludes = !empty( $options['exclude_post_types'] ) && is_array( $options['exclude_post_types'] )
				? $options['exclude_post_types']
				: ['page', 'attachment', 'e-floating-buttons'];
			if( !empty( $this->data['excludes'] ) ) {
				$custom_excludes = json_decode( stripslashes( $this->data['excludes'] ), true );
				if( !is_array( $custom_excludes ) ) {
					$custom_excludes = [];
				}
				$excludes = array_values( array_unique( array_merge( $excludes, $custom_excludes ) ) );
			}
			$post_types = array_diff( $post_types, $excludes );
```

### علامت جستجو / بازاعمال

```text
exclude_post_types may be missing/null
is_array( $options['exclude_post_types'] )
```

بعد از آپدیت تم: همین گارد را دوباره در `exec()` قبل از `array_merge` / `array_diff` قرار دهید.

---

## پچ ۹ — `SMS.php` — فیلتر `drplus/sms/pre_send` (صف پیامک)

### فایل

```text
wp-content/themes/dr-plus/inc/sms/Gateways/SMS.php
```

### متد

`DrPlus\SMS\SMS::send( $to, string $type, array $variables = [] )` — **اولین خطوط متد**، قبل از `get_gateway_class()`.

### چرا لازم است؟

پیامک‌های رزرو (ثبت، لغو، یادآور) داخل درخواست کاربر/ادمین مستقیم به درگاه می‌رفتند (مسدودکننده، بدون تلاش مجدد). پلاگین اختصاصی `medyar-sms-queue` روی این فیلتر کلیدهای `reserve_notification.*` را می‌گیرد، در جدول صف ثبت می‌کند و `true` برمی‌گرداند؛ ورکر پس‌زمینه بعداً همین متد را (با bypass فیلتر) دوباره اجرا می‌کند. بقیهٔ کلیدها (OTP، فراموشی رمز و …) دست‌نخورده می‌مانند. جزئیات: [MEDYAR-SMS-QUEUE.md](./MEDYAR-SMS-QUEUE.md).

### قبل از پچ

```php
	public static function send( $to, string $type, array $variables = [] ) {
		$gateway = self::get_gateway_class();
		if( !$gateway || is_wp_error( $gateway ) ) return $gateway;
		return $gateway->send_by_pattern( $to, $type, $variables );
	}
```

### بعد از پچ

```php
	public static function send( $to, string $type, array $variables = [] ) {
		// Medyar patch: lets medyar-sms-queue persist reserve SMS and send them from a background worker.
		$pre = apply_filters( 'drplus/sms/pre_send', null, $to, $type, $variables );
		if ( null !== $pre ) {
			return $pre;
		}
		$gateway = self::get_gateway_class();
		if( !$gateway || is_wp_error( $gateway ) ) return $gateway;
		return $gateway->send_by_pattern( $to, $type, $variables );
	}
```

### علامت جستجو / بازاعمال

```text
drplus/sms/pre_send
Medyar patch: lets medyar-sms-queue
```

بعد از آپدیت تم: همین ۵ خط را دوباره ابتدای `SMS::send()` بگذارید. اگر پاک شود، پیامک رزرو **همچنان ارسال می‌شود** ولی همگام و بدون تلاش مجدد/گزارش صف.

---

## پچ ۱۰ — فهرست گفت‌وگوها (`template-chats-list.php`) — ترتیب و زمان شروع مکالمه

### فایل

`wp-content/themes/dr-plus/templates/chats/template-chats-list.php`

### چرا لازم است؟

`Chat::get_sessions()` رمزگذاری‌شده است و ترتیب «جدید به قدیم» را رعایت نمی‌کند؛ ساعت `.chat-item-head-time` هم `last_message_time ?? created_at` بود (یعنی زمان ثبت رزرو برای گفت‌وگوی بدون پیام). مدیار می‌خواهد فهرست بر اساس **زمان شروع مکالمه** (`drplus_chat_sessions.open_at`) نزولی باشد و همان زمان نمایش داده شود. منطق در پلاگین `medyar-chat-pro` (`MCP_Frontend::list_sessions`) است؛ قالب فقط یک فیلتر و یک متغیر لازم دارد.

### ۱۰‑الف) بعد از `$chats = Chat::get_sessions( $user_id, $view_type );`

```php
// MEDYAR PATCH: list order + displayed time (medyar-chat-pro sorts by conversation start, sets list_time).
$chats = (array) apply_filters( 'drplus/chats/list_sessions', $chats, $user_id, $view_type );
```

(باید **قبل از** محاسبه صفحه‌بندی و `array_slice` باشد تا ترتیب در همه صفحه‌ها درست باشد.)

### ۱۰‑ب) داخل `.chat-item-head-time-wrap`

قبل:

```php
<span class="chat-item-head-date" role="note"><?php echo date_i18n( 'd F Y', strtotime( esc_html( $chat['last_message_time'] ?? $chat['created_at'] ) ) ) ?></span>
<span class="chat-item-head-time" role="note"><?php echo date_i18n( 'H:i', strtotime( esc_html( $chat['last_message_time'] ?? $chat['created_at'] ) ) ) ?></span>
```

بعد:

```php
<?php $list_time = $chat['list_time'] ?? ( $chat['last_message_time'] ?? $chat['created_at'] ); // MEDYAR PATCH ?>
<span class="chat-item-head-date" role="note"><?php echo date_i18n( 'd F Y', strtotime( esc_html( $list_time ) ) ) ?></span>
<span class="chat-item-head-time" role="note"><?php echo date_i18n( 'H:i', strtotime( esc_html( $list_time ) ) ) ?></span>
```

### علامت جستجو / بازاعمال

```text
drplus/chats/list_sessions
MEDYAR PATCH
```

اگر پچ پاک شود: فهرست به ترتیب و زمان پیش‌فرض قالب برمی‌گردد (خطا نمی‌دهد).

---

## پچ ۱۱ — `navigation.php` — موجودی کیف بدون fatal Sheyda

### فایل

```text
wp-content/themes/dr-plus/woocommerce/myaccount/navigation.php
```

### چرا لازم است؟

با فعال بودن `medyar-toman-wallet`، ماژول Sheyda داخل قالب لزوماً کلاس `Sheyda\Wallet\Utils\Settings` را لود نمی‌کند. قالب اصلی در سایدبار حساب این را صدا می‌زند:

```php
use Sheyda\Wallet\Utils\Settings as WalletSettings;
…
WalletSettings::get_settings()['enable']
```

نتیجه: `Uncaught Error: Class "Sheyda\Wallet\Utils\Settings" not found` → `/my-account/` و ریدایرکت بعد از `/medlogin` با Upstream 500 می‌میرد.

### تغییر

- حذف `use` سخت به کلاس‌های Sheyda
- اگر `Wallet_Manager` هست → موجودی تومان از Medyar Toman Wallet
- وگرنه اگر کلاس‌های Sheyda لود باشند → همان منطق قبلی با `class_exists`
- اگر هیچ‌کدام نبود → بلوک موجودی نشان داده نشود (بدون fatal)

### علامت جستجو / بازاعمال

```text
Wallet_Manager
Sheyda\\Wallet\\Utils\\Settings
myaccount-user-wallet-balance
```

اگر پچ پاک شود و Sheyda لود نباشد: دوباره ۵۰۰ روی my-account.

---

## چک‌لیست بعد از آپدیت قالب (SMS / Auth)

1. [ ] `utils-booking.php` — متدهای پچ ۳
2. [ ] `functions/booking.php` — بدون هوک دوبل ثبت SMS
3. [ ] `CheckBookingMessages.php` — پچ ۵
4. [ ] `Auth.php` — پچ ۶ (پسورد عددی)
5. [ ] `FarazsmsNew.php` — پچ ۷
6. [ ] `Search.php` — پچ ۸ (AJAX جستجو)
6b. [ ] `SMS.php` — پچ ۹ (`drplus/sms/pre_send`)؛ تست: ثبت رزرو → ردیف `booking` در «صف پیامک»
6c. [ ] `template-chats-list.php` — پچ ۱۰ (`drplus/chats/list_sessions`)؛ تست: فهرست گفت‌وگوهای متخصص جدید→قدیم با ساعت شروع مکالمه
6d. [ ] `navigation.php` — پچ ۱۱ (`Wallet_Manager` / guard Sheyda)؛ تست: `/my-account/` بدون ۵۰۰ و موجودی تومان در سایدبار
7. [ ] mu-pluginهای `medyar-reserve-book-sms.php` و `medyar-skip-pwsms-booking-orders.php` سر جایشان
8. [ ] تست: ثبت → ۱ پیامک؛ لغو → پیامک لغو؛ یادآور طبق زمان‌بندی؛ فراموشی رمز → پیامک عددی؛ جستجوی هدر بدون ۵۰۰؛ لاگین `/medlogin` → my-account

---

## نسخه مرجع

- قالب: `dr-plus`
- آخرین به‌روزرسانی: ۱۴۰۴/۰۸/۲۷ (2026-11-18) — پچ ۱۱ navigation کیف پول / رفع ۵۰۰ my-account
- پچ‌های قالب: **۱ تا ۱۱**
- منبع: پچ‌های اعمال‌شده روی production / workspace محلی Medyar
- سند مکمل پلاگین‌ها / mu-pluginها: `docs/MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md`
