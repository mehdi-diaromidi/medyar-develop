# سیستم چت پزشکی (بیمار ↔ متخصص) — Medyar Chat Pro

این سند مرجع کامل ارتقای چت قالب `dr-plus` است: گزارش بررسی، معماری، فهرست فایل‌ها، نقاط اتصال به قالب و **دستورالعمل دقیق بازاعمال بعد از آپدیت قالب**.

> خلاصه مهم: هیچ فایلی از قالب `dr-plus` ویرایش **نشده است**. همه‌چیز در پلاگین اختصاصی
> `wp-content/plugins/medyar-chat-pro/` است و فقط از هوک‌ها، REST API و DOM قالب استفاده می‌کند.
> تنها فایل خارج از پلاگین: `wp-content/.htaccess` (مسدودسازی فایل‌های لاگ).
> بنابراین بعد از آپدیت قالب «بازاعمال پچ» لازم نیست؛ فقط باید **قرارداد قالب** (بخش ۶) بررسی شود.

---

## ۱) گزارش بررسی وضعیت قبلی

### ساختار چت قالب (نسخه 2.9.1.2)

| جزء | مسیر | توضیح |
|-----|------|-------|
| منطق اصلی | `inc/Utils/utils-chat.php` | کلاس `DrPlusUtilsChat` — **رمزنگاری‌شده** (قابل ویرایش نیست) |
| مدل‌ها | `inc/Models/ChatSession.php`، `ChatMessage.php` | جداول `{prefix}drplus_chat_sessions` و `{prefix}drplus_chat_messages` |
| REST | `inc/REST/ChatRoutes.php`، `ChatController.php`، `Permissions.php`، `RateLimiter.php` | فضای نام `drplus_chat/v1` |
| AJAX قدیمی | `inc/AJAX/Chat.php` | `drplus_chat_send_message` و … (فرانت از آن استفاده نمی‌کند) |
| قالب صفحه | `templates/chats/template-chats-single.php`، `template-chat-message.php`، `template-chats-list.php` | |
| JS | `assets/js/chat.js` (+ `.min`)، `chat-api.js` | |
| فایل‌ها | `wp-content/chat-uploads/` با `.htaccess: deny from all` | سرو از طریق `?chat_file=` |
| نقش‌ها | `user_1_id` = متخصص، `user_2_id` = بیمار | نمای ادمین: `admin.php?page=appointments&tab=view&book_id=…&show_chat=…` |

### امکانات موجود (واقعاً در کد)

- ارسال پیام متنی، فایل و ضبط/ارسال صدا (webm)، محدودیت دقیقه‌ای (۱۰ پیام / ۵ آپلود)
- هوک `drplus/chat/message_sent` (پیامک اطلاع‌رسانی به آن متصل است)
- بستن مکالمه توسط متخصص (لینک `close_nonce`) + تکمیل سفارش نوبت

### امکانات ناقص / مشکلات پیدا‌شده

| مورد | وضعیت قبلی |
|------|------------|
| پاسخ به پیام (Reply) | **در کد قالب و روی سرور وجود نداشت** (پیاده‌سازی شد) |
| ارسال تصویر | تصویر فقط به‌شکل «فایل + نام» نمایش داده می‌شد؛ پیش‌نمایش نداشت |
| ارسال پیام در مکالمه بسته / باز‌نشده | سمت سرور جلوگیری نمی‌شد |
| `file_url` پیام | از کلاینت پذیرفته می‌شد؛ امکان ارجاع به فایل کاربر دیگر |
| لغو آپلود | دکمه داشت ولی کار نمی‌کرد (`currentUploadXHR` هرگز مقدار نمی‌گرفت) |
| درصد پیشرفت آپلود | واقعی نبود (فقط ۰ و ۱۰۰) |
| تکرار پیام در تلاش مجدد | محافظت نداشت |
| قطعی شبکه | پیام از دست می‌رفت |
| پلیر صوت | تگ `<audio>` خام؛ webm مدت زمان نشان نمی‌داد |
| AJAX قدیمی چت | فعال بود و `sender_type` را از کلاینت می‌پذیرفت |
| پاسخ خطای REST | CDN سایت (WCDN) بدنه همه پاسخ‌های 4xx/5xx را با صفحه HTML خودش عوض می‌کند |
| **نشت اطلاعات** | `wp-content/chat-debug.log` و `wp-content/debug.log` به‌صورت عمومی قابل دانلود بودند (شامل متن پیام‌ها) |

---

## ۲) معماری و برنامه اجرایی (فازها و وابستگی‌ها)

