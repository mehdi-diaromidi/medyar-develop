# دستورالعمل بازاعمال پچ‌های Medyar بعد از آپدیت پلاگین / قالب

این سند **فهرست کامل** تغییراتی است که روی پروژه اعمال شده و ممکن است با آپدیت پاک شوند. بعد از آپدیت هر جزء، همان بخش را دوباره اعمال کنید.

سندهای تخصصی مرتبط:

| سند | موضوع |
|-----|--------|
| [THEME-PATCHES-AFTER-UPDATE.md](./THEME-PATCHES-AFTER-UPDATE.md) | فقط پچ‌های قالب `dr-plus` |
| [MEDYAR-SMS-BOOKING-AND-OWNERSHIP.md](./MEDYAR-SMS-BOOKING-AND-OWNERSHIP.md) | رفتار مطلوب پیامک + نکات Faraz |
| [MEDYAR-SMS-QUEUE.md](./MEDYAR-SMS-QUEUE.md) | صف پیامک واحد (`medyar-sms-queue`): API، نقشهٔ پیامک‌ها، کرون سرور |
| [CHAT.md](./CHAT.md) | چت پزشکی (`medyar-chat-pro`): معماری، امنیت، قرارداد قالب و چک‌لیست بعد از آپدیت `dr-plus` |

---

## جدول سریع — چه چیزی با آپدیت پاک می‌شود؟

| # | محل | فایل / جزء | با آپدیت پاک می‌شود؟ | اقدام بعد از آپدیت |
|---|-----|------------|----------------------|---------------------|
| 1 | **mu-plugin** | `medyar-skip-pwsms-booking-orders.php` (v1.5.0+) | معمولاً **خیر** | اگر حذف شد، کل فایل را از بکاپ بگذارید |
| 1b | **mu-plugin** | `medyar-reserve-book-sms.php` (v1.3.3) | معمولاً **خیر** | مالک SMS ثبت؛ فقط الگوهای book را dedupe می‌کند |
| 1c | **mu-plugin** | `medyar-minicart-remove-loading.php` (v1.0.0) | معمولاً **خیر** | لودینگ مینی‌کارت هنگام حذف؛ به markup قالب وابسته است → بخش ۱‑ج |
| 1d | **mu-plugin** | `medyar-booking-clear-cart.php` (v1.1.0) | معمولاً **خیر** | پاک‌کردن state رزرو بعد از سفارش؛ وابسته به کلیدهای session قالب → بخش ۱‑د |
| 1e | **mu-plugin** | `medyar-checkout-ui-tweaks.php` (v1.0.0) | معمولاً **خیر** | padding نوار کد تخفیف چک‌اوت → بخش ۱‑هـ |
| 1f | **mu-plugin** | `medyar-wp-rocket-wc-fragments.php` (v1.0.0) | معمولاً **خیر** | غیرفعال‌سازی کش fragment سبد خالی WP Rocket → بخش ۱‑و |
| 2 | **PWSMS** | `persian-woocommerce-sms/src/Orders.php` | **بله** | پچ ۲-A |
| 3 | **PWSMS** | `…/src/Helper.php` | **بله** | پچ ۲-B |
| 4 | **PWSMS** | `…/src/Bot.php` | **بله** | پچ ۲-C |
| 5 | **Faraz SMS** (افزونه) | — | پچ مستقیم نداریم | فقط mu-plugin + پچ تم `FarazsmsNew.php` |
| 6 | **کیف پول** | `medyar-toman-wallet/…/class-woocommerce-checkout-wallet.php` | اگر جایگزین کنید: **بله** | از بکاپ |
| 7 | **قالب dr-plus** | پچ‌های ۱–۸ | **بله** | → [THEME-PATCHES](./THEME-PATCHES-AFTER-UPDATE.md) |
| 8 | **قالب** | `woocommerce/myaccount/dashboard.php` | **بله** | stub + پلاگین پیشخوان |
| 9 | **پیشخوان** | `medyar-myaccount-dashboard` (v1.1.0) | اختصاصی | بکاپ کل پوشه؛ option `medyar_admin_reserve_sms` |
| 10 | **آزمایشگاه** | `lab-booking-system` (JS + پیامک ادمین) | اگر جایگزین شود: **بله** | پچ ۱۰ + بکاپ کل پوشه |
| 11 | **داروخانه** | `pharmacy-pro-system/assets/script.js` (+ shortcode) | اگر جایگزین شود: **بله** | پچ ۱۰ (تداخل نام JS) |
| 12 | **صف پیامک** | `medyar-sms-queue` (v1.0.0) + اتصال در ARP / کیف پول / پیشخوان / قالب | پلاگین اختصاصی: **خیر**؛ اتصال قالب: **بله** | بخش ۱۲ + پچ ۹ قالب |
| 13 | **چت پزشکی** | `medyar-chat-pro` (v1.0.0) — بدون پچ روی فایل‌های قالب | **خیر** (پلاگین اختصاصی)؛ به قرارداد DOM/REST قالب وابسته است | چک‌لیست بخش ۶ در [CHAT.md](./CHAT.md) |
| 14 | **wp-content/.htaccess** | مسدودسازی `*.log` (لاگ‌های حاوی اطلاعات بیمار) | آپدیت وردپرس/قالب: **خیر**؛ ابزارهای امنیتی/کش ممکن است بازنویسی کنند | اگر نبود، از workspace دوباره آپلود کنید |

