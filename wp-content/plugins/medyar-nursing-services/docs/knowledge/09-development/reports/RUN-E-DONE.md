# RUN-E — DONE

تاریخ: 2026-11-18 · پلاگین `0.6.1` · `MNS_UI_STATIC=false`

## نتیجه کارت‌ها

| کارت | نتیجه | یادداشت |
|------|--------|---------|
| T-E.1 جزئیات سفارش بیمار | PASS (کد) | `is_real_panel_user` + `Patient_Orders_Api`؛ لغو REST |
| T-E.2 داشبورد بیمار | PASS (کد) | داده زنده / خالی |
| T-E.3 فهرست بیمار/پرستار | PASS (کد) | API list؛ DOM سبک |
| T-E.4 صفحات M4+ | PASS (کد) | کاربر واقعی بدون fixture؛ مسیر زنده invoices/loyalty/recurring/surveys و وضعیت خالی مراحل پرستار |
| T-E.5 E2E assigned seed | PASS (کد) | دکمه seed در ادمین E2E |
| T-E.6 مرورگر کامل | PARTIAL | وابسته به لینک‌های ناظر `.e2e/links.json`؛ نیازمند پاک‌سازی ناظر |

## باگ‌های رفع‌شده در همین موج

- جایگزینی stub ادمین با ARP (`mns-wrap`، KPI، List_Table، تب، notice)
- REST پرستار `start` / `end` / `steps/{step}` + اتصال UI تأیید شروع/پایان
- `MNS_DB::transaction` alias · prepare splat در `simple_status_change`
- Schema v2: invoices/payments/surveys/loyalty/recurring · مالی ادمین · CSV
- Cutover: `MNS_UI_STATIC` پیش‌فرض `false`

## نیازمند ناظر

- ساخت/تازه‌سازی `.e2e/links.json` و پاک‌سازی E2E پس از تأیید مرورگر
