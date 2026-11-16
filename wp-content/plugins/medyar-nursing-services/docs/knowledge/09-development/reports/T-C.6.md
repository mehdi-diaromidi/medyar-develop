# Report T-C.6 — Small hardenings

## Files
- `includes/services/class-mns-patients.php` — GET_LOCK ensure_self, max 20 patients, name ≤100
- `includes/services/class-mns-orders.php` — notes/address ≤500, IR mobile `09\d{9}`
- `includes/services/class-mns-nurse-orders-api.php` — essentials whitelist
- `includes/services/class-mns-patient-orders-api.php` — notes cap on patch

## Notes
`slot_known` already enforced on create/A-13; nurse schedule already validates known slots; patient patch still rejects date/slot changes.
