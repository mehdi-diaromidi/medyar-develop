# Report T-E.4 — M4+ «به‌زودی» for real users

## Helper
`MNS_UI::soon_for_real( $panel )` → preview banner + empty state; returns true so the view exits.

## Gated pages (real users only; admin/dev unchanged)
- Nurse: `checklist`, `drug`, `report` (**not** `page-ratings.php`).
- Patient: `invoices`, `invoice-view`, `loyalty`, `recurring`, `surveys`.

Nav links still resolve; content is the soon banner, not fixtures.
