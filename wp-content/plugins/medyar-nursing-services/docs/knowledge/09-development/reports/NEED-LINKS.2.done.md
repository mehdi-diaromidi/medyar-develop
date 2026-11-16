# NEED-LINKS — RUN-D (patient again)

Patient token from `.e2e/links.json` (mtime 2026-11-17 02:41) was consumed. After login, `/nursing-patient-panel/?mns_page=orders` returned **CDN-cached HTML** naming `M.Mehdi Diaromidi` with `rest_cookie_invalid_nonce` (403) while the dashboard briefly showed `mns_e2e_patient`. Browser then crashed.

Please refresh **at least `patient`** (ideally all three) into `.e2e/links.json`.

Also: WAF blocks `/wp-admin/tools.php?page=mns-e2e` and `/wp-login.php?action=logout` from the automation browser — generate links from a normal admin session.

`nurse_f` / `nurse_m` fresh tokens in the current file are still unused if you want to leave them.
