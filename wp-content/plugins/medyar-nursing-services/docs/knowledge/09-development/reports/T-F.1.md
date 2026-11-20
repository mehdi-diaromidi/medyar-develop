# T-F.1 — Migration v3 و درگاه پرداخت

تاریخ: 2026-11-19 · محیط: develop.medyarco.com · پلاگین: `0.7.0`

## خلاصه

| مورد | نتیجه |
|------|--------|
| `MNS_Schema::LATEST` | `3` |
| `sql_v3()` → `mns_payment_attempts` | افزایشی؛ v1/v2 دست‌نخورده |
| Migration روی develop | schema **3 / 3**؛ جدول `payment_attempts` موجود |
| Interface درگاه | `MNS_Payment_Gateway` (`initiate` / `verify` / `inquiry`) |
| Zarinpal | `MNS_Payment_Gateway_Zarinpal` — کپی منطقی MTW |
| Fake | `MNS_Payment_Gateway_Fake` + فیلتر `mns_payment_gateway` در self-test |

## مبلغ تومان / IRT (مقایسه با MTW)

MTW (`Wallet_Zarinpal_Gateway::initiate_payment`): `currency=IRT` و مبلغ به‌صورت **تومان** (int).  
MNS همان قرارداد را دارد: `currency=IRT`، مبلغ کپی از ردیف فاکتور (تومان)، بدون تبدیل ریال.

## تفاوت عمدی با MTW

- **بدون** `card_pan` اجباری و بدون `metadata.card_pan`
- `metadata` فقط `mobile` و `order_id` (در صورت وجود)
- timeout ۳۰ث؛ URLهای v4 `request` / `verify` / `inquiry` + `StartPay/{authority}`
- خطاهای `-14` / `-18` پیام دامنهٔ بازگشت جداگانه

## فایل‌ها

- `includes/db/class-mns-schema.php` (`LATEST=3`, `sql_v3`)
- `includes/db/class-mns-db.php` (جدول در لیست)
- `includes/payments/class-mns-payment-gateway.php`
- `includes/payments/class-mns-payment-gateway-zarinpal.php`
- `includes/payments/class-mns-payment-gateway-fake.php`
