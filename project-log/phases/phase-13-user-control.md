# Phase 13 — Login as user, suspend / block, locked customer numbers

[← Back to INDEX](../INDEX.md)

| | |
|---|---|
| **Status** | ✅ Code + tests (269 passing) · browser-checked |
| **Date** | 2026-11-22 |
| **Requested by user** | A “Login as user” button on Users, so the admin can review customers; suspend and block for customer accounts; a customer who has attached a WhatsApp number must not be able to delete it or add a new one beyond the plan; only the admin may remove a number (e.g. a plan with 2 numbers: no 3rd, and the 2 can't be deleted). |

## Login as user (Users page → “Login as”)
- **Super Admin only**, for any user except another Super Admin or yourself.
- You then see the app exactly as that user, with an amber bar at the top: “Logged in as … — Return to admin”. Suspended and blocked users can be reviewed too.
- **Return to admin** takes you back to Users.
- Not counted as the user's own login (their `last_login_at` is not touched).
- Audit: both events go to Activity Logs. Anything done while logged in as the user is logged with `impersonated_by` = the admin's id.
- While logged in as a user you **cannot change their password or profile**.

## Suspend / block (Users page → “Suspend / Block”, then “Re-activate”)
| | Can sign in? | What they see | Their WhatsApp numbers |
|---|---|---|---|
| **Suspended** | yes | only a “Your account is suspended” page with the reason + support contact + Log out | sending stopped, bots paused, can't add numbers |
| **Blocked** | no (signed out at once; even the correct password gives “This account has been blocked”) | — | same |
- The reason is optional and is shown to a suspended user. There is a filter by status, and a coloured row and badge for each status.
- Super Admins and yourself cannot be suspended or blocked. Nothing is deleted, and you can re-activate at any time.
- Stored in `users.status`, `status_reason`, `status_changed_at`, `status_changed_by` (migration `2026_11_22_100000`).
- Enforced by `EnsureUserIsActive` (web middleware), the login check, and `PlanGate`: a suspended or blocked owner means no send, no bots and no new numbers.

## Customers' WhatsApp numbers are locked
- **Customers cannot delete** a connected number (policy `delete` = staff only). The Danger zone is hidden; instead they see a note: “can't be removed or swapped — contact support; you can still disable it”.
- **Customers cannot swap the number** on an existing account: WABA ID and Phone Number ID are read-only and rejected by the server. They can still rename it and refresh the token, secret and verify token.
- **Plan limit**: the WhatsApp Numbers page shows “X / Y numbers used”. When full, the Add / Connect buttons disappear, with a note to upgrade or ask support. Adding manually or via Embedded Signup is refused by `PlanGate`. Disabled numbers still count.
- **Admins** can still delete any number. The Danger zone shows the owning customer and “frees one number slot on their plan”.

## Tests
`UserAccessControlTest` (7) covers:
- login as + return; banner shown; admin pages blocked for the viewed user; password change blocked; `last_login_at` unchanged; audit logs;
- only the Super Admin can log in as someone, never as another Super Admin;
- suspend: notice page with reason, JSON 403, no send, bots off; the admin can still review; re-activate;
- block: signed out, cannot sign in with the correct password;
- Super Admin and yourself cannot be suspended;
- customer cannot delete or swap a number but can rename it; admin can delete it;
- a plan with 2 numbers blocks a 3rd (page, create form, POST).

**269 passed (1326 assertions).**

## Browser check (local)
- Users page: Login as, Suspend / Block dialog, suspended row with reason, Re-activate.
- Login as → amber bar “SUSPENDED”, customer menu, WhatsApp Numbers “0 / 1 used” → Return to admin.
- Fixes during the check: actions were pushed off-screen (merged the Plan and Last-login columns, icon-only Edit/Delete); an invalid Blade directive (`@elseunless`) printed text on the WhatsApp Numbers page, now fixed and covered by a test.
