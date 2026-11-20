# T-F.5 — سهم پرستار → کیف پول MTW

تاریخ: 2026-11-19

## تصمیم

Listener `MNS_Nurse_Wallet::on_share_credited` روی هوک `mns_nurse_share_credited` (از `record_payment`).

## `operation_type` معتبر

**`booking_payout`**

دلیل مجاز بودن در MTW (`wallet_validate_operation_type` / `wallet_allowed_operation_types` در `medyar-toman-wallet/helpers/validations.php`): در لیست سفید عملیات‌هاست و همان نوعی است که `Booking_Specialist_Payout` برای درآمد نوبت/ویزیت متخصص به‌کار می‌برد. قبل از واریز، در صورت وجود تابع، `wallet_validate_operation_type('booking_payout')` صدا زده می‌شود.

## Idempotency (دوبار واریز نمی‌شود)

1. اگر `invoices.meta.credited_at` از قبل باشد ⇒ return
2. Guarded UPDATE: فقط وقتی `meta NOT LIKE '%"credited_at"%'` ⇒ `rows === 1` اجازهٔ `add_balance` می‌دهد
3. خطای MTW/استثنا ⇒ لاگ + `wallet_followup` در meta؛ **بدون throw**
4. اگر `Wallet_Manager` نباشد ⇒ no-op

## فایل

`includes/payments/class-mns-nurse-wallet.php` — بازبینی شد؛ تغییر جدید لازم نبود.