---

## ۱) Must-Use Plugin — پیامک رزرو + مالک واحد + truncate پترن

### مسیر

```text
wp-content/mu-plugins/medyar-skip-pwsms-booking-orders.php
```

### نسخه فعلی

`1.5.0`

### چه می‌کند؟ (خلاصه)

| قابلیت | توضیح |
|--------|--------|
| اسکیپ رزرو | سفارش‌های Book Product / `_booking_data` / پلن پیامک خودکار نگیرند |
| مالک واحد | Faraz `native` → PWSMS detach؛ حالت `pwsms` → Faraz native strip |
| Proxy | `Helper::send_sms` حتی با OPcache کهنه بلاک رزرو می‌شود |
| Truncate | attributeهای پترن IranPayamak (`price`≤25 و …) |

### بعد از آپدیت

- آپدیت PWSMS/Faraz/تم معمولاً این فایل را پاک **نمی‌کند**.
- اگر روی سرور نبود: **کل فایل** را از workspace/`docs` بکاپ کپی کنید (جایگزینی جزئی لازم نیست).
- Faraz را پچ فایل نکنید؛ همین mu-plugin با آن حرف می‌زند.

### علامت وجود روی سرور

جستجو در فایل:

```text
medyar_pwsms_sync_sms_owner
medyar_pwsms_is_booking_order
medyar_reserve_sending_cancel
medyar_reserve_sending_reminder
medyar_reserve_sending_admin
Version: 1.5.0
```

### نکته مهم — HTTP kill رزرو

هنگام `update_status` سفارش رزرو، `$GLOBALS['medyar_block_sms_http'] = true` می‌شود. فیلتر `pre_http_request` باید برای این فلگ‌ها **bypass** کند تا پیامک عمدی رزرو/لغو/یادآور/ادمین قطع نشود:

```text
medyar_reserve_sending_book
medyar_reserve_sending_cancel
medyar_reserve_sending_reminder
medyar_reserve_sending_admin
```

---

## ۱‑ب) Must-Use Plugin — مالک SMS ثبت رزرو + dedupe

### مسیر

```text
wp-content/mu-plugins/medyar-reserve-book-sms.php
```

### نسخه فعلی

`1.3.3`

### چه می‌کند؟

| قابلیت | توضیح |
|--------|--------|
| مالک ثبت | روی `woocommerce_order_status_changed` / `thankyou` پیامک `book` را یک‌بار می‌فرستد |
| قفل | transient + متا `_medyar_reserve_book_sms_sent` |
| dedupe HTTP | فقط اگر `code` یکی از **الگوهای book** باشد و transient شماره فعال باشد بلاک می‌کند |
| bypass | فلگ‌های `medyar_reserve_sending_*` مسیر را باز می‌گذارند |

> از v1.3.3 به بعد دیگر OTP / فراموشی رمز / یادآور را به‌خاطر transient شماره رزرو بلاک **نمی‌کند** (فقط الگوهای book).

### علامت جستجو

```text
Version: 1.3.3
Only block duplicate *book* patterns
medyar_reserve_book_sms_on_status
```

---

## ۱‑ج) Must-Use Plugin — لودینگ حذف آیتم از مینی‌کارت هدر

### مسیر

```text
wp-content/mu-plugins/medyar-minicart-remove-loading.php
```

### چرا لازم است؟

قالب `dr-plus` در `woocommerce/cart/mini-cart.php` یک overlay به نام `.mini-cart-loading` (بلور + SVG لودینگ) دارد، اما `assets/js/wc/wc.js` فقط هنگام تغییر **تعداد** آن را نشان می‌دهد. حذف آیتم (`.remove_from_cart_button`) به هندلر پیش‌فرض WooCommerce (`add-to-cart.js` → `onRemoveFromCart`) می‌رود که فقط همان ردیف را با blockUI بلاک می‌کند؛ نتیجه برای کاربر فقط کرسر `wait` بود.

### چه می‌کند؟

- کلیک روی `.woocommerce-mini-cart .remove_from_cart_button` → `.mini-cart-loading` همان مینی‌کارت با `display:flex` fade-in می‌شود.
- روی `removed_from_cart` / `wc_fragments_refreshed` / `wc_fragments_loaded` مخفی می‌شود (در حالت موفق، fragment جدید جایگزین markup قدیم شده و خودش حذف می‌شود).
- CSS: `.woocommerce-mini-cart .blockUI.blockOverlay{opacity:0!important;cursor:default!important}` تا کرسر لودینگ ردیف دیده نشود.
- پچ مستقیم روی قالب نداریم؛ با آپدیت قالب فایل پاک نمی‌شود.

### بعد از آپدیت قالب / ووکامرس

اگر قالب کلاس‌های زیر را تغییر داد، سلکتورهای mu-plugin را هم به‌روز کنید:

```text
.mini-cart-loading                  (woocommerce/cart/mini-cart.php)
.header-mini-cart-content           (wrapper پاپ‌اور هدر)
.widget_shopping_cart_content       (fragment ووکامرس)
.remove_from_cart_button            (لینک حذف)
```

