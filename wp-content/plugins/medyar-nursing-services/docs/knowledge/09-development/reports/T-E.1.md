# Report T-E.1 — Patient order-view (real)

## Change
- `MNS_Nurse_Access::is_real_panel_user( 'patient' )` gates live path.
- `MNS_Patient_Orders_Api::view_model_for()` maps `get_for` → template keys (`status`, `service`, `patient`, `when`, `nurse`, `can_cancel`, `tracking`, …).
- Invalid / non-owned code → soft 404 partial.
- Cancel UI posts `POST /orders/{code}/cancel?_envelope=1` (`initOrderViewCancel` in `public.js`); refresh on success; Persian error for bad state.
- Admin/dev fixture + `mns_state` path unchanged.

## Structure/CSS
Unchanged.
