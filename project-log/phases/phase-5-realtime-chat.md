# Phase 5 — Real-time WhatsApp chat interface

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Done and tested (automated + live in browser) |
| **Started** | 2026-11-19 |
| **Finished** | 2026-11-19 |
| **Spec sections** | §5 Chat interface, §10 Real-time messaging, §13 UI/UX |

## Goal
A WhatsApp Web-style chat screen that updates by itself (no page refresh) through Laravel Reverb + Echo on authorized private channels.

## Tasks

| # | Task | Status | Notes |
|---|---|---|---|
| 5.1 | Laravel Reverb + Echo | ✅ | Reverb 1.14 (installed from source — see Issues), laravel-echo 2.6, pusher-js 8.6 |
| 5.2 | Private channels + authorization | ✅ | `whatsapp.account.{id}` → active user + `chats.view` + account access |
| 5.3 | Broadcast events | ✅ | `message.received`, `message.updated`, `message.status`, `conversation.read` (queued, after DB commit) |
| 5.4 | Chat layout | ✅ | Left list + right chat, WhatsApp wallpaper & bubble colours, light/dark |
| 5.5 | Account switcher | ✅ | Dropdown in the list header + global switcher in the top bar |
| 5.6 | Search & filters | ✅ | Contact search, filters All / Unread / Open / Closed |
| 5.7 | List: preview, time, unread badge | ✅ | "You:" prefix for outgoing, Today / Yesterday / date |
| 5.8 | History pagination | ✅ | 40 messages per page, loads older ones when you scroll up, date separators |
| 5.9 | Bubbles & ticks | ✅ | pending 🕒, sent ✓, delivered ✓✓, read blue ✓✓, failed ⚠ + reason + Retry; reactions shown under bubble |
| 5.10 | Composer | ✅ | Enter to send, Shift+Enter for a new line, reply quote, attachments ("send as document"), template button, 24 h window banner |
| 5.11 | Mark as read | ✅ | Automatic on open (+ Meta read receipt), "Mark as unread" in the menu |
| 5.12 | Live updates, unread counter, message search, channel tests | ✅ | 10 s polling fallback when the socket is offline; "Search inside messages" mode |

Also renders: images (inline), video player, audio / voice-note player, document cards with download, location (Google Maps link), contact cards, template badge, button replies, *bold* / _italic_ / ~strike~ / links (HTML-escaped first, so no XSS).

## Live verification (2026-11-19, in the in-app browser)
1. Started `php artisan reverb:start` + `php artisan queue:work` → chat header showed **"Live updates connected"**.
2. Sent a **signed** Meta-format webhook to the local endpoint (demo number) → response `200 EVENT_RECEIVED`.
3. Within ~2 s the new message appeared in the open chat **without refresh**, the conversation moved to the top and the 24 h window reset → ✅ end-to-end real-time works.
4. Template modal: variable inputs + live WhatsApp-style preview ✅. Dark mode ✅.

## Files created / changed
| Area | Files |
|---|---|
| Controllers | `app/Http/Controllers/Chat/ChatController.php`, `app/Http/Controllers/MediaController.php` |
| Channels | `routes/channels.php` |
| Frontend | `resources/js/chat.js` (Alpine component), `resources/js/echo.js`, `resources/js/app.js`, `resources/css/app.css` (wallpaper) |
| Views | `resources/views/chat/index.blade.php` |
| Config | `config/broadcasting.php`, `config/reverb.php`, `.env` (REVERB_*), `composer.json` (`composer run dev` = server + queue + reverb + vite) |
| Demo data | `database/seeders/DemoDataSeeder.php` (optional, labelled demo number) |
| Tests | `tests/Feature/ChatTest.php` (13) |

## How to test this phase
1. `composer run dev` (or serve + queue:work + reverb:start + npm run dev).
2. Optional: `php artisan db:seed --class=DemoDataSeeder` for sample chats.
3. Open **Chats** → green dot "Live updates connected".
4. Send a WhatsApp message to your real number (webhook configured) → appears instantly; unread badge updates.

## Issues found & fixes
| Issue | Fix |
|---|---|
| `composer require laravel/reverb` zip download "Permission denied" (Windows antivirus) | Installed with `--prefer-source` (git clone) |
| `install:broadcasting` left two `BROADCAST_CONNECTION` lines in `.env` | Removed the duplicate, re-ran `reverb:install` |
| Breeze's `composer run dev` uses `pail`, which needs `pcntl` (not on Windows) | Dev script now runs server + queue + reverb + vite; `composer run dev:logs` for pail on Linux/macOS |
| Blade `{{ }}` inside Alpine expressions | Escaped with `@{{` / HTML entities |
| `*bold*.` (punctuation after) not bold | Formatter regex allows punctuation after the closing marker |
