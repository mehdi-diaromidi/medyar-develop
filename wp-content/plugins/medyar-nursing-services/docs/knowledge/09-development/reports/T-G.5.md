# T-G.5 — UI ویزارد ثبت سفارش

## خلاصه

1. داشبورد: ۱۰ دسته؛ new-order: چک‌باکس چندانتخابی + جمع بازه قیمت + «افزودن خدمت دیگر» (`is_extra`) + اعلان manual و `shift_note`
2. `.mns-field-hint`: `display:flex; align-items:center` سراسری و در ویزارد
3. تهران: مناطق با `.mns-nvl__dcard` تک‌انتخابی؛ سایر شهرها `<select>`
4. دکمه‌های «انتخاب همه» / «پاک‌کردن همه» → `.mns-nvl__dact-btn` بدون افکت هاور (ویزارد + availability پرستار)
5. بازه‌های ساعت: `.mns-nvl__slic` + متن ساعت (helper مشترک JS)
6. datepicker: عرض و تراز با `.mns-field-block__control` / `data-mns-date-anchor`

## فایل‌ها

- `public/views/patient/page-new-order.php`
- `public/views/patient/page-dashboard.php`
- `public/views/nurse/page-availability.php`
- `assets/js/public.js`
- `assets/css/public.css`

## بررسی زنده

صفحه new-order و dashboard → HTTP 200. بازبینی بصری کامل را ناظر در مرورگر انجام می‌دهد.
