# Report T-C.2 — Prescription upload

## Files
- `includes/rest/class-mns-rest-patient.php` — `POST /patient/files`
- `includes/services/class-mns-files.php` — `attach_order`, `store_bytes`, orphan cron
- `includes/backend/class-mns-backend-files.php` — schedule orphan cron
- `includes/class-mns-activator.php` — unschedule on deactivate
- `includes/services/class-mns-orders.php` — attach rx after create
- `assets/js/public.js` — upload on file select, send `essentials.rx_file_id`

## Notes
Upload uses `store_upload(..., $is_photo=true)`. Orphans: prescription without order_id older than 24h, daily cron `mns_files_purge_orphans`.