```text
فاز ۱  هسته پلاگین: جداول، تنظیمات، کنترل دسترسی، ثبت رویداد، محدودیت نرخ
  └─ فاز ۲  پل امنیتی روی REST قالب (rest_dispatch_request / rest_request_after_callbacks)
       ├─ فاز ۳  فایل امن: اعتبارسنجی/اسکن آپلود + استریم با کنترل دسترسی (Range)
       ├─ فاز ۴  دامنه پزشکی: وضعیت/زمان پاسخ، یادداشت، ارجاع، پیگیری، پاسخ آماده، واکنش، پروفایل بیمار
       │    └─ فاز ۵  REST اختصاصی medyar-chat/v1
       │         └─ فاز ۶  کلاینت جدید چت (جایگزین chat.js قالب با همان handle) + CSS
       └─ فاز ۷  پنل مدیریت: فهرست/فیلتر/مرتب‌سازی، آمار متخصصان، جزئیات، تاریخچه، تنظیمات، خروجی ZIP
فاز ۸  استقرار، تست بیمار/متخصص/مدیر، مسدودسازی لاگ‌ها، مستندسازی
```

### تصمیم‌های کلیدی

- **پلاگین جدا به‌جای پچ قالب**: کلاس اصلی چت رمزنگاری‌شده است و آپدیت قالب هر پچی را پاک می‌کند.
- **جایگزینی اسکریپت با حفظ handle**: `drplus-chat` در صفحه تک‌چت همچنان ثبت می‌شود (پس `wp_localize_script('drplus-chat','drplusChat',…)` داخل قالب کار می‌کند) ولی `src` آن به `mcp-chat.js` پلاگین اشاره می‌کند. غیرفعال کردن پلاگین = بازگشت کامل به چت قالب.
- **ارسال پیام همیشه از مسیر قالب**: `Chat::send_message` + هوک `drplus/chat/message_sent` ⇒ پیامک اطلاع‌رسانی و منطق قالب دست‌نخورده می‌ماند.
- **`_envelope` در REST**: چون CDN بدنه خطاها را عوض می‌کند، کلاینت همه درخواست‌ها را با `?_envelope=1` می‌فرستد (همیشه 200، وضعیت واقعی داخل JSON).
- **بدون وابستگی جدید**: فقط jQuery / wp-util که قالب لود می‌کند.

---

## ۳) قابلیت‌های پیاده‌سازی‌شده

### پزشکی

| قابلیت | جزئیات | دسترسی (سمت سرور) |
|--------|--------|-------------------|
| پروفایل بیمار در چت | تب «مشخصات» برای متخصص **فقط تاریخچه ارتباط همان بیمار با خود او** (تعداد مکالمه/بسته/نوبت/لغو، اولین ارتباط، ارجاع‌ها) + علت مراجعه/نوع/وضعیت مکالمه فعلی؛ سن، تاریخ عضویت و تماس فقط برای `mcp_view_medical` (در بک‌اند هم ارسال نمی‌شود). تب‌های سوابق، مدارک، پرونده پزشکی، یادداشت‌ها | متخصصِ همان مکالمه، یا `mcp_view_medical`. متخصص فقط مکالمات/مدارکی را می‌بیند که خودش طرف آن بوده |
| پاسخ‌های آماده | پنل ⚡ کنار ورودی، فیلتر با «/»، مدیریت افزودن/ویرایش/حذف؛ پیش‌فرض‌ها از تنظیمات | فقط متخصص فعال (user meta `mcp_quick_replies`) |
| ارجاع | جستجوی متخصص فعال، علت ارجاع؛ پیام ارجاع **بدون هیچ پیامک/اعلانی** مستقیم در جدول پیام‌های قالب درج می‌شود (`MCP_Theme::insert_message_silently` — هوک `drplus/chat/message_sent` اجرا نمی‌شود) و در چت بیمار به‌صورت کارت نمایش داده می‌شود | فقط متخصصِ همان مکالمه، فقط مکالمه باز |
| پیگیری | تاریخ **شمسی** (date picker افزونه ووکامرس فارسی `pw-datepicker-js` / `$.fn.persianDatepicker`، تم `pdp-mcp` هم‌شکل `pdp-arp` افزونه همکاران) + ساعت + توضیح؛ REST پارامتر `date` شمسی و `time` را می‌پذیرد. در صفحه تک‌چت، datepicker قالب (`drplus-pdp` / `drplus-pd`) dequeue می‌شود چون همان نام jQuery plugin و global `persianDate` را تعریف می‌کند. تقویم داخل `.mcp-layer` منتقل و `position: fixed` می‌شود (حالت تمام‌صفحه قالب گره‌های سطح body را مخفی می‌کند). نشان «پیگیری سررسید» در فهرست و پنل | متخصص مکالمه یا مدیر |
| یادداشت خصوصی | ثبت/ویرایش/حذف نرم؛ هرگز به‌عنوان پیام ارسال نمی‌شود؛ یادداشت‌های متخصص درباره همان بیمار در مکالمات دیگر هم دیده می‌شود | نوشتن: فقط متخصص مکالمه؛ خواندن: نویسنده + `mcp_view_medical` |
| وضعیت مکالمه | باز / در انتظار پاسخ متخصص / در انتظار پاسخ بیمار / پاسخ داده‌شده / بسته‌شده — خودکار با هر پیام + تغییر دستی | تغییر: متخصص مکالمه یا مدیر؛ بازگشایی مکالمه بسته فقط مدیر |

