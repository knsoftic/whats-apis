# Meta Developer Console setup (WhatsApp Business Cloud API)

This guide gets the 8 values the **WhatsApp Accounts → Add** form needs, and connects Meta's webhook to this app.
Only Meta's **official Cloud API** is used — no QR code, no WhatsApp Web, no unofficial library.

| Form field | Where it comes from |
|---|---|
| Account Name | Anything your team recognises, e.g. "Sales – India" |
| Meta Business Account ID (optional) | business.facebook.com → Settings → Business info |
| WhatsApp Business Account ID (WABA ID) | App Dashboard → WhatsApp → **API Setup** |
| Phone Number ID | App Dashboard → WhatsApp → **API Setup** (the ID under the number — not the number itself) |
| Access Token | Permanent **System User** token (step 4) |
| Webhook Verify Token | Any random string you choose (the form has a **Generate** button) |
| App Secret | App Dashboard → App settings → **Basic** → App secret → Show |
| Graph API version | `v26.0` (default; latest stable at the time of writing — July 2026 release) |

---

## 1. Business portfolio
1. Open <https://business.facebook.com> and create (or select) your business portfolio.
2. Complete **Business verification** (Security Centre) — needed for higher messaging limits and display-name approval.

## 2. Create the Meta app
1. <https://developers.facebook.com> → **My Apps → Create App**.
2. Use case: **Connect with customers through WhatsApp** (app type *Business*). Link it to your business portfolio.
3. In the app, add the **WhatsApp** product. Meta creates a WhatsApp Business Account (WABA) and a free **test number**.

## 3. Add your real phone number
1. App Dashboard → WhatsApp → **API Setup → Add phone number**.
2. Enter display name + category, verify the number by SMS / voice call.
3. Wait for display-name approval (WhatsApp Manager shows the status).
4. Copy the **Phone Number ID** and **WABA ID** shown on API Setup.

> A number that is active in the normal WhatsApp / WhatsApp Business **app** must be deleted from that app first, or migrated.

## 4. Permanent access token (System User)
The token on the API Setup page expires after ~24 hours — use it only for a quick test.

1. business.facebook.com → **Settings → Users → System users → Add** (role **Admin**).
2. **Assign assets** to the system user:
   - **Apps** → your app → *Full control*
   - **WhatsApp accounts** → your WABA → *Full control*
3. **Generate new token** → choose your app → expiry **Never** → permissions:
   - `whatsapp_business_messaging` (send / receive)
   - `whatsapp_business_management` (phone details, templates, webhook subscription)
4. Copy the token (starts with `EAA…`). Paste it into the form — after saving it is encrypted and only shown masked.

## 5. App Secret
App Dashboard → **App settings → Basic → App secret → Show**. It is used to:
- verify every webhook (`X-Hub-Signature-256`), and
- sign API calls with `appsecret_proof` (so a wrong secret is detected immediately by "Test connection").

## 6. Add the account in this app
1. Log in → **WhatsApp Accounts → Add WhatsApp account** → fill the form → **Save & validate**.
2. The details page shows three separate statuses:
   - **API connection** — token + IDs confirmed by Meta (phone details are fetched automatically)
   - **Webhook verification** — becomes green only after step 7
   - **App subscribed to WABA** — step 8

Saving credentials alone never marks a number as working.

## 7. Configure the webhook
Meta can only call a **public HTTPS** URL (your VPS domain, or a tunnel during local testing — see LOCAL-INSTALLATION.md).

1. On the account details page copy the **Callback URL** — it looks like
   `https://your-domain.com/api/webhooks/whatsapp/<uuid>` — and click **Reveal** to copy the **Verify token**.
2. App Dashboard → WhatsApp → **Configuration → Webhook → Edit**: paste both → **Verify and save**.
   The account page now shows **Webhook verified**.
3. **Webhook fields → Manage** → subscribe:
   - `messages` — **required** (incoming messages + sent/delivered/read/failed statuses)
   - optional: `message_template_status_update`, `phone_number_quality_update`

> **One app = one callback URL.** If several numbers belong to the same Meta app, use the callback URL of any of them.
> The app routes each event to the right number by `phone_number_id` — but only to numbers saved with the **same App Secret**, so data can never cross between different apps.

## 8. Subscribe the app to the WABA
Click **Subscribe app to WABA** on the account page (calls `POST /{waba-id}/subscribed_apps`). Without it Meta does not deliver webhooks for that WABA.

## 9. Go live
- App Dashboard → **Publish / App mode: Live** (needs a Privacy Policy URL). In development mode Meta only sends webhooks for test numbers and app roles.
- WhatsApp Manager → **Payment settings**: add a payment method for business-initiated (template) messages.
- **Test number only:** add up to 5 recipient numbers on API Setup ("To" list), otherwise sending fails with error 131030.

## 10. Templates
- Create templates in **WhatsApp Manager → Message templates**; wait for approval.
- In this app: **Message Templates → Sync from Meta**. Approved templates can then be sent from the chat (outside the 24-hour window) or from the template page.

## 11. Messaging rules enforced by this app
| Rule | Behaviour |
|---|---|
| 24-hour customer service window | Free-form text/media only within 24 h of the customer's last message; otherwise the UI asks for a template |
| Business-initiated messages | Only **APPROVED** templates; blocked for contacts marked *opted out* |
| STOP / UNSUBSCRIBE | Incoming keyword marks the contact opted out; START opts in again |
| Rate limits (130429, 80007, 131056 …) | Message stays *pending* and is retried with back-off |
| Token / permission errors (190, 10, 200…) | Message fails, account is flagged **API failed** with the reason |

## Troubleshooting
| Symptom | Fix |
|---|---|
| "Verify and save" fails in Meta | URL must be HTTPS and public; verify token must match exactly; check `storage/logs/laravel.log` |
| Webhook verified but no messages arrive | Subscribe the `messages` field; click *Subscribe app to WABA*; app must be **Live**; queue worker must run |
| API failed: error 190 | Token expired/revoked — generate a System User token (step 4) |
| API failed: "App Secret does not match" | Copy the App Secret of the **same** app that issued the token |
| Error 131047 | 24 h window closed — send a template |
| Error 131030 | Test number: add the recipient to the allowed list |
