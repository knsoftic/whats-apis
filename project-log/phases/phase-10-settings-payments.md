# Phase 10 — Settings center & PKPay self-purchase

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Code + tests (223 passing) · ⏳ live PKPay payment needs merchant credentials + server IP allowlist |
| **Date** | 2026-11-20 |
| **Requested by user** | Settings page (name, theme, colour, default dark/light, currency, payment methods, more); PKPay gateway from https://pkpay.site/docs with automatic plan activation on callback; credentials editable by admin |

## Settings (sidebar → Settings, Super Admin)
| Tab | What |
|---|---|
| General | Website/app name, tagline, support email/phone, logo upload (PNG/JPG/WebP), public registration on/off |
| Appearance | Brand colour (11 palettes, applied app-wide via CSS variables; status colours stay semantic), default theme (system/light/dark), allow user theme toggle |
| Payments | Default currency, self-purchase on/off, PKPay (base URL, merchant ID, API key `pk_…`, secret `sk_…` — encrypted, masked), manual payment + instructions |
| Email (SMTP) | Host/port/encryption/user/password/from; applied at runtime; "Send test email" |
| Legal pages | Company, contact email, address, jurisdiction (override .env) |
| Meta Tech Provider | Existing Embedded Signup settings (now a tab) |

## PKPay integration (per docs read 2026-11-20)
- Base `https://pkpay.site/api/v1`, PKR only, amount 1–500,000, Raast QR (expires 5 min).
- Requests signed: `X-Api-Key`, `X-Timestamp`, `X-Signature = HMAC-SHA256(ts\nMETHOD\nURI\nBODY, secret)`, `Idempotency-Key` on POST.
- Flow: Billing → Buy plan → `POST /payments` → redirect to `checkout_url` → PKPay callback `POST /api/payments/pkpay/callback` verified with `HMAC-SHA256(ts.raw_body, secret)` → amount / currency / order checked → payment paid → **plan activated** (monthly +1 month, yearly +1 year, lifetime no expiry; renewals extend from the current expiry). Idempotent (retries never extend twice). Sandbox callbacks ignored.
- Return page re-checks `GET /payments/{id}` and auto-refreshes; `payments:sync` every 5 min catches missed callbacks and expires old pending payments.
- Manual payment: customer sees instructions, submits transaction ID; Super Admin approves on **Payments** → plan activated.
- Customer **Billing & Plan** page: current plan + usage, plans, free-plan switch, payment history.

## Tests
`SettingsTest` (6), `PaymentTest` (11) → **223 passed (934 assertions)**.

## Needs from you
1. PKPay merchant portal: API key + secret → Settings → Payments.
2. Ask the PKPay admin to put the **server IP** on the pay-in allowlist.
3. Plans you sell through PKPay must be priced in **PKR** (edit plan currency).
4. Settings → Payments: tick Enable PKPay + Self-purchase.

## Update 2026-11-20 — Super Admin can change a payment status
- **Payments** page: a **Change status** button on every payment, shown only to the **Super Admin**. It is highlighted for expired, failed and cancelled payments. The server checks the Super Admin role too, so even an Admin who was given `plans.manage` gets 403.
- The popup shows customer, plan, amount and current status. Pick the new status (paid, pending, expired, failed, cancelled, rejected) and add an optional note.
- Marking a payment **paid** can activate the customer's plan ("Activate the customer's plan" box, ticked by default). Monthly plans get +1 month, yearly +1 year, and renewals extend the current expiry.
- A plan is activated **at most once per payment**: paid → expired → paid, or a later PKPay "paid", never adds time twice (`meta.plan_activated_at`).
- Moving a payment away from paid does **not** remove the customer's plan; the message says to edit it on Users if needed.
- Every change is added to the payment's history note (date · admin: from → to — note) and to Activity Logs (`billing.payment_status_changed`).
- A payment the Super Admin sets back to **pending** is skipped by the `payments:sync` auto-expire and PKPay re-check.
- **Check** (ask PKPay) now also shows for expired or failed PKPay payments, so a late real payment can be confirmed.
- Tests: `PaymentStatusChangeTest` (7) → **245 passed (1082 assertions)**. Browser-checked: expired → paid with a note → plan activated.