### علامت جستجو

```text
Medyar — Mini-cart remove loading
medyar_minicart_remove_loading_assets
```

---

## ۱‑د) Must-Use Plugin — پاک‌کردن رزرو از سبد بعد از ثبت سفارش

### مسیر

```text
wp-content/mu-plugins/medyar-booking-clear-cart.php
```

### چرا لازم است؟

فلوی رزرو قالب در `inc/SaveBookingProcess.php` است که **انکد/لایسنس‌دار** است و قابل پچ نیست. بعد از ثبت سفارش رزرو، ووکامرس سبد را خالی می‌کند (`process_order_without_payment` → `wc_empty_cart()`)، اما state رزرو در session باقی می‌ماند:

- `WC()->session` کلید `book_data`
- `$_SESSION['booking']` (فقط داخل `woocommerce/checkout/thankyou.php` و آن هم دیر unset می‌شود)

نتیجه: هر بار که کاربر به `/checkout/` (یا صفحه order-received که همان برگه پرداخت است) می‌رفت، کد انکد شده آیتم «رزرو نوبت …» (محصول `drplus_booking_product_id`) را دوباره به سبد اضافه می‌کرد و **محصولات فیزیکی سبد را حذف** می‌کرد؛ یعنی کاربر بعد از یک رزرو عملاً نمی‌توانست خرید دیگری انجام دهد.

### چه می‌کند؟

- `woocommerce_payment_complete` (اولویت 99): اگر سفارش `_booking_data` یا `_plan_data` دارد و درخواست متعلق به همان مشتری لاگین است → state پاک می‌شود.
- `template_redirect` (اولویت 9999) روی order-received با `key` معتبر → همان پاک‌سازی (پوشش درگاه بانکی و برگشت از درگاه).
- بک‌آپ: هوک `get_template_part_templates/booking/template-booking-step` با name=`receipt`.
- پاک‌سازی = unset `book_data` + unset `$_SESSION['booking']` + حذف فقط آیتم‌های رزرو/پلن از سبد (`is_booking` / `book_data` / `is_plan` / product_id = `drplus_booking_product_id`). محصولات فیزیکی دست نمی‌خورند.
- اگر `book_data` در session متعلق به رزرو دیگری باشد (`book_id` یا `specialist_id`/`raw_date`/`raw_time` متفاوت) دست نمی‌زند.

### بعد از آپدیت قالب

اگر قالب نام کلیدهای session یا cart item (`book_data`، `is_booking`، `is_plan`، option `drplus_booking_product_id`، متای `_booking_data` / `_plan_data`) را عوض کرد، همین فایل را هماهنگ کنید. تست: رزرو با کیف پول → بعد از رسید، سبد خالی؛ افزودن یک محصول فیزیکی → `/checkout/` باید چک‌اوت معمولی باشد نه رزرو.

### علامت جستجو

```text
Medyar — Clear booking state after order
medyar_booking_clear_state
```

---

## ۱‑هـ) Must-Use Plugin — بهبودهای UI چک‌اوت

### مسیر

```text
wp-content/mu-plugins/medyar-checkout-ui-tweaks.php
```

### چه می‌کند؟

CSS inline بعد از `drplus-wc-checkout` (فقط `is_checkout()`):

```css
.woocommerce .woocommerce-form-coupon-toggle .woocommerce-info{padding:12px 16px;align-items:center;flex-wrap:wrap;gap:4px 8px;line-height:1.8}
```

### چرا؟

`assets/css/wc/wc.min.css` قالب به `.woocommerce-info` بوردر 1px می‌دهد ولی `assets/css/wc/checkout.min.css` در `.woocommerce-form-coupon-toggle .woocommerce-info` مقدار `padding:0` می‌گذارد؛ متن «کد تخفیف دارید؟…» به بوردر می‌چسبید.

### بعد از آپدیت قالب

اگر قالب خودش padding را درست کرد، این قانون را بردارید. علامت: `Medyar — Checkout UI tweaks`.

---

## ۱‑و) Must-Use Plugin — کش fragment سبد خالی WP Rocket

### مسیر

```text
wp-content/mu-plugins/medyar-wp-rocket-wc-fragments.php
```

### چرا لازم است؟

قالب در `inc/Integrations/integrations-wp-rocket.php` فیلتر `rocket_cache_wc_empty_cart` را `false` می‌کند، اما WP Rocket این فیلتر را هنگام ثبت subscriberها روی `plugins_loaded` می‌خواند؛ یعنی قبل از لود قالب → فیلتر قالب بی‌اثر بود. ترنزینت `rocket_get_refreshed_fragments_cache` با رشته خالی ذخیره شده بود و `?wc-ajax=get_refreshed_fragments` برای همه مهمان‌ها / سبدهای خالی پاسخ **200 خالی** می‌داد (JS مینی‌کارت JSON نمی‌گرفت).

### چه می‌کند؟

- همان `add_filter( 'rocket_cache_wc_empty_cart', '__return_false' )` را از mu-plugin (قبل از پلاگین‌ها) اعمال می‌کند.
- یک بار ترنزینت(های) `rocket_get_refreshed_fragments_cache*` را پاک می‌کند (فلگ option: `medyar_rocket_fragments_cache_purged`).

### بعد از آپدیت WP Rocket / قالب

