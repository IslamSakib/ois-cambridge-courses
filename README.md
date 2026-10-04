# OIS Cambridge Courses

WordPress plugin for Oxford International School: a Student-ID + OTP verified purchase flow for the school's Cambridge English exam fees, built as a single shortcode (`[ois_cambridge_courses]`) so it drops straight into Elementor's Shortcode widget.

## Flow

1. **Student ID** — visitor enters their Student ID.
2. **Verify** — a 6-digit OTP is sent to the student's registered mobile (via the school's FEMS records) and verified on-screen (mobile shown masked).
3. **Choose & pay** — student details panel + an accordion of the Cambridge English levels. Opening a level shows its blurb (the course **Excerpt**; line breaks are kept, so Level / Age Group / Duration can each sit on their own line), price and **Pay now**, which creates the order and redirects straight to Moneybag's hosted checkout (no in-house checkout or payment-method page). Falls back to a dummy auto-approve gateway until a Moneybag key is set.
4. **Payment successful** — order confirmation with transaction ID and amount paid, reached either straight from the dummy gateway or after Moneybag redirects back and the order is confirmed paid (via its webhook and/or the verify API). **Download invoice** opens a printable A4 invoice with the amount in words and a PAID stamp.

## Admin

Sidebar menu **Cambridge Courses**:
- **Transactions** — colorful KPI cards + a searchable, sortable, paginated table of every order, with This Week / This Month / This Year filters and CSV export.
- **Courses** — manage the courses/exams (price, discount price, category, instructor, duration, image, description). Image and duration are optional and not shown on the storefront.
- **Settings** — FEMS student-records API, SMS OTP gateway, Moneybag payment gateway, test mode, and **access control**: pick which WordPress roles and/or individual users (besides Administrators) can see the Transactions dashboard.

## Payment gateway (Moneybag)

Set the Merchant API Key on the Settings page to go live — leave it blank to keep the dummy auto-approve gateway for testing. Once set:

- `POST /payments/checkout` creates a hosted-checkout session per order; the browser is redirected to Moneybag's `checkout_url` (no payment-method picker on this site).
- Moneybag POSTs an IPN to `/wp-json/ois-cc/v1/moneybag-ipn` (HMAC-SHA256 signed) — paste that URL and the webhook secret from Moneybag's dashboard into Settings. This is the authoritative source for marking an order paid.
- The page the browser lands on after checkout also re-checks the order's status via Moneybag's verify API as a UX nicety — it's never trusted to mark an order paid on its own.
- Base URL defaults to Moneybag's sandbox; switch it to production once real credentials are issued.

## Integration points for later

- `ois_cc_process_payment` filter — return `array('success' => bool, 'txn_id' => string, 'message' => string)` to override the dummy gateway path (only used while Moneybag isn't configured).
- FEMS API URL/key, SMS gateway URL/key, and Moneybag credentials are all set on the Settings page — leave blank to keep using dummy student data / logged (not sent) OTPs / the dummy payment gateway for testing.

## Install

Zip the `ois-cambridge-courses/` folder and upload via **Plugins → Add New → Upload Plugin**, or copy it into `wp-content/plugins/`. Activate, then place `[ois_cambridge_courses]` on any page (Elementor: Shortcode widget).

Author: Sakib Islam · 01950025990
