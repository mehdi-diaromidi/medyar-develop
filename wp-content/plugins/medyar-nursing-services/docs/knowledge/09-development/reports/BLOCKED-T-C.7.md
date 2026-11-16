# BLOCKED T-C.7 — Alternate contact OTP

## Finding
- Dr Plus OTP (`dr-plus/inc/sms/SMSUtils.php` + OTP model) is **auth-only** (login/register/lost-password). No public helper to send/verify an arbitrary contact-phone OTP for an already logged-in patient.
- `MSQ_Client::enqueue` can queue SMS with a pattern, but there is **no verify API**; building a full OTP verify flow would require either theme edits (forbidden) or inventing SMS content/patterns without a product decision.

## What exists today
- `MNS_Orders::create_from_request` correctly throws `ERR_OTP_REQUIRED` when `contact_phone` ≠ account mobile.
- No `POST /patient/phone-otp/send|verify` implemented (would need real SMS + verify store).

## Options for later
1. Thin wrapper around a new MNS-owned OTP (transient hash + MSQ pattern `sms_pattern_phone_otp`) — needs pattern id from ops.
2. Ask Dr Plus for a reusable `send_otp($phone)/verify_otp($phone,$code)` public API, then wire the two routes inside the plugin only.
3. Keep hard reject until product picks (1) or (2).

## Decision this run
Wrote this blocker; did not add routes or theme changes.
