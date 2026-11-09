# دستورالعمل بازاعمال پچ‌های پیامک سفارش (رزرو + مالک واحد + مبلغ کیف پول)

> **فهرست کامل همه پچ‌ها (تم + PWSMS + کیف پول + mu-plugin):**  
> [MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md](./MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md)

این سند رفتار مطلوب و جزئیات پیامک را پوشش می‌دهد؛ برای کد دقیق بازاعمال بعد از آپدیت از سند بالا استفاده کنید.

> **از 2026-10-31:** پیامک‌های رزرو (ثبت، لغو، یادآور، ادمین رزرو) از طریق صف واحد `medyar-sms-queue` و در پس‌زمینه ارسال می‌شوند. mu-pluginهای این سند همچنان مالک «کِی ارسال شود» هستند؛ صف فقط «چطور و با تلاش مجدد» را برعهده دارد → [MEDYAR-SMS-QUEUE.md](./MEDYAR-SMS-QUEUE.md).

---

## خلاصه رفتار مطلوب

| نوع سفارش | پیامک خودکار سفارش (PWSMS / Faraz native) |
|-----------|------------------------------------------|
| رزرو نوبت / Book Product / پلن | ارسال نشود |
| خرید محصول فیزیکی / عادی | دقیقاً یک بار (ادمین و مشتری، هر کدام طبق تنظیمات) |

| پرداخت | مقدار `{price}` / `%total%` در پیامک |
|--------|--------------------------------------|
| درگاه | همان مبلغ سفارش WC |
| کیف پول (کامل یا جزئی) | مبلغ کامل قبل از کسر کیف پول (`_wallet_payable_before`) نه ۰ |

---

## مالک پیامک (جلوگیری از ارسال ۲ باره)

پلاگین **FarazSMS** دو حالت دارد:

- `native` → خود فرراز پیامک وضعیت سفارش را می‌فرستد و باید هوک‌های `PW\PWSMS\Orders::send_order_sms` جدا بمانند.
- `pwsms` → فقط PWSMS می‌فرستد؛ هوک‌های native فرراز no-op هستند.

### باگ قبلی Medyar

mu-plugin نسخهٔ ≤1.3 هوک PWSMS را برمی‌داشت و **دوباره با wrapper وصل می‌کرد**. در حالت Faraz `native` هر دو مسیر فعال می‌ماندند → پیامک سفارش فیزیکی برای ادمین و مشتری **۲ بار** می‌رفت.

### اصلاح (v1.4.0)

فایل:

```text
wp-content/mu-plugins/medyar-skip-pwsms-booking-orders.php
```

تابع `medyar_pwsms_sync_sms_owner()`:

- اگر `farazsms_order_sms_effective_mode() === 'native'` → فقط PWSMS را detach کن؛ **هرگز دوباره hook نکن**.
- در غیر این صورت → هوک‌های native فرراز را (کمربند ایمنی) جدا کن و PWSMS را دست نزن.

---

## فایل‌های درگیر

### ۱) Must-Use Plugin (پایدار بعد از آپدیت تم)

```text
wp-content/mu-plugins/medyar-skip-pwsms-booking-orders.php
```

نقش‌ها:

- متای `_medyar_skip_pwsms` در `woocommerce_checkout_create_order` وقتی سبد رزرو/پلن است
- `medyar_pwsms_is_booking_order()`
- فیلترهای `pwoosms_skip_order_sms` و `pwoosms_buyer_can_get_order_sms`
- Proxy روی `Helper::send_sms` (مقاوم به OPcache کهنه)
- بلاک Faraz برای رزرو: `farazsms_order_sms_try_acquire_send` + kill per-request
- `medyar_pwsms_sync_sms_owner()` (مالک واحد پیامک)
- Truncate attributeهای پترن Faraz (سقف `price` و …)

بعد از آپدیت پلاگین‌های SMS این فایل معمولاً پاک نمی‌شود (mu-plugin است). اگر سرور mu-plugin جدید نداشت، همین فایل را دوباره آپلود کنید.

### ۲) پچ‌های داخل PWSMS (بعد از آپدیت پلاگین دوباره اعمال شوند)

مسیر پایه:

```text
wp-content/plugins/persian-woocommerce-sms/
```

| فایل | علامت جستجو | هدف |
|------|-------------|-----|
| `src/Orders.php` | `pwoosms_skip_order_sms` / `is_drplus_booking_order` | اسکیپ ارسال خودکار برای رزرو |
| `src/Helper.php` | `skipped_drplus_booking_order` | اسکیپ در `send_sms` |
| `src/Bot.php` | `skipped_drplus_booking_order` یا فراخوانی `medyar_pwsms_is_booking_order` | جلوگیری از مسیر async ادمین برای رزرو |

اگر بعد از آپدیت PWSMS این رشته‌ها نبودند، از نسخهٔ بکاپ workspace دوباره کپی کنید یا منطق skip را از mu-plugin (فیلتر + proxy) نگه دارید؛ mu-plugin به‌تنهایی برای رزرو کافی است اگر proxy نصب شود.

