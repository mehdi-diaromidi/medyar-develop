# reports — گزارش‌های Cursor (هر کارت یک فایل)

Cursor بعد از هر کارت `T-m.n.md` و در پایان `RUN-X-DONE.md` یا در صورت مشکل `BLOCKED-T-m.n.md` اینجا می‌نویسد. ناظر (Claude) این پوشه را پایش و بازبینی می‌کند.

## موج‌های اتمام پروژه (ARP + roadmap)

پس از **اتمام هر Wave** از پلن اتمام MNS، یک گزارش جدا نوشته می‌شود:

| فایل | موج |
|------|-----|
| `WAVE-1-DONE.md` | شِل ادمین ARP + M3 ops |
| `WAVE-2-DONE.md` | M4 اجرای خدمت |
| `WAVE-3-DONE.md` | M5 مالی |
| `WAVE-4-DONE.md` | M6–M7 surveys/loyalty/recurring |
| `WAVE-5-DONE.md` | M8 گزارش/CSV + M9 cutover |

هر گزارش باید شامل: تاریخ/نسخه، جدول تحویل، محدودیت‌ها، وضعیت commit/push باشد.