لازم نیست کاری کنید مگر فایل حذف شود. تست: `curl -X POST https://medyarco.com/?wc-ajax=get_refreshed_fragments` باید JSON با `fragments` برگرداند.

---

## ۲) پلاگین پیامک حرفه‌ای ووکامرس (PWSMS) — شخص ثالث

مسیر پایه:

```text
wp-content/plugins/persian-woocommerce-sms/
```

> این پچ‌ها با آپدیت افزونه از wordpress.org / نسخهٔ جدید **پاک می‌شوند**. حتماً بعد از آپدیت دوباره اعمال کنید.  
> حتی بدون این سه پچ، mu-plugin بخش زیادی از اسکیپ رزرو را پوشش می‌دهد؛ ولی برای اطمینان هر سه را برگردانید.

---

### پچ ۲-A — `src/Orders.php`

#### کجا؟

کلاس: `PW\PWSMS\Orders`  
متد: `send_order_sms`

#### چه اضافه شد؟

بلافاصله بعد از `wc_get_order` موفق و قبل از ارسال به مشتری/مدیر، این بلاک:

```php
		/**
		 * Skip all automatic order SMS (customer / super-admin / product admin)
		 * for Dr Plus specialist booking orders (virtual "Book Product").
		 *
		 * @param bool     $skip       Whether to skip.
		 * @param WC_Order $order      Order object.
		 * @param string   $old_status Previous status slug.
		 * @param string   $new_status New / event status slug.
		 */
		if ( (bool) apply_filters( 'pwoosms_skip_order_sms', $this->is_drplus_booking_order( $order ), $order, (string) $old_status, (string) $new_status ) ) {
			return;
		}
```

#### متد جدید داخل کلاس (قبل از `buyer_can_get_sms`)

کل متد را اضافه کنید:

```php
	/**
	 * Detect Dr Plus theme booking orders (appointment / visit checkout).
	 */
	private function is_drplus_booking_order( WC_Order $order ): bool {
		if ( function_exists( 'medyar_pwsms_is_booking_order' ) ) {
			return medyar_pwsms_is_booking_order( $order );
		}

		$book_data = $order->get_meta( '_booking_data' );
		if ( ! empty( $book_data ) ) {
			return true;
		}

		$booking_product_id = (int) get_option( 'drplus_booking_product_id', 0 );
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			if ( $booking_product_id > 0 && $product_id === $booking_product_id ) {
				return true;
			}

			$labels = [ (string) $item->get_name() ];
			$product = $item->get_product();
			if ( $product ) {
				$labels[] = (string) $product->get_name();
			}
			if ( $product_id > 0 ) {
				$labels[] = (string) get_the_title( $product_id );
			}

			foreach ( $labels as $label ) {
				if ( $label !== '' && stripos( $label, 'Book Product' ) !== false && stripos( $label, 'Drplus' ) !== false ) {
					return true;
				}
			}
		}

		return false;
	}
```

#### داخل `buyer_can_get_sms` — بعد از چک `is_wc_order`

```php
		if ( $this->is_drplus_booking_order( $order ) ) {
			return false;
		}
```

#### علامت جستجو بعد از پچ

```text
is_drplus_booking_order
pwoosms_skip_order_sms
```

---

### پچ ۲-B — `src/Helper.php`

#### کجا؟

متد: `send_sms` — **اول متد**، قبل از `esc_textarea` روی message.

#### کد اضافه شود

```php
		// Skip Dr Plus booking / Book Product automatic order SMS.
		$post_id         = ! empty( $data['post_id'] ) ? (int) $data['post_id'] : 0;
		$message_preview = ! empty( $data['message'] ) ? (string) $data['message'] : '';
		$is_booking      = false;

		if ( $post_id && function_exists( 'medyar_pwsms_is_booking_order' ) ) {
			$is_booking = medyar_pwsms_is_booking_order( $post_id );
		} elseif ( $post_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $post_id );
			if ( $order && ( $order->get_meta( '_medyar_skip_pwsms' ) || $order->get_meta( '_booking_data' ) ) ) {
				$is_booking = true;
			}
		}

		if ( ! $is_booking && $message_preview !== '' ) {
			if ( stripos( $message_preview, 'Book Product' ) !== false && stripos( $message_preview, 'Drplus' ) !== false ) {
				$is_booking = true;
			} elseif ( stripos( $message_preview, 'سلام مدیر' ) !== false && stripos( $message_preview, 'رزرو نوبت' ) !== false ) {
				$is_booking = true;
			}
		}

		if ( $is_booking ) {
			return 'skipped_drplus_booking_order';
		}
```

#### علامت جستجو

```text
skipped_drplus_booking_order
```

---

### پچ ۲-C — `src/Bot.php`

#### کجا؟

متد: `send_async` — **اول متد**، قبل از `get_uuids()`.

#### کد اضافه شود

```php
		// Never notify bots for Dr Plus booking / Book Product orders.
		if ( ! empty( $data['post_id'] ) && function_exists( 'medyar_pwsms_is_booking_order' ) && medyar_pwsms_is_booking_order( (int) $data['post_id'] ) ) {
			return false;
		}
		if ( ! empty( $data['message'] )
			&& stripos( (string) $data['message'], 'Book Product' ) !== false
			&& stripos( (string) $data['message'], 'Drplus' ) !== false ) {
			return false;
		}
```

