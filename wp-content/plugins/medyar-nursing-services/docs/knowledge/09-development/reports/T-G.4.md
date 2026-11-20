# T-G.4 — چک‌لیست پرستار چندخدمتی

## خلاصه

- `MNS_Order_Items::checklist_groups($order_id)` اجتماع بندها به‌ازای هر زیرخدمت
- API پرستار فیلد `checklist_groups` را برمی‌گرداند
- ذخیره: whitelist `by_service` در `MNS_Execution`؛ سقف حجم **۴۸KB** (بالاتر از ۲۰KB برای چند زیرخدمت)
- `page-checklist.php` مسیر زنده: عنوان گروه + بندها (+ برچسب مورد اضافه)

## فایل‌ها

- `includes/services/class-mns-order-items.php`
- `includes/services/class-mns-execution.php`
- `includes/services/class-mns-nurse-orders-api.php`
- `public/views/nurse/page-checklist.php`
- Self-test `AC-G-5`

## باز

QUESTION-2 — فرم تعاملی کامل در مسیر زنده vs نمایش گروه‌ها.