قواعد خودکار وضعیت: پیام بیمار ⇐ «در انتظار پاسخ متخصص»؛ پیام متخصص ⇐ «پاسخ داده‌شده» (اگر وضعیت «باز» یا «در انتظار متخصص» بود). `is_closed` قالب همیشه بر وضعیت ما مقدم است. بستن از پلاگین دقیقاً منطق لینک بستن قالب را اجرا می‌کند (`is_closed=1`، `closed_at`، سفارش ⇐ completed).

### تجربه کاربری

- **ارسال با Enter**: Enter ارسال می‌کند، Shift+Enter خط جدید (حین تایپ IME فارسی/چینی دخالت نمی‌کند).
- **لینک‌ها**: آدرس‌های `http(s)://` و `www.` در متن پیام به لینک قابل کلیک تبدیل می‌شوند (فقط روی text node، `target=_blank`، `rel="noopener noreferrer nofollow ugc"`؛ پیام‌های قدیمی هم شامل می‌شود).
- **در حال نوشتن**: کلاینت هنگام تایپ هر ۴ ثانیه `POST sessions/{id}/typing` می‌فرستد (transient ده‌ثانیه‌ای `mcp_typing_{session}_{user}`)؛ طرف مقابل وضعیت را از پاسخ polling قالب (`mcp_typing`) می‌گیرد و «در حال نوشتن…» جای زیرعنوان هدر نمایش داده می‌شود. با ارسال پیام پاک می‌شود. تأخیر نمایش ≈ بازه polling (پیش‌فرض ۵ ثانیه).
- **پاسخ به پیام**: منوی «⋯» (دسکتاپ) / لمس طولانی (موبایل) ⇐ پاسخ؛ نوار نقل‌قول بالای ورودی؛ کلیک روی نقل‌قول ⇐ اسکرول و هایلایت پیام اصلی.
- **واکنش (ایموجی روی پیام)**: به درخواست کارفرما **حذف شد** — نه در منوی پیام نمایش داده می‌شود، نه واکنش‌های قبلی، و endpoint آن 403 می‌دهد؛ گزینه‌اش از تنظیمات هم برداشته شد. کد و جدول `mcp_reactions` برای بازگشت احتمالی باقی است: `add_filter( 'mcp_enable_reactions', '__return_true' );`.
- **منوی پیام** («⋯» / لمس طولانی): دکمه‌های «پاسخ» و «کپی متن» تمام‌عرض و راست‌چین.
- **ضبط صدا**: ماشین حالت `idle → starting (انتظار مجوز میکروفون) → recording → idle` با `token`؛ «حذف» (سطل) در هر حالتی — حتی حین پنجره مجوز — میکروفون را فوراً آزاد و صدای ضبط‌شده را دور می‌ریزد؛ در حالت ضبط کل دایره کنار ورودی دکمه حذف است و آیکون پیوست پنهان می‌شود.
- **پلیر صوتی** (عرض ۲۸۰px / موبایل ۲۳۰px، هرگز بیشتر از حباب پیام — جلوگیری از بیرون‌زدن دکمه پخش در موبایل): Waveform واقعی (Web Audio)، پخش/توقف، 1x/1.5x/2x، جابه‌جایی با کلیک/درگ و کلیدهای ←/→ (۵ ثانیه)، Home/End، فقط یک صوت هم‌زمان. مدت WebM بدون متادیتا با `decodeAudioData` قفل می‌شود (نه seek به `1e101` که ثانیه‌ها را هنگام پخش زیاد می‌کرد)؛ برای وویس تازه‌ضبط‌شده مدت اندازه‌گیری‌شدهٔ ضبط هم پاس داده می‌شود.
- **پیش‌نمایش فایل**: تصویر بندانگشتی ⇐ نمایش تمام‌صفحه با زوم (دکمه، چرخ ماوس، دابل‌کلیک، pinch) و جابه‌جایی؛ PDF داخل نمایشگر (در مرورگر بدون نمایشگر PDF، تب جدید)؛ ویدیو با پلیر؛ سایر فایل‌ها کارت با آیکون، حجم، پسوند و دانلود.
- **مدیریت آپلود**: پیش‌نمایش پیش از ارسال، درصد واقعی پیشرفت، لغو، تلاش مجدد (اگر آپلود موفق و ثبت پیام ناموفق بود فقط ثبت پیام تکرار می‌شود)، پیام‌های خطای دقیق، چسباندن تصویر از کلیپ‌بورد.
- **قطعی شبکه**: صف خروجی در `localStorage` (`mcp_outbox_{session}_{user}`)، نوار «اتصال برقرار نیست»، ارسال خودکار با برقراری اتصال و backoff، دکمه تلاش مجدد/حذف.
- **هشدار اورژانس**: به درخواست کارفرما **پیش‌فرض نمایش داده نمی‌شود** (`emergency_notice` خالی). با وارد کردن متن در تنظیمات، نوار زیر هدر برمی‌گردد.
- **هشدار پایان مشاوره (T-5 دقیقه)**: از `drplus_booking.end_time` (از طریق `context_id`). بیمار بنر بالای صفحه می‌بیند؛ متخصص بنر + دکمه‌های تمدید رایگان ۵/۱۰/۱۵/۲۰ دقیقه یا «مایل نیستم». تمدید فقط `end_time` را جابه‌جا می‌کند (بدون هزینه)، پیام سیستمی برای بیمار می‌فرستد و بنر اطلاع تمدید نشان می‌دهد. در سررسید، کرون دقیقه‌ای / هر poll مکالمه را می‌بندد (`MCP_Timing`). نکات: ستون `date` نوبت ممکن است شمسی ذخیره شود (`1405-08-11`) و با `MCP_Jalali` به میلادی تبدیل می‌شود؛ زمان‌ها با `wp_timezone()` و timestamp واقعی مقایسه می‌شوند؛ نوبت‌هایی که قبل از فعال‌شدن قابلیت (`mcp_timing_since`) تمام شده‌اند legacy هستند و خودکار بسته نمی‌شوند.
- **منوی هدر** (`.chat-header-action-list`): `z-index: 1000` بالای پیام‌ها و راست‌چین.
- **نشان وضعیت** (`.mcp-status`): در همان ردیف نام کاربر و سمت چپ آن؛ JS نام (`.chat-header-other-user-name`) را داخل `.mcp-name-row` (flex + `space-between`) می‌پیچد. «در حال نوشتن» با سه نقطه سمت چپ متن جای زیرعنوان می‌نشیند.
- متن‌های وضعیت ارسال («در حال ارسال…»، «در حال آپلود…») با `unicode-bidi: isolate` راست‌به‌چپ می‌مانند تا «…» سمت چپ متن بیفتد.
- **سرعت ارسال**:
  - تصویر/فایل/صوت در **یک درخواست** (`POST medyar-chat/v1/sessions/{id}/attachments`) آپلود و ثبت می‌شود (قالب دو درخواست جدا داشت).
  - عکس‌های بزرگ‌تر از ۴۰۰KB قبل از آپلود در مرورگر به حداکثر ۱۹۲۰px و JPEG ۸۵٪ کوچک می‌شوند (معمولاً ۵ تا ۱۰ برابر کم‌حجم‌تر؛ متادیتای EXIF/GPS هم حذف می‌شود).
  - شنونده‌های پیامک روی `drplus/chat/message_sent` در درخواست ارسال **بعد از برگشت پاسخ** اجرا می‌شوند (`MCP_Bridge::defer_notifications` + `litespeed_finish_request`/`fastcgi_finish_request`)؛ پیامک همچنان ارسال می‌شود ولی فرستنده منتظرش نمی‌ماند. خاموش‌کردن: فیلتر `mcp_defer_chat_notifications` ⇐ `false`.
  - تصویر و صوتِ تازه ارسال‌شده از نسخه محلی مرورگر نمایش داده می‌شود (بدون دانلود دوباره).