#### علامت جستجو

```text
medyar_pwsms_is_booking_order
```

---

## ۳) پلاگین Faraz SMS (افزونه وردپرس)

**هیچ فایل داخل افزونه `faraz-sms` را دستی پچ نکنید.**

تعامل Medyar فقط از طریق:

- mu-plugin (detach / try_acquire / truncate HTTP / block booking-order HTTP با bypass فلگ‌ها)
- تنظیمات پنل Faraz (حالت `native` یا `pwsms`)
- پچ تم روی درگاه خود Dr Plus: `inc/sms/Gateways/FarazsmsNew.php` (پچ ۷ در THEME-PATCHES) — این فایل جزء قالب است نه افزونه Faraz

بعد از آپدیت افزونه Faraz فقط چک کنید mu-plugin هنوز لود می‌شود و حالت پیامک سفارش درست است.

---

## ۴) پلاگین کیف پول — `medyar-toman-wallet` (اختصاصی Medyar)

### مسیر

```text
wp-content/plugins/medyar-toman-wallet/includes/class-woocommerce-checkout-wallet.php
```

### کلاس

`MTW_WC_Checkout_Wallet`

### چه اضافه شد؟ (مربوط به SMS + مبلغ)

#### در `__construct` (کنار فیلترهای ادمین)

```php
        // SMS: after full wallet pay, WC order total is 0 — show full payable in PWSMS/Faraz SMS.
        add_filter('pwoosms_order_sms_body_before_replace', [$this, 'sms_body_use_full_wallet_total'], 10, 5);
        add_filter('woocommerce_order_get_total', [$this, 'sms_order_get_total_full_payable'], 20, 2);
```

#### متدهای جدید (نزدیک `admin_list_show_full_order_total`)

این متدها باید در کلاس باشند:

- `order_full_payable_amount()`
- `sms_body_use_full_wallet_total()`
- `format_amount_for_sms()`
- `sms_order_get_total_full_payable()`

متن کامل از نسخهٔ فعلی workspace کپی شود (علامت جستجو: `sms_body_use_full_wallet_total`).

### چرا؟

پرداخت کامل از کیف پول → `order->get_total()` = ۰ → پیامک `{price}` / `%total%` غلط می‌شد.

### بعد از آپدیت

این پلاگین اختصاصی است؛ ترجیحاً **کل پوشه را از بکاپ** برگردانید، نه فقط یک متد. اگر فقط این فایل عوض شد، بلوک‌های بالا را از workspace کپی کنید.

---

## ۵) قالب `dr-plus`

جزئیات کامل کد در:

```text
docs/THEME-PATCHES-AFTER-UPDATE.md
```

### خلاصه پچ‌های قالب (جزئیات در THEME-PATCHES)

| # | فایل | هدف |
|---|------|-----|
| 1 | `inc/Utils/utils-user.php` | فیلتر `drplus/account_menu_items` |
| 2 | `templates/header/template-header-action-account_btn.php` | `dir=ltr` برای شماره ماسک‌شده |
| 3 | `inc/Utils/utils-booking.php` | SMS ثبت/لغو (`send_reserve_event_sms` و …) |
| 4 | `functions/booking.php` | بدون هوک دوبل SMS ثبت |
| 5 | `inc/Cronjobs/CheckBookingMessages.php` | یادآور + heal کرون |
| 6 | `inc/AJAX/Auth.php` | فراموشی رمز: پسورد عددی ۸ رقمی |
| 7 | `inc/sms/Gateways/FarazsmsNew.php` | `farazsms_pattern_failed` روی HTTP/API error |
| — | `woocommerce/myaccount/dashboard.php` | stub؛ UI در پلاگین جدا |

### پیشخوان حساب + پیامک ادمین رزرو

```text
wp-content/plugins/medyar-myaccount-dashboard/   (Version 1.1.0)
```

| جزء | نقش |
|-----|-----|
| `templates/myaccount/dashboard.php` | UI پیشخوان حساب |
| `includes/class-admin-reserve-sms.php` | منوی WP «پیامک ادمین رزرو» + ارسال روی ثبت/لغو |
| `includes/admin-page-reserve-sms.php` | فرم تنظیمات |
| `assets/css/admin-reserve-sms.css` / `assets/js/admin-reserve-sms.js` | استایل/ریپیتر شماره |

- Option: `medyar_admin_reserve_sms` (enabled، phones[]، book.pattern/message، book_canceled.pattern/message)
- هوک‌ها: `woocommerce_order_status_changed` (ثبت) و `drplus/booking/appointment_canceled` (لغو)
- هنگام ارسال: `$GLOBALS['medyar_reserve_sending_admin'] = true`

فایل تم فقط fallback است:

```text
wp-content/themes/dr-plus/woocommerce/myaccount/dashboard.php
```

بعد از آپدیت تم:

1. پچ‌های ۱–۷ از `THEME-PATCHES-AFTER-UPDATE.md` را بزنید.
2. stub `dashboard.php` تم را دوباره بگذارید.
3. پلاگین `medyar-myaccount-dashboard` را فعال نگه دارید.

---

