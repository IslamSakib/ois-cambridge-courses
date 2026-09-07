# OIS Cambridge Courses

WordPress plugin for Oxford International School: a Student-ID + OTP verified purchase flow for the school's 7 Cambridge courses, built as a single shortcode (`[ois_cambridge_courses]`) so it drops straight into Elementor's Shortcode widget.

## Flow

1. **Student ID** — visitor enters their Student ID.
2. **OTP** — an OTP is sent to the student's registered mobile (via the school's FEMS records) and verified on-screen.
3. **Course grid** — the 7 Cambridge courses, card layout.
4. **Course detail** — price breakdown + Buy Now.
5. **Checkout** — form auto-filled from the verified student's FEMS data.
6. **Payment** — dummy gateway (auto-approves) until the school's real gateway credentials are wired in.
7. **Thank you** — order confirmation with transaction ID and amount paid.

## Admin

Sidebar menu **Cambridge Courses**:
- **Transactions** — colorful KPI cards + a searchable, sortable, paginated table of every order, with This Week / This Month / This Year filters and CSV export.
- **Courses** — manage the 7 courses (price, discount price, category, instructor, duration, image, description).
- **Settings** — FEMS student-records API, SMS OTP gateway, test mode, and **access control**: pick which WordPress roles and/or individual users (besides Administrators) can see the Transactions dashboard.

## Integration points for later

- `ois_cc_process_payment` filter — return `array('success' => bool, 'txn_id' => string, 'message' => string)` to plug in the school's real payment gateway; the dummy gateway is used until then.
- FEMS API URL/key and SMS gateway URL/key are set on the Settings page — leave blank to keep using dummy student data / logged (not sent) OTPs for testing.

## Install

Zip the `ois-cambridge-courses/` folder and upload via **Plugins → Add New → Upload Plugin**, or copy it into `wp-content/plugins/`. Activate, then place `[ois_cambridge_courses]` on any page (Elementor: Shortcode widget).

Author: Sakib Islam · 01950025990