- **فهرست گفت‌وگوها**: نشان وضعیت + زمان انتظار + پیگیری روی کارت‌ها.
- دسترس‌پذیری: نقش‌ها و برچسب‌های ARIA، فوکوس قابل مشاهده، تله فوکوس در دیالوگ/کشو، Esc، `prefers-reduced-motion`، اهداف لمسی ≥ ۴۰–۴۴px، RTL.

### امنیت و پایداری

| # | مورد | پیاده‌سازی |
|---|------|-----------|
| 1 | مالکیت مکالمه | `permission_callback` روی همه مسیرهای `medyar-chat/v1` (`MCP_Access`)؛ تست شده: متخصص دیگر ⇐ 403 |
| 2 | فایل‌های پزشکی | `?mcp_file={message_id}` فقط برای طرفین یا مدیر؛ `no-store`، `nosniff`، CSP sandbox، پشتیبانی Range؛ دسترسی مدیر و تلاش غیرمجاز ثبت می‌شود. پوشه `chat-uploads` همچنان `deny from all` |
| 3 | اعتبارسنجی آپلود | لیست سفید پسوند ⇐ MIME واقعی (finfo) باید با پسوند بخواند، نوع باید در تنظیمات قالب مجاز باشد، حداقل(تنظیم پلاگین، تنظیم قالب) حجم، رد پسوند دوگانه خطرناک |
| 4 | اسکن | ClamAV (`clamdscan`/`clamscan`) در صورت وجود + بررسی محتوایی: MZ/ELF/shebang، تگ PHP/اسکریپت، `getimagesize` برای تصاویر، PDF فعال (`/JavaScript`، `/Launch`، `/EmbeddedFile`)، محتوای ZIP؛ فیلتر `mcp_scan_file` برای اسکنر خارجی |
| 5 | تاریخچه عملیات | جدول `mcp_audit` (تغییر وضعیت، بستن/بازگشایی، یادداشت، ارجاع، پیگیری، مشاهده پروفایل، مشاهده متن توسط مدیر، خروجی، رد آپلود/پیام، محدودیت نرخ، تنظیمات) |
| 6 | پیام تکراری | `client_uid` یکتا (کلید یکتای `sender_id+client_uid`)؛ درخواست هم‌زمان ⇐ 409 `mcp_in_flight`، تکرار ⇐ همان پیام قبلی |
| 7 | اسپم | محدودیت دقیقه‌ای قالب + سقف ساعتی پیام + سهمیه حجمی روزانه آپلود + محدودیت هر endpoint پلاگین |
| 8 | بکاپ | خروجی ZIP هر مکالمه (متن JSON/HTML، فایل‌ها، یادداشت‌ها، ارجاع‌ها، رویدادها)؛ یادداشت‌ها حذف نرم؛ فهرست جداول/پوشه در بخش ۸ |
| 9 | شبکه | صف آفلاین + تلاش مجدد idempotent + `_envelope` برای عبور از CDN |
| 10 | تفکیک نقش | بیمار / متخصص (طرفین جلسه) / `mcp_manage_chats` (نظارت) / `mcp_view_medical` (پرونده کامل) — همه در بک‌اند |
| — | مسیر قدیمی AJAX | `drplus_chat_send_message` و `drplus_chat_upload_file` با 410 بسته شدند (فیلتر `mcp_block_legacy_chat_ajax`) |
| — | مکالمه بسته/باز‌نشده | ارسال پیام/آپلود/واکنش ⇐ 403 `mcp_chat_closed` |
| — | `file_url` | باید فایل خود فرستنده (`chat_{uid}_*`) باشد و قبلاً در پیام دیگری استفاده نشده باشد |
| — | لاگ‌ها | `wp-content/.htaccess` ⇐ همه `*.log` ممنوع (403) |

