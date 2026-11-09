# Affiliate Referral — مرجع کامل UI/UX

سند اجرایی وضعیت موجود پلاگین `affiliate-referral` برای بازطراحی و پیاده‌سازی بعدی.  
RTL · فارسی · تم Dr Plus · WP Admin استاندارد.

---

## فهرست

1. [محصول و نقش‌ها](#1-محصول-و-نقشها)
2. [نقشه صفحات و ناوبری](#2-نقشه-صفحات-و-ناوبری)
3. [توکن‌های بصری (رنگ، تایپ، فاصله، شعاع، سایه، موشن)](#3-توکنهای-بصری)
4. [شِل پنل همکار و ناوبری](#4-شل-پنل-همکار-و-ناوبری)
5. [سیستم فرم و رفتار اینپوت‌ها](#5-سیستم-فرم-و-رفتار-اینپوتها)
6. [Custom Select](#6-custom-select)
7. [Date picker شمسی](#7-date-picker-شمسی)
8. [جداول، فیلتر، صفحه‌بندی، AJAX لیست](#8-جداول-فیلتر-صفحهبندی-ajax-لیست)
9. [دکمه‌ها، نوتیس، وضعیت‌ها، KPI، نمودار](#9-دکمهها-نوتیس-وضعیتها-kpi-نمودار)
10. [کارت دعوت، کپی، اشتراک](#10-کارت-دعوت-کپی-اشتراک)
11. [صفحات فرانت صفحه به صفحه](#11-صفحات-فرانت-صفحه-به-صفحه)
12. [ادمین: ناوبری، کامپوننت‌ها، صفحات](#12-ادمین)
13. [حالت‌های سیستم و دسترس‌پذیری](#13-حالتهای-سیستم-و-دسترسپذیری)
14. [نقشه فایل](#14-نقشه-فایل)
15. [پیوست A — پالت کامل وضعیت و پورسانت](#15-پیوست-a--پالت-کامل-وضعیت-و-پورسانت)
16. [پیوست B — موجودی فیلد به فیلد هر فرم](#16-پیوست-b--موجودی-فیلد-به-فیلد-هر-فرم)
17. [پیوست C — ستون جداول و empty copy](#17-پیوست-c--ستون-جداول-و-empty-copy)
18. [پیوست D — قوانین تاریخ و صفحه‌بندی](#18-پیوست-d--قوانین-تاریخ-و-صفحهبندی)
19. [پیوست E — چک‌لیست بازپیاده‌سازی](#19-پیوست-e--چکلیست-بازپیادهسازی)

---

## 1. محصول و نقش‌ها

| نقش | سطح UI | هدف اصلی |
| --- | --- | --- |
| متقاضی / همکار | فرانت اختصاصی + My Account | عضویت، معرفی بیمار، پیگیری، پورسانت، دعوت، پروفایل، برداشت |
| ادمین عملیات | `wp-admin` منوی «همکاران مدیار» | تأیید درخواست، صف تماس، وضعیت معرفی، مالی، SMS، تعرفه |

فرض‌ها: OTP سایت · نقش `affiliate` فقط بعد از approve · پورسانت فقط کیف پول تومان مدیار · بدون dark mode.

---

## 2. نقشه صفحات و ناوبری

### 2.1 جریان ورود کاربر

```
هدر سایت: «ورود طرح همکاران» / «ورود / عضویت…»
  → OTP
  → صفحه ثبت‌نام (template: arp-signup.php)
       ├ pending → فقط پیام صف بررسی
       ├ rejected → پیام + فرم مجدد
       ├ already / approved → پنل
       └ form → ارسال → pending
  → صفحه پنل (template: arp-dashboard.php) ?arp_page=
```

### 2.2 ناوبری پنل همکار (سایدبار)

ترتیب ثابت در `layout.php`:

| # | slug / مقصد | برچسب | آیکون | رفتار |
| --- | --- | --- | --- | --- |
| 1 | `dashboard` | داشبورد | home SVG | داخلی پنل |
| 2 | `submit` | ثبت معرفی بیمار | user-plus | داخلی |
| 3 | `referrals` | معرفی‌های من | users | داخلی |
| 4 | `invited` | همکاران معرفی‌شده | drplus-icon-user-group | داخلی (بعد از referrals درج می‌شود) |
| 5 | `commissions` | پورسانت من | wallet | داخلی |
| 6 | URL کیف پول | برداشت کیف پول | banknote | **خروجی** → My Account withdrawal |
| 7 | `profile` | پروفایل من | user | داخلی |
| 8 | My Account | پنل مدیار | grid | **خروجی** → Woo my-account |
| 9 | logout | خروج از حساب | logout | `wp_logout_url(home)` · رنگ danger |

**قواعد ناوبری**
- آیتم داخلی: `Arp_Pages::page_url($slug)` · کلاس `is-active` + `aria-current="page"` وقتی `?arp_page` برابر slug است.
- آیتم خارجی: `url` ثابت · بدون `is-active` · همان تب (`target="_self"`).
- عنوان لینک = `title` attribute برای فشرده‌سازی احتمالی.
- فلش hover فقط روی غیرفعال‌ها (`arp-dash__nav-arrow`)؛ روی active مخفی.
- موبایل ≤767: سایدبار drawer از راست (RTL) · تب لبه صفحه بازکننده · Escape / backdrop / دکمه close می‌بندد.
- دسکتاپ ≥1024 + `data-arp-sticky`: سایدبار sticky با `--arp-sticky-top` زیر هدر Elementor/admin bar.

### 2.3 ناوبری ادمین

منوی ریشه **همکاران مدیار** (`dashicons-groups` · position 26) با bubble:
- اگر درخواست pending > 0 → bubble روی منو و زیرمنوی «درخواست‌ها»
- وگرنه اگر معرفی waiting > 0 → bubble روی «معرفی‌ها»

زیرمنوها به ترتیب: پیشخوان · درخواست‌ها · دعوت‌کننده‌ها · معرفی‌ها · همکاران · مالی و پورسانت · تنظیمات.

**Tabs مالی:** پورسانت‌ها | کیف پول | قوانین پورسانت  
**Tabs تنظیمات:** عمومی و پیامک | خدمات | وضعیت‌های معرفی | انواع شغل | ابزارها و خروجی  
کامپوننت: `nav-tab-wrapper` + `aria-current="page"` روی تب فعال · `Arp_Admin_Ui::tabs()`.

### 2.4 My Account

آیتم «همکاران معرفی‌شده» داخل منوی حساب تم؛ همان `page-invited.php` با کلاس `--myaccount` و `<h2>` به‌جای `<h1>`.

---

## 3. توکن‌های بصری

منبع: `assets/css/public.css` (`:root`) و `assets/css/admin.css`.

### 3.1 رنگ فرانت (پلاگین)

| نقش | Token / مقدار | کاربرد |
| --- | --- | --- |
| Primary | `--arp-primary` → `var(--primary-100, #0272b1)` | CTA، لینک، فوکوس فیلد، nav active، آیکون |
| Primary soft | `--arp-primary-soft` → `rgba(2,114,177,0.12)` | پس‌زمینه آیکون دایره، hover outline |
| Primary hover | `#015a8c` | دکمه primary hover/focus |
| Navy / KPI ink | `#114b5f` (`--metrics-ink`) | اعداد متریک |
| KPI ink soft | `#5a737c` | لیبل متریک |
| KPI accent | `#0d5c63` | hero metric + نوار عمودی |
| Surface metrics | `#fffcf9` / `#fff` | برد متریک |
| Line metrics | `#e2e6e9` | جداکننده‌های عمودی/افقی |
| Progress tone | `#a16207` | «در حال پیگیری» |
| Success tone | `#1a7a4c` / `#16a34a` | موفق، کپی‌شده |
| Success bg | `#dcfce7` / `#e8f7ee` | pill / notice |
| Danger | `#e11d48` / `#d60043` | خطا، حذف آواتار، reset فیلتر |
| Danger bg | `#ffe4e6` / `#fde8ec` | notice/invalid |
| Warn / followed | `#b7791f` / `#fff5e0` | وضعیت در جریان |
| Text | `--arp-text` `#383838` | بدنه |
| Heading | `--arp-heading` `#373434` | عناوین |
| Muted | `#a6a6a6` | ثانویه |
| Muted strong | `#747474` | لیبل/لید |
| Border | `#e6e6e6` | کارت/فیلد |
| BG page/shell | `#f7f7f7` | سایدبار خاکستری، main |
| Card | `#ffffff` | کارت |
| Input placeholder | `#a3a0a0` | |
| Table head | `#f6f9fc` | |
| Table hover | `#fafcfe` | |
| Field filled soft | `#eef3f7` | سلول‌های تاریخ در range |
| How/CTA tint | `#f3f9fd` / `#eef7fb` | کارت راهنما و بنر |
| Sidebar header | `var(--primary-100)` | آبی پر |
| Sidebar text on blue | `#fff` / `#f7f7f7` phone | |
| Logout | `var(--error-100, #d93a3a)` | |
| Backdrop drawer | `rgba(0,0,0,0.4)` | |
| Select option hover | `rgba(2,114,177,0.08)` | |
| Select selected | `rgba(2,114,177,0.12)` | |
| Friday in calendar | `#e11d48` | |

### 3.2 رنگ وضعیت معرفی (پیل فرانت)

| Modifier | bg | text |
| --- | --- | --- |
| `--waiting` | `rgba(2,114,177,0.1)` | `#0272b1` |
| `--progress` / `--followed` | `#fff5e0` | `#b7791f` |
| `--accepted` / `--done` | `#e8f7ee` | `#1a7a4c` |
| `--cancel` | `#fde8ec` | `#d60043` |
| `--failed` | `#f1f3f5` | `#6b7280` |
| `--dynamic` | color-mix 12% از `--arp-status-c` | mix 88% با سیاه |

ادمین badge: `--arp-c` روی `.arp-badge` با opacity 0.12 پس‌زمینه.  
پالت وضعیت ادمین (fallback tones): waiting `#0891B2` · progress `#0272B1` · accepted `#16A34A` · done `#0F766E` · cancel `#E11D48` · failed `#EA580C`.  
رنگ نهایی هر وضعیت از تنظیمات swatch (`Arp_Ui::STATUS_PALETTE`) می‌آید.

### 3.3 رنگ ادمین KPI

| Tone class | `--arp-tone` | `--arp-tint` |
| --- | --- | --- |
| (default blue) | `#2271b1` | `#e8f1f9` |
| `--orange` | `#b26200` | `#fcf0e3` |
| `--teal` | `#0f766e` | `#e6f4f2` |
| `--purple` | `#6b3fa0` | `#f1ebf8` |
| `--green` | `#00a32a` | `#e7f6ea` |

متن WP: `#1d2327` · muted `#646970` · border `#c3c4c7` / `#dcdcde` · success `#00a32a` · error `#d63638` / `#b32d2e`.

### 3.4 تایپوگرافی

| نقش | Size | Weight | LH | کلاس نمونه |
| --- | --- | --- | --- | --- |
| عنوان صفحه | 1.5rem (24) · ≤640: 1.25 · ≤767 refs head: 1.125 | 800 | 1.4–1.5 | `.arp-refs__title` |
| عنوان کارت/بخش | 1rem–1.125 | 800 | 1.5–2 | `.arp-card__title` |
| بدنه شِل | 1rem | 400 | 2 | `.arp-dash` |
| لیبل فیلد | 0.875rem | 400 | 1.5 | `.arp-field-block__label` |
| Lead | 0.875 · موبایل 0.8125 | 400 | 1.7 | `.arp-refs__lead` |
| Meta / hint | 0.75–0.8125 | 400–500 | 1.6 | `.arp-field-hint` |
| Metric hero num | 1.75rem · موبایل 1.5 | 800 | 1.1 | `.arp-metric--hero` |
| Metric money | 1.375 | 700 | | |
| Metric stat | 1.25 · موبایل 1.125 | 700 | | |
| Table cell | 0.875 | 400 | | `.arp-table` |
| Table th | 0.8125 | 500 | | |
| Status pill | 0.8125 | 600 | 1.6 | `.arp-status` |
| Nav item | 0.875 · active 1rem | 400 | 1.75 | |
| Role badge | 0.75 | 600 | | |
| Tracking code | 0.8125 Inter 500 | tnum | 1 | `.arp-comm__tracking` |
| Donut center | 1.5rem / 24 | 800 | | |
| Admin KPI value | 22px | 600 | | |
| Admin badge | 12px | 600 | 1.7 | |

فونت: `var(--main-font)` همه‌جا.  
جزایر لاتین: Inter خودمیزبان (`ARP Inter`) برای کد پیگیری · `ui-monospace` برای URL دعوت · Arial برای بعضی متا انگلیسی پروفایل · `dir="ltr"` برای موبایل/کد/URL/پترن.

### 3.5 فاصله و شبکه

- پایه 8pt (ریز 4).
- Container صفحه: `width: min(1280px, calc(100% - 32px))` · margin عمودی 24/40 · موبایل 24px افقی، 8/28 عمودی.
- Gap ستون داشبورد: 24 · gap بدنه: 22 · gap کارت‌ها/فیلتر: 14–20.
- سایدبار عرض ثابت **306px**.
- Padding main: 32 · ≤1023: 20 · ≤767: 12–16–20.
- Field control padding: 8×16 · min-height **50**.
- Breakpoints: **640 / 767 / 900 / 1023 / 1100 / 1200**.

### 3.6 شعاع و سایه

| عنصر | Radius |
| --- | --- |
| کارت عمومی | `--arp-radius` ≈ 16 (`--global-border-radius-2`) |
| شِل سایدبار/مین | 24 (`--global-border-radius-1`) |
| فرم/how card | 24 |
| فیلد / دکمه submit | 16 (`--field-border-radius` / `--btn-normal-border-radius`) |
| Outline btn | 15 · موبایل 12 |
| Pill / status / reset | 999 |
| Select menu / option | 16 / 12 |
| Datepicker panel | 14 · day cell 10 |
| Avatar sidebar | 16 · profile hero 50% |
| Row toggle | 10 |
| Pager btn | 10 |
| Admin KPI/postbox | 4 · badge 999 · icon 8 |

سایه: کارت‌ها معمولاً **بدون سایه**. موارد استثنا: select menu `0 8px 24px rgba(17,75,95,.08)` · datepicker `0 12px 32px rgba(17,75,95,.16)` · avatar `0 8px 24px rgba(2,114,177,.15)` · تب موبایل `0 0 12px` primary 36%.

### 3.7 موشن

| تعامل | Duration | Easing |
| --- | --- | --- |
| Border/background فیلد و دکمه | 150–200ms | ease / ease-in-out |
| Select option | 150ms | ease |
| Chevron select / row toggle | 150–200ms | ease (rotate 180°) |
| Drawer open | 420ms | `cubic-bezier(0.22, 1, 0.36, 1)` |
| Drawer close / backdrop hide | 280ms | `cubic-bezier(0.4, 0, 1, 1)` / ease |
| Spinner | 700ms linear infinite | |
| Badge shine | 2.6s ease-in-out infinite | |
| Tip کپی | 150ms opacity+translate | |
| `prefers-reduced-motion` | drawer ≈ 0.01ms · spinner 2s · toggle transition none | |

---

## 4. شِل پنل همکار و ناوبری

### 4.1 آناتومی `.arp-dash`

```
┌──────── 306px ────────┬────────────── 1fr ──────────────┐
│ Sidebar header (blue) │ Main (gray-100, radius 24)      │
│  badge · avatar ·     │  flash notice (optional)        │
│  name · phone · wallet│  page body (arp-dash-body / …)  │
│  icons                │                                 │
│ Sidebar body · nav    │                                 │
└───────────────────────┴─────────────────────────────────┘
```

- `dir="rtl"` روی `.arp-dash`.
- `data-arp-page="{slug}"` برای اسکریپت/استایل شرطی.
- Avatar سایدبار: `data-arp-avatar="side"` · همگام با پروفایل AJAX.
- Name/phone: `data-arp-bind="display_name|mobile_display"` · اگر phone خالی → `hidden`.
- Wallet فقط اگر پلاگین کیف پول موجود باشد.

### 4.2 وضعیت‌های آیتم منو

| State | ظاهر |
| --- | --- |
| Default | متن `#383838` · padding 8 0 · آیکون 18 |
| Hover / focus-within (غیر active) | bg `primary-100-20` · padding 8 16 · فلش visible |
| Active | bg primary · متن سفید · font-size 1rem · بدون فلش |
| Logout hover | bg `rgba(217,58,58,0.1)` |

### 4.3 موبایل drawer (≤767)

1. دکمه لبه `.arp-dash__sidebar-mobile-expand`: fixed · ارتفاع 72 · primary · `aria-label` باز کردن · `aria-expanded`.
2. باز: کلاس `mobile-expanded` روی sidebar · backdrop `is-visible` · `html.arp-drawer-open` قفل اسکرول · focus به دکمه close.
3. بستن: close / backdrop / Escape / خروج از breakpoint موبایل · focus برمی‌گردد به expand اگر فوکوس داخل drawer بود.
4. Expand به `document.body` منتقل می‌شود تا `position:fixed` نشکند.
5. اگر `scrollbar-gutter: stable` پشتیبانی نشود، `padding-inline-end` معادل عرض اسکرول‌بار روی body.

### 4.4 Sticky دسکتاپ

فقط وقتی ارتفاع سایدبار + offset زیر هدر در viewport جا شود و main بلندتر از سایدبار باشد → `.is-sticky`.  
Offset از stacking admin bar + Elementor sticky header محاسبه و در `--arp-sticky-top` نوشته می‌شود.

---

## 5. سیستم فرم و رفتار اینپوت‌ها

### 5.1 بلوک فیلد `.arp-field-block`

```
[label]  متن  [*]اختیاری(arp-req)
[control] icon? + input/select/textarea + chevron?/action?
[hint/help/counter]
```

- کل بلوک ترجیحاً داخل `<label>` تا کلیک لیبل فوکوس بدهد.
- Control: flex · min-height 50 · padding 8 16 · border 1 `#e6e6e6` · radius 16 · bg سفید.
- آیکون داخل کنترل: 18×18 · رنگ primary · `pointer-events: none`.
- Transition border/background 200ms.

### 5.2 حالت‌های بصری فیلد

| State | رفتار CSS/JS |
| --- | --- |
| Default | border gray-200 |
| Focus-within / filled (non-empty text) / `.is-filled` | border primary |
| Invalid `.is-invalid` | border `#d60043` · bg `#fff7f9` · لیبل قرمز · `aria-invalid="true"` روی کنترل |
| Readonly locked (inviter) | `pointer-events: none` روی control · متن placeholder-color · `tabindex="-1"` · `aria-readonly` |
| Disabled submit | opacity 0.6 · `cursor: not-allowed` |
| Loading submit | متن transparent · اسپینر سفید وسط · `pointer-events: none` · `aria-busy` روی form |

**نکته a11y:** روی خود `input` داخل control، `outline: none !important` است؛ نشانه فوکوس عملاً border کنترل والد است. در بازطراحی باید focus-visible ring جدا (≥3:1) اضافه شود.

### 5.3 تایپ‌های اینپوت و قوانین رفتاری

#### متن معمولی (`patient_name`, `full_name`, …)
- `maxlength` معمولاً 191.
- Placeholder نمونه فارسی («مثال: علی محمدی»).
- خالی کردن / تایپ → اگر بلوک invalid بود، با `input` کلاس invalid برداشته می‌شود (فرم‌های AJAX).

#### موبایل ایران
سلکتور: `input[data-arp-iran-mobile]` و `input[name="patient_phone"]`.

رفتار زنده روی `input`:
1. ارقام فارسی/عربی → لاتین.
2. غیررقم حذف.
3. پیشوند `0098` یا `98` → تبدیل به `0…`.
4. اگر با `0` شروع نشود → یک `0` جلو.
5. سقف **11 رقم** (maxlength attribute موقت 20 برای paste با +98).
6. نمایش: `dir="ltr"` · `inputmode="tel"`.

#### کد معرف
- `pattern="[a-z0-9]{4,16}"` · min 4 max 16 · LTR · `autocapitalize="none"` · `spellcheck="false"`.
- اگر از کوکی دعوت آمده: readonly + help «قابل تغییر نیست».

#### Textarea + شمارنده
- کنترل `--area`: padding پایین بیشتر برای meta.
- شمارنده فارسی (`toFaDigits`) · سقف: توضیحات معرفی **400** (`#arp-desc`) · درباره من **200** (`#arp-about`).
- تجاوز از max → برش مقدار.
- آیکون مداد تزئینی پایین چپ.

#### File — مدارک عضویت
- Control dashed `#c9d5de` · bg `#f8fafc` · radius 12.
- `multiple` · accept JPG/PNG/WEBP/PDF · راهنما: حداکثر ۳ × ۵MB.
- `aria-describedby` به help خصوصی‌بودن.

#### File — آواتار پروفایل
- Input sr-only؛ تریگر دکمه دوربین روی آواتار.
- Client: فقط image jpeg/png/webp/gif · max 5MB.
- AJAX: پیش‌نمایش فوری objectURL · shrink به لبه 640px JPEG 0.88 · upload `arp_upload_avatar` · loading روی holder · rollback روی خطا.
- حذف: confirm «تصویر پروفایل حذف شود؟» → `arp_remove_avatar` · دکمه حذف وقتی `has_avatar` false مخفی است.

#### جستجو در فیلتر
- `type="search"` · دکمه آیکون submit کنار · webkit cancel مخفی.
- Placeholder «جستجو...» · maxlength 100.
- Submit فرم → AJAX لیست (نه ریلود کامل اگر JS باشد).

#### پول ادمین `.arp-money`
- ارقام فارسی مجاز.
- زنده: فقط رقم · جداکننده هزارگان `,` · caret حفظ می‌شود · صفر پیشرو حذف.
- `inputmode="numeric"` · `dir="ltr"`.

### 5.4 ارسال فرم

| فرم | مکانیزم | Busy | موفقیت | خطا |
| --- | --- | --- | --- | --- |
| Signup | Native POST multipart | `is-busy` + loading؛ disable دکمه در setTimeout 0 | redirect query msg | query `arp_err` + `arp_field` |
| Referral / Profile AJAX | `data-arp-ajax` → admin-ajax | `setBusy` | notice سبز · optional reset · optional redirect 1.2s · profile bind | notice قرمز · `markFieldError` · redirect 1.8s اگر session |
| بدون JS | همان `arp_action` POST کلاسیک | — | query `arp_msg` | query err |

Live region: `.arp-form-notice` · `role=status|alert` · scrollIntoView اگر خارج ویو · `focus({preventScroll:true})`.  
لینک اختیاری داخل notice: `.arp-notice__link`.

Flash شِل: `?arp_msg=` whitelist در layout (`referral_ok`, `invalid`, `profile_ok`, `no_status`).

### 5.5 شبکه فیلدها

- `.arp-submit-form__row--2`: دو ستون؛ ≤640 یک ستون (ثبت‌نام/معرفی).
- پروفایل موبایل: دو ستون باریک برای نام/فامیل حفظ می‌شود با gap 10.

---

## 6. Custom Select

Native `<select class="arp-select-native" data-arp-select>` مخفی (opacity 0، absolute) برای ارسال فرم و a11y fallback.

JS می‌سازد:
- دکمه `.arp-select__trigger` (`aria-haspopup="listbox"` · `aria-expanded`)
- منو `ul.arp-select__menu` (`role="listbox"`)
- گزینه `li.arp-select__option` (`role="option"`)

### رفتار
- کلیک trigger یا control → toggle.
- باز شدن: بقیه منوها بسته · `buildOptions` از options زنده · اگر `data-arp-select-search` → فوکوس به سرچ.
- انتخاب گزینه: set `selectedIndex` · `change` bubble · sync لیبل · بستن.
- Placeholder خالی / disabled → `.is-disabled` · غیرقابل کلیک.
- خارج کلیک یا Escape → بستن همه.
- `data-arp-autosubmit` روی native: بعد از change → `requestSubmit` فرم فیلتر.
- لیبل trigger: متن گزینه یا «انتخاب کنید» + `.is-placeholder`.
- Filled: کلاس `is-filled` روی control وقتی value غیرخالی.

### جستجو در منو (`data-arp-select-search` — نوع شغل)
- نرمال‌سازی ی/ک عربی، حذف اعراب و ZWNJ.
- فیلتر زنده · «موردی یافت نشد».
- ArrowUp/Down · Enter انتخاب · Escape بستن و فوکوس trigger.

### ظاهر منو
- max-height 220 (با سرچ 260) · shadow · radius 16 · option hover/selected primary soft · sticky search bar.

---

## 7. Date picker شمسی

### 7.1 مارکاپ فیلتر (از / تا)

`partials/date-range.php`:
- یک لیبل «تاریخ».
- Control `--range`: آیکون تقویم · input from · جداکننده «تا» · input to.
- هر input: `readonly` · `data-arp-jdate` · `autocomplete="off"` · `aria-label` از/تا.
- Placeholder پیش‌فرض: اول ماه جاری جلالی / امروز (ارقام فارسی).
- ظاهر سلول‌ها: bg `#eef3f7` · border `#c9d6e0` · radius 10 · font-weight 600 · center.

### 7.2 موتور

وابسته به **persianDatepicker** پلاگین Persian WooCommerce (`$.fn.persianDatepicker`).

اگر بار نشده باشد: `readOnly = false` → کاربر دستی `YYYY/M/D` تایپ می‌کند.

اگر بار شده:
```
theme: 'arp'
formatDate: 'YYYY/0M/0D'
cellWidth: 38, cellHeight: 34, fontSize: 13
closeOnBlur: false
calendarPosition y: 6
prev/next: SVG chevron
```

**موقعیت:** در `onShow` با `getBoundingClientRect` راست‌چین نسبت به فیلد و clamp داخل viewport (فاصله 8px از لبه).

**انتخاب:** `onSelect` → `change` bubble روی input.

**پاک کردن:** Backspace/Delete وقتی مقدار دارد → خالی + `setPickerDate('')` + `change` (نه ویرایش جزئی).

**فیلتر لیست:** هر `change` روی `.arp-refs-filters [data-arp-jdate]` → submit فرم → AJAX.

**تولد پروفایل:** `data-arp-jdate="birthday"` → `selectableYears` از سال جاری جلالی تا 1300.

### 7.3 تم CSS `.pdp-arp`

- پنل سفید · border `#e3e9ef` · radius 14 · shadow · z-index 100002 · RTL.
- هدر 36px · فلش 32×32 radius 10 · hover `#eef3f7`.
- Dow muted 12px · day radius 10 · friday قرمز · today inset ring primary · selday solid primary سفید · disday خاکستری.
- Footer «برو به امروز» pill primary soft.
- Month/year overlay لیست 3ستونه · selected primary.

Caret روی readonly jdate: transparent.

---

## 8. جداول، فیلتر، صفحه‌بندی، AJAX لیست

### 8.1 فیلتر `.arp-refs-filters`

- داخل کارت · `role="search"` · `method="get"` · `data-arp-list="{view}"`.
- Hidden `arp_page`.
- گرید: referrals 4 ستون (تاریخ | وضعیت | خدمت | جستجو) · commissions `--3` · invited special تاریخ+خدمت+reset.
- ≤1100: 2 ستون · ≤767: تاریخ و جستجو full-width؛ وضعیت/خدمت 50/50.
- Reset: `[data-arp-filters-reset]` · مخفی تا فیلتری فعال شود · لینک قرمز pill «پاک کردن فیلترها».

Views AJAX: `referrals` | `commissions` | `invited_commissions`.

### 8.2 جریان AJAX لیست

1. Submit فرم یا change autosubmit یا انتخاب تاریخ یا کلیک pager یا کلیک reset.
2. `AbortController` درخواست قبلی را لغو می‌کند.
3. باکس `[data-arp-results]` → `.is-loading` · opacity 0.55 · اسپینر · `aria-busy=true`.
4. پاسخ HTML جایگزین innerHTML · `history.pushState` · sync نمایش reset · اگر بالای ویو رفته scrollIntoView.
5. خطا: notice `.arp-results-error` prepend.
6. `popstate`: sync مقادیر فرم (و datepicker) از URL سپس load بدون push.

بدون JS: GET معمولی سرور ساید کار می‌کند.

### 8.3 جدول `.arp-table`

- جدا · th مرکز · td مرکز · nowrap · hover ردیف.
- ستون عدد ردیف muted · مبلغ bold + واحد کوچک · empty state pad 40.
- اسکرول افقی: `.arp-table-wrap` · `-webkit-overflow-scrolling: touch`.

### 8.4 ردیف گسترش‌پذیر

`.arp-table--expandable`:
- کلیک ردیف یا دکمه `.arp-row-toggle` (مگر لینک/دکمه دیگر یا selection متن).
- `aria-expanded` · `aria-controls` → ردیف جزئیات · `aria-label` «نمایش/بستن توضیحات».
- باز: ردیف `is-open` · bg `#f6f9fc` · chevron rotate 180° · details `pre-line` · empty «—».

### 8.5 صفحه‌بندی `.arp-refs-pager`

- چپ/راست RTL: info متنی («نمایش x تا y از z») + دکمه‌های prev / صفحات / next.
- Current: primary پر · disabled muted · hover outline primary.
- موبایل: `pages--full` مخفی · `pages--compact` · در ≤640 ستون عمودی.

اندازه دکمه: min 36×36 (زیر 44 لمسی — در بازطراحی بهتر است 44 شود).

---

## 9. دکمه‌ها، نوتیس، وضعیت‌ها، KPI، نمودار

### 9.1 دکمه‌ها

| گونه | کلاس | رفتار |
| --- | --- | --- |
| Primary block | `.arp-submit-btn` | full · 52min · primary · hover `#015a8c` · loading spinner |
| Outline | `.arp-btn-outline` | border primary · 8×16 · hover soft |
| Banner CTA | `.arp-refs-cta__btn` | primary · 12×22 · margin-top 12 |
| Profile edit | `.arp-profile-hero__edit` | primary · 10×18 · radius 12 |
| Camera / remove avatar | دایره روی عکس | 38 / 34 · border سفید 3 |
| Invite icon | `.arp-invite-card__btn` | 49 مربع · border · hover primary soft · `.is-copied` سبز |
| Admin | `.button` / `.button-primary` هسته WP | ARP فقط dashicon هم‌تراز |

### 9.2 نوتیس

| گونه | ظاهر | role |
| --- | --- | --- |
| Success `.arp-notice` | bg `#ecfdf5` · border `#6ee7b7` · text `#065f46` | status |
| Error `--error` | `#fde8ec` / rose | alert |
| Admin | `notice notice-{type} inline is-dismissible` داخل `.arp-notices` aria-live polite | |

### 9.3 پیل وضعیت

همیشه **متن + رنگ**؛ رنگ سفارشی از ادمین با `--dynamic` و `--arp-status-c`.

### 9.4 Metrics board `.arp-metrics`

Desktop: finance (hero | money) ‖ refs (total | open | success) با جداکننده 1px.  
≤1100: ستونی.  
≤640: CSS grid 2×N · hero full · بدون جداکننده‌های قبلی · border بین سلول‌ها.

Hero: نوار 2px accent راست · عدد 28px accent.  
Open: لیبل progress + نقطه. Success: سبز.

### 9.5 نمودارها

- Donut `#arp-chart-status`: legend رنگی با badge count گوشه‌ای · مرکز «کل موارد».
- Trend `#arp-chart-trend`: ارتفاع ~240 · داده از JSON `#arp-dash-chart-data`.
- RTL: وضعیت راست، روند چپ (`1fr 1.85fr`) · ≤1200 ستونی · موبایل donut بالا، legend ردیفی wrap.

---

## 10. کارت دعوت، کپی، اشتراک

### URL row
- Input readonly LTR monospace · کلیک = `select()`.
- Copy: Clipboard API یا fallback textarea · کلاس `.is-copied`  · tip «کپی شد» fade · `aria-live` روی tip.
- Share: Web Share API با title/text/url؛ اگر نبود tip مناسب / کپی.

Admin copy تلفن: دکمه `.arp-copy` متن را 1.5s به «کپی شد» عوض می‌کند.

---

## 11. صفحات فرانت صفحه به صفحه

### 11.1 عضویت (`signup.php`)
Header + lead · فرم (نام، موبایل، شغل searchable، کد معرف، مدارک) | کارت how چهار مرحله.  
Submit: «ارسال درخواست طرح همکاران» · disabled اگر شغلی نیست.

### 11.2 Pending / Rejected / Already
Pending: فقط توضیح + notice صبر.  
Rejected: پیام + امکان فرم مجدد.  
Already: هدایت پنل.

### 11.3 داشبورد
Profile card (+ CTA موبایل ثبت معرفی) → Invite card → Metrics → Charts → جدول ۵تایی آخرین معرفی‌ها + outline «مشاهده همه».

### 11.4 ثبت معرفی (`page-submit.php`)
Intro · error · فرم بیمار (نام، تلفن، خدمت select، توضیح+counter) | how ۴ مرحله + تصویر پس‌زمینه.  
AJAX `arp_submit_referral` + `data-arp-reset`.  
Grid ≤1100 تک‌ستون؛ how پایین.

### 11.5 معرفی‌های من
فیلتر ۴تایی · نتایج expandable · pager · بنر CTA ثبت معرفی (back-2.webp؛ موبایل padding-bottom برای تصویر).

### 11.6 پورسانت من
فیلتر ۳تایی · جدول مبلغ/وضعیت پرداخت/کد پیگیری Inter/خدمت/تاریخ.

### 11.7 همکاران معرفی‌شده
کارت لینک دعوت · جدول دعوت‌شدگان.  
اگر special inviter: KPI سه‌تایی سود دعوت · فیلتر تاریخ+خدمت · جدول پورسانت دعوت.

### 11.8 پروفایل
Hero گرادیان آبی + متا + آواتار/دوربین/حذف/ویرایش → فرم اطلاعات (هم‌تراز WC) → بنر امنیت با ۳ آیتم اعتماد.

### 11.9 بنر آموزشی دعوت
`.arp-invite-colleagues-cta` روی برخی صفحات با تصویر vector.

---

## 12. ادمین

### 12.1 اصول
ظاهر هسته وردپرس + لایه نازک ARP. فونت/RTL از ادمین. بدون طراحی «اپ»؛ الگوی List Table / Metabox / Settings API-like.

### 12.2 ناوبری و بازخورد صف
- Bubble `awaiting-mod` روی منو.
- پیشخوان = مرکز تصمیم: KPI کلیک‌پذیر → صفحات فیلترشده.
- صف تماس: قدیمی‌ترین‌ها · `tel:` · دکمه «پیگیری» → edit.

### 12.3 KPI ادمین `.arp-kpi`
Flex · padding 16 · border · radius 4 · آیکون 40 tint · hover/focus ring tone.  
Tones: blue/orange/teal/purple/green.

### 12.4 List tables
- Columns عرض‌ثابت برای phone/status/date.
- Views وضعیت با count فارسی.
- فیلتر: select max-width 170 · `.arp-date-input` 130 · `.arp-autosubmit` change→submit.
- User cell: avatar 32/40 گرد + نام + muted.
- Row inactive: opacity 0.6.
- Bulk actions استاندارد WP.
- Screen option per_page (پیش‌فرض 20).
- Export CSV از tools / list hooks.
- بعد از bulk، redirect پاک‌سازی `_wp_http_referer`.

### 12.5 Applications
ستون‌ها: متقاضی · موبایل · شغل · مدارک (لینک یا «بدون مدرک») · زمان · تأیید/رد.  
Flash: approved / rejected / fail.

### 12.6 Referral edit
دو ستون metabox:
- اصلی: جزئیات + timeline (نقطه آبی حرکت وضعیت · زرد یادداشت · سبز شروع ثبت).
- ساید: select وضعیت (برچسب «پورسانت‌دار») · فیلد مبلغ `.arp-amount-field` فقط وقتی `data-commission=1` (JS: show/required/enable) · یادداشت · ذخیره · کارت همکار با لینک‌ها · لیست پورسانت badge.
- کپی تلفن.

### 12.7 Finance
- Commissions table.
- Wallet hub: notice وابستگی MTW · لینک تراکنش‌ها/برداشت‌ها · migrate مانده قدیمی با `data-confirm`.
- Rules: قیمت × درصد با preview زنده `[data-arp-commission-preview]`.

### 12.8 Settings
- SMS دسته‌بندی‌شده در کارت سفید · قالب متن + شمارنده سگمنت (۷۰/۱۶۰ یونیکد) · کلیک `.arp-insert-var` درج توکن در caret · پترن فراز اختیاری.
- Admin picker: لیست اسکرول‌دار avatar+نام+موبایل؛ بدون موبایل قرمز هشدار.
- Special inviters picker مشابه.
- Statuses: swatches رادیویی ۳۰px · focus-visible ring · flags رفتار و گروه داشبورد.
- Services/job-types: CRUD + usage count · حذف محافظت‌شده.
- Tools: نسخه DB · شمارش‌ها · خروجی.

### 12.9 ویجت‌های JS ادمین خلاصه
- Status form sync commission field.
- SMS counter.
- Commission preview.
- Money formatter.
- Autosubmit selects.
- Copy + insert-var.
- Confirm `[data-confirm]`.
- Double-submit disable روی POST (بازگردانی در pageshow bfcache).

---

## 13. حالت‌های سیستم و دسترس‌پذیری

### 13.1 حالت‌هایی که UI باید پوشش دهد

| State | رفتار فعلی |
| --- | --- |
| Empty list | `.arp-table__empty` / `.arp-empty` ادمین |
| Empty services/jobs | notice خطا · disable submit عضویت |
| Loading form | spinner روی دکمه |
| Loading list/avatar | overlay spinner |
| Validation | invalid block + focus فیلد |
| Network / 401 / 403 | پیام i18n · redirect لاگین |
| Pending membership | صفحه صبر بدون فرم |
| Wallet missing | لیبل جایگزین متریک · notice ادمین |
| No JS | فرم POST و فیلتر GET کار می‌کنند؛ select native دیده می‌شود اگر enhance نشود |
| Long content | جدول overflow-x · details `overflow-wrap` |
| First visit invited | کارت لینک + جدول خالی |

### 13.2 A11y موجود
Landmarks aside/nav/main · aria-label منو · aria-current · aria-live لیست/نوتیس · aria-label آیکون‌دکمه‌ها · role listbox/option · Escape بستن drawer/select · confirm حذف آواتار.

### 13.3 شکاف‌ها (برای اصلاح بعدی)
- Focus ring واضح روی فیلد (الان outline none).
- `*` الزامی با `aria-hidden` بدون نام accessible «ضروری».
- هدف لمسی pager/toggle گاهی <44px.
- انیمیشن shine badge بدون خاموشی کامل در reduced-motion.
- نمودار بدون جدول داده جایگزین.

---

## 14. نقشه فایل

| موضوع | مسیر |
| --- | --- |
| CSS فرانت | `assets/css/public.css` |
| JS فرانت | `assets/js/public.js` |
| CSS/JS ادمین | `assets/css/admin.css` · `assets/js/admin.js` |
| شِل ناوبری | `public/views/dashboard/layout.php` |
| صفحات پنل | `public/views/dashboard/page-*.php` |
| پارشال‌ها | `public/views/dashboard/partials/*` |
| عضویت | `public/views/signup*.php` |
| My Account | `public/views/myaccount/invited-colleagues.php` |
| روت ادمین | `admin/class-arp-admin-menu.php` |
| UI helpers ادمین | `admin/class-arp-admin-ui.php` |
| ویوها | `admin/views/**` |
| جداول | `admin/list-tables/*` |
| تصاویر UI | `assets/img/*` |
| فونت Inter | `assets/fonts/inter-latin-500.woff2` |

---

*هر بازطراحی باید این رفتارها را حفظ یا صریحاً جایگزین مستند کند: autosubmit فیلتر، datepicker جلالی readonly+پاک‌کن Delete، نرمال موبایل ایران، custom select+search شغل، drawer موبایل، واریز پورسانت فقط از کیف پول.*

---

## 15. پیوست A — پالت کامل وضعیت و پورسانت

### A.1 وضعیت‌های بذر (slug → tone CSS + رنگ پیش‌فرض)

| slug | tone class | default hex |
| --- | --- | --- |
| `waiting_contact` | waiting | `#0EA5E9` |
| `in_progress` | progress | `#0272B1` |
| `patient_accepted` | accepted | `#16A34A` |
| `completed` | done | `#0F766E` |
| `patient_withdrawn` | cancel | `#E11D48` |
| `failed_contact` | failed | `#EA580C` |

وضعیت سفارشی بدون رنگ ذخیره‌شده: اگر `triggers_commission` → tone `done`؛ اگر `is_terminal` → `cancel`؛ وگرنه `progress`. رنگ fallback = اندیس در `STATUS_PALETTE`.

### A.2 پالت قابل انتخاب ادمین (`Arp_Ui::STATUS_PALETTE`)

`#0272B1` آبی مدیار · `#0EA5E9` آبی آسمانی · `#06B6D4` فیروزه‌ای · `#0D9488` سبزآبی · `#0F766E` سبز کله‌غازی · `#059669` زمردی · `#16A34A` سبز · `#65A30D` سبز لیمویی · `#4D7C0F` زیتونی · `#CA8A04` خردلی · `#F59E0B` کهربایی · `#EA580C` نارنجی · `#DC2626` قرمز · `#9F1239` زرشکی · `#E11D48` سرخابی · `#DB2777` صورتی · `#C026D3` ارغوانی · `#9333EA` بنفش · `#7C3AED` یاسی · `#4F46E5` نیلی · `#2563EB` آبی پررنگ · `#1E3A8A` سرمه‌ای · `#92400E` قهوه‌ای · `#475569` خاکستری آبی · `#1F2937` زغالی.

UI swatch: دایره ۳۰×۳۰ · radio مخفی · checked = ring دوبل سفید+رنگ · focus-visible outline `#2271b1` offset 4 · hover scale 1.08.

نمایش فرانت: `style="--arp-status-c:{HEX}"` روی `.arp-status--dynamic`.

### A.3 وضعیت پرداخت پورسانت

| state | برچسب UI | tone → class |
| --- | --- | --- |
| `approved` | در انتظار پرداخت | progress |
| `paid` | پرداخت شد | done |
| `reversed` | برگشت‌خورده | cancel |
| `pending` | در انتظار تایید | waiting (فیلتر فرانت ندارد؛ موتور تولید نمی‌کند) |

فیلتر همکار فقط: approved / paid / reversed.

گروه داشبورد همکار (settings status): مقادیر `Arp_Statuses_Repo::dashboard_groups()` برای شمارش open/success در metrics.

---

## 16. پیوست B — موجودی فیلد به فیلد هر فرم

### B.1 عضویت (`arp-signup-form`)

| name | UI label | required | control | attrs / رفتار |
| --- | --- | --- | --- | --- |
| `full_name` | نام و نام خانوادگی | ✓ | text + person icon | maxlength 191 · autocomplete name |
| `mobile` | شماره موبایل | ✓ | text + phone | LTR · tel · `data-arp-iran-mobile` · max 20 |
| `job_type_id` | نوع شغل | ✓ | select + search | `data-arp-select` + `data-arp-select-search` |
| `inviter_code` | کد معرف (اختیاری) | — | text + ticket | LTR · 4–16 `[a-z0-9]` · ممکن است readonly قفل |
| `arp_documents[]` | آپلود مدارک (اختیاری) | — | file multiple dashed | JPG/PNG/WEBP/PDF · ≤3 · ≤5MB · `aria-describedby` |

Submit: «ارسال درخواست طرح همکاران» · enctype multipart · **بدون AJAX** (native + loading guard).

### B.2 ثبت معرفی (`data-arp-ajax="arp_submit_referral"`)

| name | label | req | control |
| --- | --- | --- | --- |
| `patient_name` | نام و نام خانوادگی بیمار | ✓ | text · max 191 · autocomplete off |
| `patient_phone` | شماره تماس بیمار | ✓ | Iran mobile normalize · placeholder `09123446789` |
| `service_id` | نوع خدمت موردنظر | ✓ | custom select (بدون search) |
| `description` | توضیحات (در صورت نیاز) | — | textarea · id `arp-desc` · max **400** · counter فارسی |

Submit: «ثبت معرفی بیمار» · `data-arp-reset` بعد از موفقیت · disabled اگر خدمتی نباشد.

How card عنوان: «چطور کار می‌کنید؟» · ۴ گام · CTA: «با معرفی، هم بیمار را راهی کنید و هم درآمد داشته باشید».

### B.3 پروفایل (`data-arp-ajax="arp_save_profile"`)

| name | label | req | notes |
| --- | --- | --- | --- |
| `arp_avatar` | (دوربین) | — | hidden file · jpeg/png/webp/gif · AJAX جداگانه upload |
| `account_first_name` | نام | ✓ | max 100 · given-name |
| `account_last_name` | نام خانوادگی | ✓ | max 100 · family-name |
| `account_display_name` | نام نمایشی | ✓ | max 191 · hint زیر فیلد |
| `account_email` | ایمیل | ✓ | type email · LTR |
| `drplus_mobile` | شماره تماس | ✓ | Iran mobile · maxlength 11 بعد از normalize |
| `drplus_birthday` | تاریخ تولد | ✓* | readonly · `data-arp-jdate="birthday"` · years تا 1300 |
| `drplus_nid` | کد ملی | ✓ | 10 رقم · LTR · numeric |
| `job_type_id` | نوع شغل | ✓ | select + search |
| `about` | درباره من | — | textarea · id `arp-about` · max **200** |

Submit: «ذخیره اطلاعات» + آیکون save.  
Hero همیشه badge «فعال».  
Security: رمزگذاری اطلاعات · پایش فعالیت‌ها · تأیید هویت.

\* required در UI با ستاره؛ مقدار از datepicker.

### B.4 فیلترها (GET · AJAX)

**معرفی‌ها:** `arp_from` · `arp_to` · `arp_status` · `arp_service` · `arp_q` (+ hidden `arp_page=referrals`)  
**پورسانت:** `arp_from` · `arp_to` · `arp_state` · `arp_service`  
**دعوت special:** `arp_from` · `arp_to` · `arp_service`  
Autosubmit: select‌ها و تاریخ؛ جستجو با submit دکمه/Enter.

### B.5 ادمین — تغییر وضعیت معرفی

`status_id` select · `commission_amount` (مشروط) · `note` textarea اختیاری · nonce `arp_update_referral`.

---

## 17. پیوست C — ستون جداول و empty copy

### C.1 داشبورد — آخرین معرفی‌ها

ردیف · نام بیمار · تلفن · خدمت · تاریخ ثبت جلالی · وضعیت dynamic · پورسانت|—  
Empty: «هنوز معرفی‌ای ثبت نکرده‌اید.»

### C.2 معرفی‌های من (expandable)

نام و نام خانوادگی · شماره تماس (ltr+fa) · تاریخ ثبت · نوع خدمت · وضعیت · پورسانت · toggle جزئیات  
Details: «توضیحات معرفی:» یا «توضیحی ثبت نشده است.»  
Empty فیلتر: «موردی با این فیلترها پیدا نشد.» · Empty خام: «هنوز معرفی‌ای ثبت نکرده‌اید.»

### C.3 پورسانت من

# · تاریخ ثبت · مبلغ پورسانت · وضعیت پرداخت (tone class) · تاریخ پرداخت|– · کد پیگیری Inter LTR|–  
Empty فیلتر / خام مشابه بالا با متن پورسانت.

### C.4 همکاران معرفی‌شده

ستون‌های پایه: نام · موبایل · تاریخ عضویت/درخواست · وضعیت درخواست؛ special + ستون سود/پورسانت دعوت.  
بالا: کارت لینک دعوت.

### C.5 ادمین Applications

متقاضی (avatar+email) · موبایل tel · شغل · مدارک «فایل ۱ · …» یا بدون مدرک · زمان · **تأیید** primary + **رد** با confirm «این درخواست رد شود؟».

### C.6 ادمین Referrals list

cb · بیمار · تلفن · همکار · خدمت · وضعیت badge · پورسانت · تاریخ ثبت (sortable).

### C.7 ادمین Referral edit timeline

حرکت وضعیت / یادداشت / نقطه شروع ثبت توسط همکار · `ago` و تاریخ جلالی با ساعت.

### C.8 ادمین صف تماس داشبورد

بیمار+tel · خدمت · همکار · انتظار (ago) · دکمه پیگیری.

---

## 18. پیوست D — قوانین تاریخ و صفحه‌بندی

### D.1 نرمال بازه تاریخ (`Arp_Ui::date_range_from_request`)

1. خواندن `arp_from` / `arp_to` از GET.
2. تبدیل جلالی → میلادی؛ نامعتبر = خالی.
3. ذخیره Jalali ASCII در `from`/`to` و Gregorian در `date_from`/`date_to`.
4. اگر from > to → **جابه‌جایی خودکار** دو سر بازه.

فیلتر placeholder: از = روز ۱ ماه جاری جلالی · تا = امروز (ارقام فارسی در attribute).

### D.2 Pager (`Arp_Ui::pager_items`)

- همیشه صفحه ۱ و آخر.
- `full`: radius **2** اطراف جاری (+ …).
- `compact` (موبایل CSS): radius **1**.
- اگر `total < 1` کل pager رندر نمی‌شود.
- یک صفحه: فقط «N مورد» بدون nav.
- چند صفحه: «نمایش a تا b از c مورد» + prev/pages/next.
- اعداد فارسی در دکمه‌ها.
- `aria-label="صفحه‌بندی"` · `aria-current="page"` روی جاری · فلش‌ها aria-label قبل/بعد.

### D.3 Datepicker key rules (جمع‌بندی رفتاری)

| رویداد | نتیجه |
| --- | --- |
| باز شدن | theme `arp` · راست‌چین به فیلد · clamp viewport |
| انتخاب روز | مقدار `YYYY/0M/0D` · fire `change` |
| change در فیلتر | submit فرم لیست |
| Delete/Backspace | پاک کامل (نه ویرایش کاراکتر) |
| بدون پلاگین jQuery | readonly برداشته → تایپ دستی |
| birthday | سال‌های selectable از امسال تا ۱۳۰۰ |

---

## 19. پیوست E — چک‌لیست بازپیاده‌سازی

وقتی UI عوض می‌شود، این‌ها را یکی‌یکی تیک بزن:

**ناوبری**
- [ ] ترتیب ۹ آیتم سایدبار + logout
- [ ] active فقط داخلی · لینک خارجی بدون active
- [ ] drawer ≤767: expand/close/backdrop/Escape/focus return/scroll lock
- [ ] sticky ≥1024 زیر هدر Elementor + admin bar
- [ ] badge «پنل همکار» + موجودی کیف پول شرطی

**فرم‌ها**
- [ ] field anatomy label/control/icon/hint
- [ ] focus/filled/invalid/loading/disabled states
- [ ] Iran mobile normalize روی همه تلفن‌ها
- [ ] counters 400 / 200 فارسی
- [ ] custom select + search شغل + autosubmit فیلتر
- [ ] signup native multipart؛ referral/profile AJAX + fallback POST
- [ ] avatar shrink 640 + confirm حذف

**تاریخ**
- [ ] range UI از/تا · readonly · theme arp
- [ ] birthday years · Delete clears · swap from/to

**لیست‌ها**
- [ ] AJAX loading/abort/pushState/popstate/reset chip
- [ ] expandable توضیحات
- [ ] pager full+compact · empty copy دو حالت

**رنگ/وضعیت**
- [ ] dynamic `--arp-status-c` از پالت ادمین
- [ ] commission tones سه حالت فیلتر
- [ ] metrics hero/open/success رنگ‌ها

**ادمین**
- [ ] menu bubbles
- [ ] KPI tones + صف تماس
- [ ] amount field فقط commission status
- [ ] money formatter · SMS counter · insert-var · confirm · double-submit
- [ ] swatches وضعیت · admin SMS picker

**RTL / i18n نمایش**
- [ ] ارقام فارسی در UI
- [ ] جزایر LTR: phone, email, URL, tracking, pattern, nid
