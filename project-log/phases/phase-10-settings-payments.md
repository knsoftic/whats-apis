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