### پنل مدیریت (`wp-admin ← گفت‌وگوهای پزشکی`)

- کارت‌های وضعیت (کلیک = فیلتر)، میانگین زمان پاسخ، میانگین اولین پاسخ، طولانی‌ترین انتظار فعلی، پیگیری‌های سررسیده
- جدول عملکرد متخصصان (کل/فعال/بسته/منتظر پاسخ/میانگین پاسخ/بیشترین انتظار)
- همه ورودی‌های تاریخ پنل (فیلتر مکالمات، تاریخچه فعالیت‌ها، پیگیری در جزئیات مکالمه) **شمسی و متنی** هستند، هم‌شکل فیلتر افزونه همکاران (`arp-date-input`): `<input type="text" class="mcp-date-input" inputmode="numeric" dir="ltr" placeholder="از ۱۴۰۵/۰۱/۰۱">`، عرض ۱۳۰px؛ ارقام فارسی/لاتین پذیرفته و در سرور با `MCP_Jalali::to_gregorian()` به میلادی تبدیل می‌شود (پارامترهای URL `from`/`to` شمسی می‌مانند). ساعت پیگیری فیلد متنی `HH:MM` (پیش‌فرض ۰۹:۰۰).
- فهرست مکالمات با فیلتر وضعیت، متخصص، بازه تاریخ، پیگیری سررسیده، جستجو و مرتب‌سازی (شناسه، ایجاد، آخرین فعالیت، پیام‌ها، انتظار، میانگین پاسخ، وضعیت)
- جزئیات مکالمه: تغییر وضعیت، پیگیری، یادداشت‌ها (فقط خواندن)، ارجاع‌ها، رویدادها، لینک متن مکالمه (نمای قالب)، خروجی ZIP، بازمحاسبه آمار
- تب‌های «ارجاع‌ها»، «تاریخچه فعالیت‌ها» (فیلتر رویداد/مکالمه/کاربر/تاریخ) و «تنظیمات»

---

## ۴) فهرست فایل‌ها

```text
wp-content/plugins/medyar-chat-pro/
  medyar-chat-pro.php                     بوت‌استرپ (Version 1.0.0، DB 1)
  includes/class-mcp-settings.php         تنظیمات (option: mcp_settings)
  includes/class-mcp-install.php          جداول + capها (mcp_db_version)
  includes/class-mcp-theme.php            ★ تنها نقطه اتصال به قالب (جداول، Chat::send_message، بستن، مسیرها)
  includes/class-mcp-access.php           قواعد دسترسی
  includes/class-mcp-audit.php            تاریخچه عملیات
  includes/class-mcp-rate-limiter.php     سقف ساعتی/روزانه
  includes/class-mcp-sessions.php         وضعیت، زمان پاسخ، پیگیری، گزارش‌ها
  includes/class-mcp-timing.php           هشدار ۵دقیقه‌ای، تمدید رایگان، بستن خودکار در end_time
  includes/class-mcp-messages.php         reply/idempotency/واکنش/اطلاعات فایل
  includes/class-mcp-files.php            اعتبارسنجی/اسکن آپلود + استریم امن
  includes/class-mcp-notes.php            یادداشت خصوصی
  includes/class-mcp-referrals.php        ارجاع
  includes/class-mcp-quick-replies.php    پاسخ آماده
  includes/class-mcp-patient-profile.php  پروفایل بیمار
  includes/class-mcp-bridge.php           ★ هوک روی REST قالب (drplus_chat/v1) + بستن AJAX قدیمی
  includes/class-mcp-rest.php             REST اختصاصی medyar-chat/v1
  includes/class-mcp-frontend.php         ★ جایگزینی src اسکریپت drplus-chat + کانفیگ
  includes/class-mcp-plugin.php           سیم‌کشی هوک‌ها + کرون روزانه (mcp_daily_maintenance)
  includes/class-mcp-jalali.php           تبدیل شمسی/میلادی برای ورودی‌های تاریخ پنل
  includes/admin/class-mcp-admin.php      پنل مدیریت (MCP_Admin::date_input)
  includes/admin/class-mcp-export.php     خروجی ZIP
  includes/admin/views/*.php              نماها
  assets/js/mcp-chat.js                   ★ کلاینت صفحه چت (جایگزین chat.js قالب)
  assets/js/mcp-chat-list.js              نشان‌های فهرست گفت‌وگوها
  assets/css/mcp-chat.css                 استایل (از توکن‌های قالب استفاده می‌کند)
  assets/css/mcp-admin.css

wp-content/.htaccess                      مسدودسازی *.log (خارج از پلاگین)
```

