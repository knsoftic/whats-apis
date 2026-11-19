# Status report — क्या काम कर रहा है / क्या नहीं

[← Back to INDEX](INDEX.md)

**Last updated:** 2026-11-19 14:35

---

## ✅ Working (done and tested)

| Area | What works | Proof |
|---|---|---|
| Login / security | Login, logout, password reset, profile, inactive users blocked, login rate limit, registration disabled | 32 auth tests + browser |
| Roles | Super Admin / Admin / Agent, 13 permissions, per-number access for Agents | Authorization + isolation tests |
| User management | Create / edit / deactivate / delete users, assign numbers, last Super Admin protected | UserManagementTest |
| Dashboard | Connected numbers, contacts, sent, received, failed, unread, recent conversations, 7-day chart, number health | Browser + tests |
| Activity logs | Logins, failed logins, user/account/contact/template/message actions; secrets redacted | Tests |
| WhatsApp Accounts | Add / edit / delete / enable / disable; encrypted secrets; masked tokens; validate with Meta; fetch phone details; test connection; subscribe app to WABA; **separate** API / webhook / subscription statuses | 22 tests (Meta mocked) |
| Webhooks | GET verification, POST signature check, duplicate-safe storage, queued processing, all message types, statuses, template-status & quality webhooks, multi-number routing for one app, STOP/START opt-out | 31 tests + live local signed webhook |
| Media | Incoming media downloaded via Meta media API to private storage (SHA-256 checked); outgoing upload; safe serving (only image/video/audio inline) | Tests |
| Sending | Text, reply, image, video, audio, document, template; 24 h window; queue + retries; Meta error mapping; retry button | 24 tests |
| Real-time chat | Reverb + Echo private channels, live new messages / ticks / unread counters, polling fallback | 13 tests + **live browser test** |
| Contacts | List, search, filters, add/edit, tags, notes, consent, history, CSV import/export | 9 tests |
| Templates | Sync from Meta (paginated), list, preview, send with validated variables | 14 tests |
| Ops | Hourly credential check, daily pruning, retry failed webhooks command | SecurityTest |
| UI | Branded layout, sidebar, mobile drawer, dark/light mode, toasts, empty states, loading spinners | Browser + PageSmokeTest |

## 🟢 Built, not yet verified with a REAL Meta account
Everything that talks to Meta is tested with **faked** Meta responses that follow Meta's documented formats. A real run needs your credentials:

| Feature | What is needed from you |
|---|---|
| Credential validation / phone details | Real WABA ID, Phone Number ID, System User token, App Secret |
| Receiving real messages | Public HTTPS URL (VPS, or a cloudflared/ngrok tunnel) + Meta webhook "Verify and save" + `messages` field |
| Sending real messages | Same as above + a customer who messaged you in the last 24 h (or an approved template) |
| Template sync | Templates created in WhatsApp Manager |

## ❌ Not working / failed
_Nothing known._ All 158 automated tests pass.

## ⚠️ Works with a limitation

| Limitation | Details / workaround |
|---|---|
| Local PHP is 8.2 (spec asks 8.3+) | Laravel 12 used; code is 8.3/8.4-compatible. Use PHP 8.3+ on the VPS (docs say so) |
| Real-time needs 2 background processes | `php artisan queue:work` and `php artisan reverb:start` must run (`composer run dev` starts both locally; Supervisor on the VPS). Without Reverb the chat refreshes every 10 s |
| Template headers of type LOCATION | Not supported in the send form yet (Meta rarely uses them); TEXT / IMAGE / VIDEO / DOCUMENT headers work |
| Media for templates | Header media is sent as a public HTTPS **link** (no upload from the template form) |
| Sending interactive (buttons/list) or location messages *from* the dashboard | Not in the spec; receiving them works |
| `php artisan pail` (live logs) | Needs `pcntl`, unavailable on Windows → `composer run dev:logs` works on Linux/macOS only |
| Composer on this Windows PC | Antivirus sometimes blocks zip downloads; retry or use `--prefer-source` |

## 🚧 Blockers
_None._ Remaining items only need your Meta credentials / server (see [REMAINING.md](REMAINING.md)).

## Currently running in this session (local)
- Dev server (in-app browser preview), Reverb on port 8080, queue worker — they stop when the session/PC restarts. Start again with `composer run dev`.
- Local DB contains the optional **"Demo Account (sample data)"** — delete it from WhatsApp Accounts when no longer needed.

## Environment notes (found at start, 2026-11-19)
| Item | Found | Impact |
|---|---|---|
| PHP | 8.2.12 (XAMPP) | See limitation above |
| MySQL | MariaDB 10.4.32 (XAMPP), was **not running** | Started from the command line; start it from XAMPP Control Panel next time |
| Composer / Node | 2.10.2 / 24.18.0 | OK |
| PHP `intl` | Not enabled | Not needed by the app |
