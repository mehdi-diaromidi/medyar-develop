# T-G.3 — ثبت سفارش سرور (`items[]`)

## خلاصه

- `POST /orders` → `items: [{service_id, is_extra}]` (۱–۱۰)؛ primary هم‌دسته؛ extras آزاد
- `requires_rx` هر قلم ⇒ نسخه الزامی؛ snapshot قیمت در `order_items`
- `manual_assign` ⇒ بدون A-13/auto-assign؛ `submitted`؛ تاریخ/ساعت اختیاری + `shift_note`
- فاکتور: `invoices.meta.items` + برچسب «مورد اضافه» در `page-invoice-view.php`
- Self-test: `AC-G-3`, `AC-G-4`

## فایل‌ها

- `includes/services/class-mns-order-items.php`
- `includes/services/class-mns-orders.php`
- `includes/services/class-mns-invoices.php`
- `public/views/patient/page-invoice-view.php`
- `assets/js/public.js` (payload `items` / `shift_note`)