### جداول جدید (فقط افزودنی؛ جداول قالب تغییری نکرده‌اند)

| جدول | کاربرد |
|------|--------|
| `{prefix}mcp_session_meta` | وضعیت، زمان انتظار/پاسخ، پیگیری (کلید: `session_id`) |
| `{prefix}mcp_message_meta` | `reply_to`، `client_uid`، نوع سیستمی (ارجاع)، کش اطلاعات فایل |
| `{prefix}mcp_reactions` | واکنش‌ها (یکتا: پیام + کاربر) |
| `{prefix}mcp_notes` | یادداشت خصوصی (حذف نرم) |
| `{prefix}mcp_referrals` | ارجاع‌ها |
| `{prefix}mcp_audit` | تاریخچه عملیات |

Capها (روی administrator): `mcp_manage_chats`، `mcp_view_medical`.

### REST — `/wp-json/medyar-chat/v1/`

| مسیر | متد | دسترسی |
|------|-----|--------|
| `sessions/{id}/context[?with_meta=1]` | GET | طرفین یا مدیر |
| `sessions/{id}/meta[?ids=]` | GET | طرفین |
| `sessions/{id}/typing` | POST | طرفین |
| `sessions/{id}/attachments` | POST (multipart: `file`، `type`=file\|voice، `client_uid`، `reply_to`) | طرفین، مکالمه باز؛ محدودیت ۵/دقیقه قالب + سقف ساعتی + اعتبارسنجی کامل فایل |
| `sessions/{id}/status` | POST | متخصص مکالمه / مدیر |
| `sessions/{id}/follow-up` | POST | متخصص مکالمه / مدیر |
| `sessions/{id}/profile` | GET | متخصص مکالمه / `mcp_view_medical` |
| `sessions/{id}/notes` , `notes/{note_id}` | GET/POST/PUT/DELETE | خواندن: متخصص / `mcp_view_medical`؛ نوشتن: متخصص |
| `sessions/{id}/referrals` | POST | متخصص مکالمه |
| `specialists?search=` | GET | کاربر متخصص |
| `messages/{id}/reactions` | POST | طرفین |
| `quick-replies`، `quick-replies/{qid}`، `quick-replies/order` | GET/POST/PUT/DELETE | کاربر متخصص |
| `sessions/summary?ids=` | GET | فقط مکالمات خود کاربر |

افزوده‌ها روی REST قالب: پارامترهای `client_uid` و `reply_to` در `POST conversations/{id}/messages`؛ فیلد `mcp` روی هر پیام و `mcp_state` در پاسخ‌ها.

---

## ۵) استقرار و فعال‌سازی

1. کل پوشه `wp-content/plugins/medyar-chat-pro/` را آپلود کنید.
2. افزونه «Medyar Chat Pro» را فعال کنید ⇐ جداول ساخته و آمار مکالمات قبلی **بازسازی** می‌شود.
3. `wp-content/.htaccess` را آپلود کنید (بخش ۳).
4. کش WP Rocket و CDN را پاک کنید.

نسخه asset ها `MCP_VERSION.filemtime` است تا کش لبه CDN با هر آپلود شکسته شود.

---

## ۶) بعد از آپدیت قالب dr-plus — چه چیزی را چک کنیم؟

پچ مستقیمی روی قالب نداریم؛ اما پلاگین به این **قرارداد قالب** تکیه دارد. بعد از آپدیت، هر مورد را جستجو و تأیید کنید:

