# Affiliate Referral (Medyar) — طرح همکاران مدیار

WordPress plugin for Medyar’s **affiliate / patient-referral** program: partners apply for panel access, refer patients to services, track statuses, and earn commissions (with optional Toman wallet integration).

**Author:** M.Mehdi Diaromidi  
**Stack:** WordPress 5.8+, PHP 8.0+, Faraz SMS, WooCommerce-friendly account hooks, Dr Plus theme integration  
**Version:** 1.2.0

---

## What it does

| Area | Capability |
|------|------------|
| **Onboarding** | Logged-in users submit name, mobile, job type, and optional documents → **review queue** |
| **Admin review** | Approve / reject applications; SMS to admins on submit and to applicants on submit / approve / reject |
| **Affiliate panel** | Refer patients, track referrals & commissions, profile, wallet bridge |
| **Admin console** | Applications queue, referrals, affiliates, finance rules, SMS settings |
| **Access UX** | “ورود طرح همکاران” / “ورود / عضویت طرح همکاران” in header & auth role switch |

---

## Architecture (high level)

```
affiliate-referral/
├── includes/          # Roles, DB repos, commission engine, SMS (Faraz), applications
├── admin/             # Menus, list tables, settings, CSV export
├── public/            # Page templates, AJAX, panel actions, signup & dashboard views
└── assets/            # Front CSS/JS (AJAX forms, charts, Jalali datepicker-friendly)
```

- **No shortcodes required** for core UX — dedicated page templates (`پنل طرح همکاران`, `ثبت‌نام طرح همکاران`).
- Custom tables for job types, services, referral statuses, referrals, commissions, settlements.
- Affiliate identity = WP role `affiliate` (granted **only after admin approval** since 1.2.0).
- Application state in user meta: `arp_affiliate_status` (`pending` | `approved` | `rejected`), optional private media attachments for documents.

---

## Application review flow (v1.2)

1. User logs in via **ورود طرح همکاران**.
2. Completes onboarding form (name, mobile, job; optional JPG/PNG/WEBP/PDF docs — max 3 × 5MB).
3. Status → `pending` (no panel role yet).
4. SMS → configured admins (“صف بررسی”) + applicant (“در صف بررسی”).
5. Admin opens **همکاران مدیار → درخواست‌ها** → Approve / Reject.
6. SMS → applicant with result; on approve → role + referral code + panel access.

**Security notes for uploads:** MIME/extension checks via `wp_check_filetype_and_ext`, size limits, `is_uploaded_file`, attachments stored as `private` with ownership meta.

---

## Integrations

- **Faraz SMS** — free-text via `farazsms_send_sms` by default; optional pattern codes (settings) send via `farazsms_send_pattern` when set (patient, affiliate status, admin signup/referral, applicant pending/approve/reject, wallet templates shared with Medyar Toman Wallet)  
- **Nightly pending digest** — hourly WP-Cron tick; at **22:00 Asia/Tehran** SMS summary of open admin queues (withdrawals, affiliate applications, waiting-contact referrals, optional docs/legacy commissions) to `arp_admin_sms_recipient_ids`  

- **Dr Plus** account menu filter `drplus/account_menu_items`, login redirect, icons  
- **Medyar Toman Wallet** (optional) for balances / insights links  
- **Persian WooCommerce** datepicker on panel pages when available  

---

## Setup (short)

1. Activate the plugin (creates tables & role on activation).  
2. Create pages with templates **پنل طرح همکاران** and **ثبت‌نام طرح همکاران**.  
3. Configure job types, services, statuses, and SMS recipients under **تنظیمات همکاران**.  
4. Select admin recipients with valid `billing_phone` for application alerts.

---

## Resume / portfolio highlights

- End-to-end **affiliate CRM** in WordPress (front panel + admin ops).  
- **Approval workflow** with secure multi-file upload and SMS notifications.  
- Clean separation of repos, AJAX JSON contracts, and progressive enhancement (forms work without JS).  
- Production hardening: nonce checks, capability gates, rate-friendly SMS queue patterns, RTL / Persian UX.

---

## License

Proprietary — Medyar / author. Not for redistribution without permission.
