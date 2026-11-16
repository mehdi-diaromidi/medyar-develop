# Report T-E.3 — Orders lists + nurse live views

## Patient `page-orders.php`
- Real user: `list_for` only (≤50); list items include `patient_id` / name / nurse / url from API.

## Nurse
- `page-orders.php`: live `list_for`; enriched list items (`tab`, `tracking`, `url`, `area`, `patient`, phone only when assigned/in_progress).
- `page-dashboard.php`: live upcoming agenda; fixture blocks cleared for real nurse.
- `page-order-view.php`: `view_model_for` / soft 404; address+phone only when API allows (assigned/in_progress); no amounts.
- `page-availability.php`: `$use_real` via shared helper.

## Note
Start/end service actions remain preview-style for real users until M4 transitions exist; contact fields are live.