## ۶) پلاگین‌های اختصاصی / شخص ثالث — خلاصه نگهداری

| جزء | نقش | با آپدیت شخص ثالث؟ |
|-----|-----|----------------------|
| `medyar-toman-wallet` | کیف پول + مبلغ SMS | فقط اگر خودتان جایگزین کنید |
| `medyar-myaccount-dashboard` | پیشخوان + پیامک ادمین رزرو | اختصاصی؛ از بکاپ |
| `lab-booking-system` | فرم آزمایش + پیامک ادمین (متن آزاد) + پچ JS | اگر جایگزین شود از بکاپ / پچ ۱۰ |
| `pharmacy-pro-system` | پچ JS (جلوگیری از تداخل با آزمایشگاه) | اگر جایگزین شود از بکاپ / پچ ۱۰ |
| `affiliate-referral` | همکار | اختصاصی |
| `persian-woocommerce-sms` | پچ‌های Orders/Helper/Bot | **بله — دوباره بزنید** |
| mu-plugin skip-pwsms | اسکیپ SMS خودکار رزرو + bypass فلگ‌ها | معمولاً خیر |
| mu-plugin reserve-book-sms | مالک SMS ثبت + dedupe فقط book | معمولاً خیر |

اگر مجبور به جایگزینی کل پوشه شدید، از بکاپ نسخهٔ Medyar استفاده کنید.

---

## چک‌لیست عملی بعد از هر آپدیت

### بعد از آپدیت PWSMS

- [ ] `Orders.php` → `is_drplus_booking_order` / `pwoosms_skip_order_sms`
- [ ] `Helper.php` → `skipped_drplus_booking_order`
- [ ] `Bot.php` → `medyar_pwsms_is_booking_order` در `send_async`
- [ ] mu-plugin هنوز در `mu-plugins/` هست
- [ ] تست: سفارش رزرو بدون SMS خودکار؛ سفارش فیزیکی یک‌بار برای مشتری و مدیر

### بعد از آپدیت Faraz

- [ ] mu-plugin سر جایش است
- [ ] حالت پیامک سفارش (`native` / `pwsms`) درست است
- [ ] تست سفارش فیزیکی (دوبار نفرستد)

### بعد از آپدیت قالب dr-plus

- [ ] پچ‌های ۱–۸ در [THEME-PATCHES-AFTER-UPDATE.md](./THEME-PATCHES-AFTER-UPDATE.md)
  - شامل: `utils-user`، هدر RTL، `utils-booking`، `booking.php`، `CheckBookingMessages`، `Auth.php`، `FarazsmsNew.php`، `AJAX/Search.php`
- [ ] stub `woocommerce/myaccount/dashboard.php`
- [ ] پلاگین `medyar-myaccount-dashboard` فعال (منوی پیامک ادمین رزرو)
- [ ] mu-pluginهای SMS سر جایشان (نسخه‌های جدول بالا)
- [ ] mu-plugin `medyar-minicart-remove-loading.php` — کلاس `.mini-cart-loading` هنوز در `woocommerce/cart/mini-cart.php` قالب هست؟ (بخش ۱‑ج)
- [ ] mu-plugin `medyar-booking-clear-cart.php` — بعد از رزرو، سبد خالی و `/checkout/` با محصول فیزیکی چک‌اوت معمولی است؟ (بخش ۱‑د)
- [ ] mu-plugin `medyar-checkout-ui-tweaks.php` — نوار کد تخفیف padding دارد؟ (بخش ۱‑هـ)
- [ ] تست: ثبت / لغو / یادآور / فراموشی رمز با موبایل / پیامک ادمین (اگر فعال)
- [ ] چت پزشکی: چک‌لیست «بعد از آپدیت قالب» در [CHAT.md](./CHAT.md) (بخش ۶) — `mcp-chat.js` به‌جای `chat.min.js` لود شود

### بعد از جایگزینی کیف پول

- [ ] فیلترهای SMS در `class-woocommerce-checkout-wallet.php`
- [ ] تست پرداخت کامل کیف پول → مبلغ پیامک ≠ ۰

### بعد از جایگزینی medyar-myaccount-dashboard

- [ ] کل پوشه از بکاپ (شامل `includes/class-admin-reserve-sms.php` و assets)
- [ ] option `medyar_admin_reserve_sms` در دیتابیس مانده باشد
- [ ] منوی «پیامک ادمین رزرو» در پیشخوان WP دیده شود

### بعد از جایگزینی lab-booking-system / pharmacy-pro-system

- [ ] پچ ۱۰ (تداخل JS + پیامک ادمین درخواست‌ها) دوباره اعمال شود
- [ ] منوی «تنظیمات ← پیامک ادمین درخواست‌ها» دیده شود (lab/nurse/phar)
- [ ] ثبت آزمایش در `lab-orders` برود نه `phar-orders`
- [ ] optionهای `lab_admin_sms_*` / `nurse_admin_sms_*` / `phar_admin_sms_*` در دیتابیس مانده باشند

---

## ۱۰) آزمایشگاه / داروخانه — تداخل JS + پیامک ادمین

### چرا لازم است؟