| # | قرارداد | محل در قالب | علامت جستجو | اگر تغییر کرد |
|---|---------|-------------|-------------|----------------|
| 1 | handle اسکریپت تک‌چت و لوکالایز | `inc/Scripts.php` ، `templates/chats/template-chats-single.php` | `'drplus-chat'` ، `wp_localize_script( 'drplus-chat', 'drplusChat'` | `MCP_Frontend::enqueue()` — نام handle را به‌روز کنید |
| 2 | کلیدهای `drplusChat` | `template-chats-single.php` | `chatID`، `userID`، `restNonce`، `lastMessageID`، `chatStatus`، `ajaxCheckMessage`، `ajaxCheckMessageInterval`، `allowedFileTypes`، `recordVoice`، `siteUrl`، `i18n` | `mcp-chat.js` بالای فایل (`var D = drplusChat`) |
| 3 | DOM صفحه چت | `template-chats-single.php`، `template-chat-message.php` | `.chat-content`، `.chat-header`، `.chat-header-other-user-info`، `.chat-header-action-wrap/-icon/-list`، `.chat-close-session`، `.chat-messages`، `.chat-message[data-message-id]`، `.current-user-message`/`.other-user-message`، `.chat-message-content`، `.chat-message-text`، `.chat-message-file[data-url]`، `audio.chat-message-audio`، `.chat-message-date`، `.chat-send-container`، `#chat-send-input`، `#chat-send-btn`، `#chat-send-attachment(-btn)`، `#chat-record-voice-btn`، `#chat-send-voice-btn`، `#chat-cancel-record-voice-btn`، `.chat-send-recording-voice-time`، `.chat-new-messages-notif`، `.chat-closed-wrap` | سلکتورهای `mcp-chat.js` / `mcp-chat.css` |
| 4 | REST قالب | `inc/REST/ChatRoutes.php` | `drplus_chat/v1`، `/conversations/(?P<id>\d+)/messages`، `/upload`، `/messages/mark-seen` | `MCP_Bridge::kind()` و `themeApi()` در JS |
| 5 | شکل پاسخ REST | `inc/REST/ChatController.php` | `'success' => true, 'data' => [ 'id' …` و `data.messages[]` | `MCP_Bridge::after_send/after_list` |
| 6 | هوک ارسال | `ChatController::send_message` | `do_action( 'drplus/chat/message_sent'` | `MCP_Bridge::init()` (وضعیت/زمان پاسخ) و `MCP_Bridge::defer_notifications()` (اجرای پیامک بعد از پاسخ). اگر پیامک چت به هوک دیگری منتقل شد، ارجاع بی‌صدا هنوز امن است چون اصلاً از مسیر قالب نمی‌رود |
| 7 | متدهای کلاس Chat | `inc/Utils/utils-chat.php` (رمزنگاری) | `send_message`، `upload_file( $file, $user_id )` (برگشت URL یا WP_Error)، `allowed_file_types` | `MCP_Theme::chat_class()` و `MCP_REST::attachment()` |
| 8 | جداول و ستون‌ها | `inc/Models/ChatSession.php`، `ChatMessage.php` | `user_1_id` (متخصص)، `user_2_id` (بیمار)، `context_id`، `is_closed`، `open_at`، `closed_at`؛ `session_id`، `sender_id`، `message`، `type`، `file_url`، `created_at` | `MCP_Theme` |
| 9 | منطق بستن | `template-chats-single.php` بلوک `close_nonce` | `$chat->is_closed = 1` و `update_status( 'completed' )` | `MCP_Theme::close_session()` را هم‌سان کنید |
| 10 | مسیر فایل‌ها | `utils-chat.php` (رمزنگاری) | فایل‌ها در `wp-content/chat-uploads/chat_{uid}_*.ext` | `MCP_Theme::uploads_dir()` و `file_path_from_url()`؛ قاعده مالکیت در `MCP_Files::validate_message_file_url()` |
| 11 | endpointهای حساب | `woocommerce/myaccount/custom-links/chats.php`، `specialist-dashboard/specialist-chats.php` | query var `chats` و `specialist-dashboard` = `specialist-chats/{id}` | `MCP_Frontend::page()` و `MCP_Theme::chat_url()` |
| 12 | نمای ادمین متن مکالمه | `inc/Backend/Appointments/class-appointments-view.php` | `show_chat`، `page=appointments&tab=view` | `MCP_Theme::admin_transcript_url()` و `MCP_Admin::log_transcript_view()` |
| 13 | AJAX قدیمی | `inc/AJAX.php` | `'chat_send_message'`، `'chat_upload_file'` (پیشوند `drplus_`) | `MCP_Bridge::block_legacy_ajax()` |
| 14 | حالت تمام‌صفحه | `assets/css/wc/my-account.min.css` | `html:has(.drplus-myaccount-section-chats .chat-content.fullscreen) *:not(...)` | همه لایه‌های شناور داخل `.chat-content > .mcp-layer` ساخته می‌شوند؛ اگر کلاس‌ها عوض شد این قاعده را بررسی کنید |
| 15 | توکن‌های طراحی | CSS قالب | `--primary-100`، `--primary-100-20`، `--general-bg`، `--general-text`، `--gray-100/200`، `--text-main/400/500`، `--red`، `--red-20`، `--global-border-radius-2`، `--btn-small-border-radius`، آیکون‌های `drplus-icon-*` | `mcp-chat.css` |

