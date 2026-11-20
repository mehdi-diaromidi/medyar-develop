# T-F.6 — Self-test پرداخت آنلاین (AC-M6)

تاریخ اجرا روی develop: 2026-11-18/19 · suite: **فقط مالی** · درگاه: Fake

## کلاس

`includes/class-mns-selftest-pay.php` — ثبت در `MNS_Selftest_Scenarios::register_all` و گروه `money` در ادمین (`AC-M6-*`).

## نتیجهٔ اجرا روی develop

`tools.php?page=mns-selftest` → **فقط مالی** → `#mns-selftest-summary` **PASS=13 FAIL=0**

| AC | نتیجه | detail |
|----|--------|--------|
| AC-M6-1 | PASS | `amount_locked;owner_404;bad_state;reuse_ok` |
| AC-M6-2 | PASS | `paid;tracking=FREF-…` |
| AC-M6-3 | PASS | `one_payment_after_replay` |
| AC-M6-4 | PASS | `failed_unpaid` |
| AC-M6-5 | PASS | `amount_mismatch` |
| AC-M6-6 | PASS | `paid_duplicate;ref=…` |
| AC-M6-7 | PASS | `generic_error_redirect` |
| AC-M6-8 | PASS | `expired` |

(همراه AC-M5/AC-M4 و AC-TC-1 در همان suite.)

## یادداشت

در این دور پایانی نشست ادمین لاگین‌شده برای تکرار اجرا در دسترس نبود؛ نتیجهٔ بالا از اجرای زندهٔ قبلی روی همان محیط develop است. ناظر می‌تواند با «فقط مالی» دوباره تأیید کند. هیچ تماس واقعی به زرین‌پال در self-test نیست.
