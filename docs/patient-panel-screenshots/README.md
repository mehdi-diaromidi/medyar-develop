# صفحات پنل بیمار (develop)

اسکرین‌شات‌ها از `https://develop.medyarco.com` با `mns_nocache=1&v=0.5.36` گرفته شده‌اند.

| صفحه | لینک | فایل |
|------|------|------|
| داشبورد | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=dashboard) | `01-dashboard.png` |
| ثبت سفارش | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=new-order) | `02-new-order.png` |
| سفارش‌های من | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=orders) | `03-orders.png` |
| جزئیات سفارش | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=order-view&mns_order=MNS-1405-1001) | `04-order-view.png` |
| بیماران من | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=patients) | `05-patients.png` |
| فاکتورها | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=invoices) | `06-invoices.png` |
| جزئیات فاکتور | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=invoice-view&mns_invoice=INV-2031) | `07-invoice-view.png` |
| خدمات دوره‌ای | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=recurring) | `08-recurring.png` |
| کارت وفاداری | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=loyalty) | `09-loyalty.png` |
| پرسشنامه‌ها | [باز کردن](https://develop.medyarco.com/nursing-patient-panel/?mns_panel=patient&mns_page=surveys) | `10-surveys.png` |
| موبایل — دراور باز (overlay زیر هدر) | — | `00-mobile-drawer-overlay.png` |

## موبایل (۰.۵.۳۶)

- علت افتادن overlay روی nav سایت: `.mns-dash__backdrop` با `z-index: 99990` بالاتر از هدر Elementor sticky (`z-index: 99`) بود.
- اصلاح: backdrop → `z-index: 90` تا زیر هدر بماند؛ سایدبار همچنان `100000`.
- با باز شدن دراور، `.mns-bottom-nav` با `translateY(110%)` + fade پایین می‌رود؛ با بستن، همان transition برعکس ظاهر می‌شود.
