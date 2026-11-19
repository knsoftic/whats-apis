# Remaining work — क्या बाकी है

[← Back to INDEX](INDEX.md)

**Last updated:** 2026-11-19 14:35

## Remaining by phase

| Phase | Remaining tasks | Details |
|---|---|---|
| 1 — Foundation | 0 of 16 | [phase-1-foundation.md](phases/phase-1-foundation.md) |
| 2 — WhatsApp accounts | 0 of 13 | [phase-2-whatsapp-accounts.md](phases/phase-2-whatsapp-accounts.md) |
| 3 — Webhooks | 0 of 13 | [phase-3-webhooks.md](phases/phase-3-webhooks.md) |
| 4 — Sending | 0 of 10 | [phase-4-sending.md](phases/phase-4-sending.md) |
| 5 — Real-time chat | 0 of 12 | [phase-5-realtime-chat.md](phases/phase-5-realtime-chat.md) |
| 6 — Media, contacts, templates | 0 of 12 | [phase-6-media-contacts-templates.md](phases/phase-6-media-contacts-templates.md) |
| 7 — Security, testing, deploy | 0 of 11 | [phase-7-security-testing-deploy.md](phases/phase-7-security-testing-deploy.md) |
| **Total** | **0 of 87 code tasks** | All built and covered by automated tests |

## Things only you can do (to go live)
| # | Item | Guide |
|---|---|---|
| U1 | Create the Meta app, add WhatsApp, add your number, create a **System User permanent token**, copy WABA ID / Phone Number ID / App Secret | [docs/META-SETUP.md](../docs/META-SETUP.md) |
| U2 | Add the number in **WhatsApp Accounts** → "Test connection" must turn green | META-SETUP §6 |
| U3 | Get a public **HTTPS** URL: deploy to the VPS, or use a tunnel locally | [docs/VPS-DEPLOYMENT.md](../docs/VPS-DEPLOYMENT.md) · [docs/LOCAL-INSTALLATION.md](../docs/LOCAL-INSTALLATION.md) §4 |
| U4 | In Meta: paste Callback URL + Verify token → Verify and save → subscribe `messages` → "Subscribe app to WABA" | META-SETUP §7–8 |
| U5 | Run manual checks M6–M10 in [TESTING.md](TESTING.md) with a real phone | |
| U6 | Change the Super Admin password after first login; on the VPS set a real `ADMIN_EMAIL` | |
| U7 | (Optional) Delete the local "Demo Account (sample data)" | WhatsApp Accounts page |
| U8 | (Optional) Upgrade local PHP to 8.3+ to match production | |

## Possible future improvements (not in the original spec)
- Sending interactive button / list messages and location from the dashboard
- Assigning conversations to specific agents (column exists: `assigned_user_id`)
- Uploading template header media instead of using a link
- Creating new templates from the dashboard (currently created in WhatsApp Manager, then synced)
- Bulk / broadcast template campaigns with opt-in filtering