### ۳) مبلغ پیامک هنگام کیف پول (پلاگین Medyar — پایدار)

```text
wp-content/plugins/medyar-toman-wallet/includes/class-woocommerce-checkout-wallet.php
```

متدها / فیلترها:

- `order_full_payable_amount()` — از `_wallet_payable_before` یا `wallet + remainder`
- فیلتر `pwoosms_order_sms_body_before_replace` → جایگزینی `{price}` قبل از `str_ireplace`
- فیلتر `woocommerce_order_get_total` (فقط وقتی backtrace مربوط به سازندهٔ SMS فرراز/PWSMS باشد) → `%total%` فرراز

---

## چک‌لیست بعد از آپدیت Faraz / PWSMS / تم

1. وجود mu-plugin v1.4.0+ را تأیید کنید.
2. در تنظیمات Faraz «پیامک سفارش» ببینید حالت `native` یا `pwsms` است؛ هر دو نباید همزمان بفرستند.
3. یک سفارش **رزرو** تست کنید → هیچ پیامک خودکار سفارش نرود؛ نوت سفارش Medyar دیده شود.
4. یک سفارش **فیزیکی** تست کنید → ادمین و مشتری هر کدام **یک** پیامک.
5. یک سفارش با **پرداخت کامل کیف پول** → مبلغ در متن پیامک ۰ نباشد.

---

## نکات OPcache

اگر پچ Helper روی دیسک هست ولی رفتار کهنه است:

- mu-plugin در بوت `opcache_invalidate` روی Helper/Orders/Bot می‌زند.
- Proxy روی `Helper::_instance` حتی با فایل کهنه، `send_sms` رزرو را بلاک می‌کند.

---

## پیامک ادمین درخواست‌ها (متن آزاد + پترن اختیاری)

جدا از پیامک پترنی مشتری (OTP / ثبت / پرداخت):

| مورد | توضیح |
|------|--------|
| منو | تنظیمات وردپرس ← **پیامک ادمین درخواست‌ها** |
| فایل | `lab-booking-system/includes/admin-sms-settings.php` |
| سرویس‌ها | آزمایشگاه + پرستاری در منزل (GF فرم ۶) + داروخانه |
| روش ارسال | پیش‌فرض `farazsms_send_sms` (متن آزاد)؛ اگر کد پترن پر باشد `farazsms_send_pattern` |
| آپشن پترن | `lab_admin_sms_pattern` / `nurse_admin_sms_pattern` / `phar_admin_sms_pattern` |
| شماره گیرنده | مشترک؛ `billing_phone` پروفایل مدیرکل |
| زمان ارسال | lab/phar: بعد از `ajax_submit` موفق؛ nurse: `gform_after_submission_6` |
| UI متن | متغیرهای کلیک‌خور + شمارنده کاراکتر/پیامک + فیلد کد پترن فراز |
| جزئیات بازاعمال | → `MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md` بخش **۱۰** |

---

## نسخه

| جزء | نسخه / یادداشت |
|-----|----------------|
| mu-plugin skip-pwsms | 1.5.0+ — بدون لاگ دیباگ؛ bypass فلگ‌های reserve_sending_* |
| mu-plugin reserve-book-sms | 1.3.3 — dedupe فقط الگوهای book |
| Wallet SMS price | فیلترهای `{price}` / `get_total` در `MTW_WC_Checkout_Wallet` |
| Lab admin SMS | متن آزاد + پترن اختیاری lab/nurse/phar + `billing_phone` (پچ ۱۰) |
| پچ تم کامل | → `THEME-PATCHES-AFTER-UPDATE.md` (۱–۷) و `MEDYAR-REAPPLY-PATCHES-AFTER-UPDATE.md` |
| آخرین به‌روزرسانی سند | 2026-10-12 |

---

## خطای مدیر: «داده درخواستی یافت نشد»

این پاسخ IranPayamak معمولاً به‌معنای کد پترن حذف‌شده نیست؛ برای پترن‌های **فعال** مدیر هم دیده شده وقتی طول متغیر از سقف پترن بیشتر باشد.

مثال واقعی (پترن `super_admin` / `processing`):

| متغیر | سقف پترن مدیر | سقف پترن مشتری |
|--------|----------------|-----------------|
| `price` | **25** | 50 |
| `all_items` | 120 | 120 |

اگر `{price}` به‌صورت `wc_price` بلند درآید (مثلاً با «تومان» و جداکننده)، ارسال مدیر fail می‌شود ولی مشتری همان سفارش را می‌گیرد.

### اصلاح Medyar

1. mu-plugin: فیلتر `http_request_args` برای `/sms/pattern` روی `iranpayamak.com` — truncate ویژگی‌ها با سقف محافظه‌کارانه (`price`≤25 و …).
2. کیف پول: فرمت فشردهٔ مبلغ برای `{price}` در SMS.

اختیاری در پنل Faraz: در تنظیمات PWSMS → مدیر کل، پترن وضعیت‌ها را طوری ویرایش کنید که طول `price` مثل مشتری ۵۰ باشد.