هر دو پلاگین روی **همهٔ صفحات** اسکریپت لود می‌کردند و توابع سراسری هم‌نام داشتند (`submitFinalOrder`، `togglePresInput`). اسکریپت داروخانه نسخهٔ آزمایشگاه را بازنویسی می‌کرد → ثبت آزمایش با `phar_submit` به جدول داروخانه می‌رفت.

### فایل‌های پچ‌شده

```text
wp-content/plugins/lab-booking-system/
  assets/script.js
  includes/shortcode-handler.php
  includes/admin-sms-settings.php   (جدید)
  includes/admin-page.php           (لینک به تنظیمات ادمین)
  lab-pro-system.php                (require فایل تنظیمات)

wp-content/plugins/pharmacy-pro-system/
  assets/script.js
  includes/shortcode-handler.php
```

### علائم وجود پچ JS

در `lab-booking-system/assets/script.js`:

```text
labSubmitFinalOrder
labTogglePresInput
#lab-booking-app
if (!$('#lab-booking-app').length)
```

در `pharmacy-pro-system/assets/script.js`:

```text
pharSubmitFinalOrder
pharTogglePresInput
#phar-booking-app
if (!$('#phar-booking-app').length)
```

### پیامک ادمین درخواست‌ها (متن آزاد مثل ARP)

| مورد | مقدار |
|------|--------|
| منوی WP | **تنظیمات ← پیامک ادمین درخواست‌ها** (`options-general.php?page=lab-admin-sms`) |
| کلاس | `Lab_Admin_Sms_Settings` در `includes/admin-sms-settings.php` |
| ارسال | متن آزاد با `farazsms_send_sms` (نه پترن) |
| شماره ادمین | مشترک؛ `billing_phone` → fallback `mobile` |
| UI | متغیرهای کلیک‌خور + شمارنده کاراکتر/پیامک |
| هوک lab | `ajax_submit` → `notify_admins('lab', …)` |
| هوک nurse | `gform_after_submission_6` → `notify_admins('nurse', …)` |
| هوک phar | pharmacy `ajax_submit` → `notify_admins('phar', …)` |

#### Optionها

| option | نقش |
|--------|-----|
| `lab_admin_sms_recipient_ids` | گیرندگان مشترک (administrator) |
| `lab_admin_sms_enabled` / `lab_admin_sms_template` | آزمایشگاه |
| `nurse_admin_sms_enabled` / `nurse_admin_sms_template` | پرستاری در منزل |
| `phar_admin_sms_enabled` / `phar_admin_sms_template` | داروخانه |

متغیرهای lab: `{name}` `{mobile}` `{token}` `{city}` `{province}` `{address}` `{national_code}` `{insurance}` `{sup_insurance}` `{pres_type}`  
متغیرهای nurse: `{name}` `{first_name}` `{last_name}` `{location}` `{mobile}` `{email}` `{entry_id}`  
متغیرهای phar: `{name}` `{mobile}` `{token}` `{city}` `{province}` `{address}` `{national_code}` `{insurance}` `{insurance_code}` `{pres_type}`

پترن قدیمی `lab_pat_admin_submit` دیگر استفاده نمی‌شود.

### بعد از آپدیت / جایگزینی این دو پلاگین

1. فایل‌های پچ ۱۰ را از workspace دوباره کپی کنید (lab + pharmacy shortcode).
2. منوی **پیامک ادمین درخواست‌ها** را چک کنید (سه بخش + متغیرهای کلیک‌خور).
3. تست: ثبت آزمایش / فرم پرستاری / ثبت داروخانه → پیامک ادمین.

---

## ۱۲) صف پیامک واحد — `medyar-sms-queue`

جزئیات کامل معماری و API: [MEDYAR-SMS-QUEUE.md](./MEDYAR-SMS-QUEUE.md)

### اجزا و محل اتصال

| جزء | فایل | تغییر | علامت جستجو | با آپدیت؟ |
|-----|------|-------|-------------|-----------|
| پلاگین صف | `wp-content/plugins/medyar-sms-queue/` (کل پوشه) | جدید | `MSQ_Client` | اختصاصی — از بکاپ |
| طرح همکاران | `affiliate-referral/includes/sms/class-arp-faraz-sms-gateway.php` | `enqueue()` در صورت `msq_ready()` رندر + `deliver()` → `queue_capture()` | `msq_ready`، `queue_capture`، `$capture` | اختصاصی |
| طرح همکاران | `affiliate-referral/admin/views/settings/general.php` | بخش «پیامک کیف پول» و گزینه‌های تست کیف پول **حذف شد** (مالک = تنظیمات MTW)؛ ردیف «وضعیت صف پیامک» به صفحهٔ صف لینک می‌دهد | `arp-sms-outbox-diag` | اختصاصی |
| طرح همکاران | `affiliate-referral/admin/class-arp-admin.php` + `class-arp-admin-menu.php` | ذخیرهٔ optionهای `arp_wallet_*` از تنظیمات ARP حذف شد (جلوگیری از پاک شدن قالب‌های کیف پول) | — | اختصاصی |
| کیف پول | `medyar-toman-wallet/includes/class-wallet-sms.php` | `with_msq_context()`، شاخهٔ صف در `send()`، `on_queue_sent()`؛ برداشت: **اول ادمین** | `msq_ctx`، `on_queue_sent`، `withdraw_admin` | اگر جایگزین شود: **بله** |
| پیشخوان | `medyar-myaccount-dashboard/includes/class-admin-reserve-sms.php` | `send_event()` → صف با transport `mmad_pattern`؛ `register_queue_transport()` + `send_queued_job()` | `mmad_pattern` | اگر جایگزین شود: **بله** |
| قالب | `dr-plus/inc/sms/Gateways/SMS.php` | فیلتر `drplus/sms/pre_send` | `drplus/sms/pre_send` | **بله** → [THEME-PATCHES پچ ۹](./THEME-PATCHES-AFTER-UPDATE.md) |