### تست سریع بعد از آپدیت (۵ دقیقه)

1. صفحه تک‌چت: در سورس باید `plugins/medyar-chat-pro/assets/js/mcp-chat.js` لود شود (نه `chat.min.js`).
2. نشان وضعیت، پلیر صوتی، پیش‌نمایش تصویر و لینک‌شدن URLها دیده شود؛ Enter ارسال و Shift+Enter خط جدید.
3. ارسال پیام ⇐ وضعیت به «در انتظار پاسخ متخصص» برود (تست هوک ۶).
4. متخصص: آیکون پرونده بیمار، ⚡ پاسخ آماده، منوی ارجاع/پیگیری/یادداشت.
5. پنل `گفت‌وگوهای پزشکی` بدون خطا باز شود.
6. `curl -I https://medyarco.com/wp-content/debug.log?x=1` ⇐ 403.

اگر قالب ساختار را شکست و فرصت اصلاح نیست: افزونه را **غیرفعال** کنید ⇐ چت قالب بدون تغییر برمی‌گردد (داده‌های پلاگین حذف نمی‌شود).

---

## ۷) تنظیمات (`گفت‌وگوهای پزشکی ← تنظیمات`)

| کلید | پیش‌فرض | توضیح |
|------|---------|-------|
| `emergency_notice` | خالی (عدم نمایش) | با وارد کردن متن، نوار هشدار زیر هدر نمایش داده می‌شود |
| `default_quick_replies` | ۴ نمونه درخواست‌شده | فقط برای متخصصانی که فهرست شخصی ندارند |
| `share_medical_summary` | فعال | خلاصه پرونده پزشکی برای متخصص مکالمه |
| `max_file_mb` | 20 | حداقلِ این و تنظیم قالب (`chat-file-upload-max-size`) |
| `daily_upload_mb` | 200 | سهمیه روزانه هر کاربر |
| `messages_per_hour` | 120 | علاوه بر ۱۰ در دقیقه قالب |
| `scan_mode` | auto | auto / clamav (الزامی) / heuristic |
| `audit_retention_days` | 0 | ۰ = دائمی؛ پاک‌سازی با کرون روزانه |

---

## ۸) بکاپ و بازیابی

- بکاپ کامل (Duplicator) باید شامل جداول `drplus_chat_*` و `mcp_*` و پوشه `wp-content/chat-uploads/` باشد.
- خروجی تکی: جزئیات مکالمه ⇐ «خروجی کامل (ZIP)»: `conversation.json` + `transcript.html` + `files/`.
- بازیابی یادداشت حذف‌شده: `UPDATE {prefix}mcp_notes SET deleted_at = NULL WHERE id = …`.
- بازسازی آمار زمان پاسخ: دکمه «بازمحاسبه آمار همه مکالمات» (وضعیت‌های دستی حفظ می‌شوند).

---

## ۹) موارد باز / نیازمند اقدام شما

1. **پاک‌کردن کش CDN (فوری)**: نسخه‌های قبلی `https://medyarco.com/wp-content/chat-debug.log` و `/wp-content/debug.log` در کش WCDN مانده‌اند (`wcdn-status: Hit`) و هنوز از آدرس بدون پارامتر قابل دانلودند. Origin اکنون 403 می‌دهد؛ این دو URL را در پنل CDN Purge کنید.
2. mu-plugin موقت `wp-content/mu-plugins/drplus-chat-debug.php` (خودش نوشته «Delete this file when debugging is done») و فایل `wp-content/chat-debug.log` روی سرور هستند؛ حذفشان با تأیید شما انجام شود.
3. ClamAV روی هاست در دسترس نیست ⇐ حالت auto فعلاً فقط بررسی محتوایی انجام می‌دهد. برای اسکن کامل، ClamAV هاست یا فیلتر `mcp_scan_file` (سرویس ابری) را فعال کنید.
4. سرعت REST کل سایت ۳–۵ ثانیه است (حتی `wp/v2/users/me`)؛ ربطی به چت ندارد ولی تجربه چت را کند می‌کند (بررسی Query Monitor / کش شیء پیشنهاد می‌شود).
5. باگ قالب (دست‌نخورده): `GET drplus_chat/v1/conversations` به‌خاطر `validate_positive_int` روی پارامتر `type` همیشه 400 می‌دهد.

---

## نسخه سند

| فیلد | مقدار |
|------|--------|
| آخرین به‌روزرسانی | 2026-10-31 (۱۴۰۵/۰۸/۰۹) — دور دوم: Enter، لینک، در حال نوشتن، پروفایل محدود، حذف هشدار، منوی هدر، ارجاع بی‌پیامک، ارسال یک‌مرحله‌ای/فشرده‌سازی/پیامک پس از پاسخ |
| medyar-chat-pro | **1.0.0** (DB 1) |
| قالب تست‌شده | dr-plus 2.9.1.2 |
| تست | بیمار (مکالمه ۱۲)، متخصص (user-switching به کاربر ۹۰)، مدیر؛ دسکتاپ و موبایل ۳۹۰px |