### بعد از جایگزینی هر جزء

- [ ] پلاگین «Medyar SMS Queue» فعال است و منوی «صف پیامک» دیده می‌شود
- [ ] کرون سرور هر دقیقه `admin-ajax.php?action=msq_cron&key=…` را صدا می‌زند (کارت سبز در صفحهٔ صف)
- [ ] ثبت معرفی بیمار → دو ردیف `arp` (ادمین با برچسب «ادمین» + بیمار) → «ارسال‌شده»
- [ ] ثبت برداشت → ردیف `withdraw_admin` قبل از `withdraw_registered`
- [ ] ثبت/لغو رزرو → ردیف‌های `booking` (`reserve.patient.book` …)
- [ ] ذخیرهٔ تنظیمات ARP، قالب‌های پیامک کیف پول را پاک نکند

---

## منبع حقیقت (بکاپ کد)

همیشه نسخهٔ پچ‌شده را از workspace محلی نگه دارید:

```text
d:\medyar-main\
```

به‌خصوص:

```text
wp-content/mu-plugins/medyar-skip-pwsms-booking-orders.php
wp-content/mu-plugins/medyar-reserve-book-sms.php
wp-content/mu-plugins/medyar-minicart-remove-loading.php
wp-content/mu-plugins/medyar-booking-clear-cart.php
wp-content/mu-plugins/medyar-checkout-ui-tweaks.php
wp-content/mu-plugins/medyar-wp-rocket-wc-fragments.php
wp-content/plugins/persian-woocommerce-sms/src/{Orders,Helper,Bot}.php
wp-content/plugins/medyar-toman-wallet/includes/class-woocommerce-checkout-wallet.php
wp-content/plugins/medyar-myaccount-dashboard/          (کل پوشه، v1.1.0+)
wp-content/plugins/lab-booking-system/                  (کل پوشه؛ پچ JS + admin-sms-settings)
wp-content/plugins/pharmacy-pro-system/assets/script.js
wp-content/plugins/pharmacy-pro-system/includes/shortcode-handler.php
wp-content/themes/dr-plus/inc/Utils/utils-user.php
wp-content/themes/dr-plus/inc/Utils/utils-booking.php
wp-content/themes/dr-plus/inc/Cronjobs/CheckBookingMessages.php
wp-content/themes/dr-plus/functions/booking.php
wp-content/themes/dr-plus/inc/AJAX/Auth.php
wp-content/themes/dr-plus/inc/sms/Gateways/FarazsmsNew.php
wp-content/themes/dr-plus/inc/sms/Gateways/SMS.php
wp-content/plugins/medyar-sms-queue/                    (کل پوشه)
wp-content/plugins/medyar-chat-pro/                     (کل پوشه)
wp-content/.htaccess                                    (مسدودسازی *.log)
wp-content/themes/dr-plus/templates/header/template-header-action-account_btn.php
wp-content/themes/dr-plus/woocommerce/myaccount/dashboard.php
docs/THEME-PATCHES-AFTER-UPDATE.md
docs/MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md
docs/MEDYAR-SMS-BOOKING-AND-OWNERSHIP.md
docs/CHAT.md
```

> mu-pluginهای دیباگ SMS (`medyar-reserve-sms-debug-log.php`، `medyar-lost-password-sms-debug-log.php`) عمداً حذف شده‌اند؛ دوباره اضافه نکنید.

---

## نسخه سند

| فیلد | مقدار |
|------|--------|
| آخرین به‌روزرسانی | 2026-10-31 (صف پیامک واحد `medyar-sms-queue`) |
| medyar-sms-queue | **1.0.0** |
| mu-plugin skip-pwsms | 1.5.0+ (bypass cancel/reminder/admin) |
| mu-plugin reserve-book-sms | **1.3.3** (dedupe فقط الگوهای book) |
| mu-plugin minicart-remove-loading | **1.0.0** (2026-10-31) |
| mu-plugin booking-clear-cart | **1.1.0** (2026-10-31) |
| mu-plugin checkout-ui-tweaks | **1.0.0** (2026-10-31) |
| mu-plugin wp-rocket-wc-fragments | **1.0.0** (2026-10-31) |
| medyar-myaccount-dashboard | **1.1.0** (پیامک ادمین رزرو) |
| lab-booking-system | پچ JS + **پیامک ادمین متن‌آزاد** (`lab-admin-sms`) |
| pharmacy-pro-system | پچ JS تداخل نام |
| پچ‌های قالب dr-plus | **۱ تا ۱۱** (→ THEME-PATCHES) |
| محدوده | SMS ثبت/لغو/یادآور/ادمین/فراموشی‌رمز + فیزیکی + کیف پول + پچ تم + آزمایشگاه/داروخانه |
