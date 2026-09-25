# User Acceptance Testing Checklist (Phase 8)

Run on **staging** ([PRODUCTION_DEPLOYMENT.md §11](PRODUCTION_DEPLOYMENT.md#11-staging-environment)) with the client's own configuration, before go-live. Each tester signs their role section. Record failures with: URL, role, steps, expected, actual, screenshot (no passwords or customer phone numbers in tickets).

Legend: ☐ not run · ✅ pass · ❌ fail (ticket #) · N/A

---

## 1. UAT users

Create real named accounts (never the local demo accounts; `*@salescrm.local` must not exist on staging or production):

| Role | Accounts | Setup |
|------|----------|-------|
| Super Admin | 1 (client owner / IT) | `php artisan crm:create-super-admin` |
| Admin | 1 | Users → Add |
| Sales Manager | 1 | Users → Add |
| Sales Executive | 3 | Users → Add |
| Inactive user | 1 executive, then deactivated | Used to verify blocked login |

There are no teams. Access is **Own / All** only, so no team or manager is set on users.

Test data: 20–30 leads per salesperson created by hand or through the Meta test tool, a few unassigned, a few in each status, one archived. Use test phone numbers that belong to the client's staff.

## 2. Role matrix (client-facing)

Default access. The Super Admin can change Admin, Manager and Executive permissions on **Roles & Permissions**; the Super Admin role itself is fixed. There are only two visibility levels:

- **All:** holds the `*.view_all` permissions, Admin / Super Admin by default.
- **Own:** leads assigned to the user, plus follow-ups, meetings and calls on those leads or assigned to the user.

There is **no team visibility**. A Sales Manager sees only their own records unless the Super Admin grants a view-all permission. Unassigned leads are visible to Admin / Super Admin only.

| Capability | Super Admin | Admin | Sales Manager | Sales Executive |
|------------|:-----------:|:-----:|:-------------:|:---------------:|
| See leads | All (incl. unassigned) | All (incl. unassigned) | Own (assigned) only | Own (assigned) only |
| Create / edit leads, change status | ✅ | ✅ | ✅ (own) | ✅ (own) |
| Assign / reassign leads | ✅ any lead → any user | ✅ any lead → any user | Own leads → any user (then loses access) | ❌ |
| Bulk actions, import | ✅ | ✅ | ❌ | ❌ |
| Archive leads / restore archived | ✅ / ✅ | ✅ / ❌ | ❌ | ❌ |
| Follow-ups & meetings | All | All | Own; can assign own-lead items to others | Own |
| Upload attachments / download attachments | ✅ / ✅ | ✅ / ✅ | ✅ / ✅ (own leads) | ✅ / ❌ |
| Make & receive calls, log outcome | ✅ | ✅ | ✅ (own leads) | ✅ (own leads) |
| See calls | All | All | Own | Own |
| Listen to recordings / download recordings | ✅ / ✅ | ✅ / ❌ | ✅ (own calls) / ❌ | ❌ / ❌ |
| Dial a number that is not a lead's | ✅ | ✅ | ❌ | ❌ |
| Reports & dashboard | Company (incl. Unassigned), salesperson filter | Company (incl. Unassigned), salesperson filter | My data only | My data only |
| Export report CSV | ✅ | ✅ | ❌ (grantable) | ❌ (never by design) |
| Users | Manage | Manage (no delete) | View | ❌ |
| Roles & permissions | Manage | View | ❌ | ❌ |
| Settings, lead/follow-up/meeting configuration, branding | ✅ | ✅ | ❌ | ❌ |
| Telephony configuration | ✅ | ✅ | ❌ | ❌ |
| Facebook / Meta integration | ✅ | ❌ (grantable) | ❌ | ❌ |
| Audit logs & login history | ✅ | ✅ | ❌ | ❌ |

Fixed rules: salespeople never export or bulk-download data; audit logs cannot be edited or deleted; nobody can change their own role or deactivate themselves; only a Super Admin can create another Super Admin. Technical detail: [PERMISSIONS.md](PERMISSIONS.md).

## 3. Super Admin

- ☐ Log in; System settings shows **Version 1.0.0**; company name, logo, favicon appear on login page and sidebar; "Powered by Buildify360" footer present
- ☐ Create Admin, Manager, Executive users; each receives access; deactivate one → that user cannot log in and active sessions end
- ☐ Roles: grant `report.export` to one manager, verify they can export; revoke, verify 403
- ☐ Integrations → Facebook: connect, select Page, map form, **submit a live test lead** → lead created, assigned per rule, assignee notified (in-app + browser push if enabled)
- ☐ Integrations → Facebook: webhook events list shows the test event; no tokens visible anywhere
- ☐ Telephony: health check passes; one calling account per agent
- ☐ Audit logs: user creation, role change, login, export, recording play are recorded; no passwords/tokens in entries
- ☐ `crm:create-super-admin` refuses a weak password and never shows the password

## 4. Admin

- ☐ Sees all leads, follow-ups, meetings, calls; dashboard shows company figures and an **Unassigned leads** card
- ☐ Sidebar has no Teams entry; `/admin/teams` and `/reports/teams` → not found; user form has no Team / Manager fields
- ☐ Assignment rules: any legacy team rule shows "Deprecated — not running" and cannot be switched on; round robin uses selected users
- ☐ Lead settings: add a status, source, lost reason, campaign, custom field → available in lead form
- ☐ Assignment rule: new manual/Facebook lead goes to the expected person
- ☐ Bulk assign 10 leads; bulk status change; archive one lead (it disappears for executives)
- ☐ Reports: every report loads with company filter; export CSV → file downloads once; the link stops working after expiry (default 24 h) and cannot be opened by another user
- ☐ Cannot open Integrations → Facebook (403) unless granted; cannot delete users; cannot restore archived leads
- ☐ Settings → Security: password minimum length (12) enforced on user creation/reset
- ☐ Recording: can listen, cannot download (no button; direct URL → 403)

## 5. Sales Manager

- ☐ Sees **only leads assigned to them**: an executive's lead is not found by name/phone/lead number; no unassigned leads
- ☐ Opening an executive's lead URL directly → 403 / not found
- ☐ Reassigns one of their own leads to an executive → the lead disappears for the manager ("You no longer have access to this lead"); only the new owner is notified
- ☐ Follow-ups, meetings, calendar and calls show only their own items; conflict warning when double-booking; override is audited
- ☐ Reports and dashboard show "My" data only, with no salesperson filter; no export button (a crafted `POST /reports/{report}/export` → 403, audited as export attempt)
- ☐ Can play recordings of their own calls; cannot download
- ☐ Downloads an attachment on their own lead (allowed)
- ☐ No access to Settings, Users edit, Roles, Telephony config, Audit (403)

## 6. Sales Executive

- ☐ Dashboard shows own work only (today's follow-ups, meetings, new leads)
- ☐ Lead list shows only assigned leads; search never reveals others' leads
- ☐ Create a lead; duplicate phone warning does not reveal another person's lead details
- ☐ Change status; lost requires a reason; won updates the pipeline
- ☐ Schedule, reschedule, complete, cancel a follow-up; reminder notification arrives on time (in-app, browser push if enabled)
- ☐ Schedule an internal (no-lead) meeting with a colleague; colleague sees it and can RSVP. Colleagues who can't see a lead are not offered as participants for that lead's meeting
- ☐ **Click-to-call** from Lead 360: phone rings, call connects, call record created, outcome form required after a connected call
- ☐ **Incoming call** from a lead's number: screen-pop shows the lead (only if assigned to this executive)
- ☐ Missed call appears and can be called back
- ☐ Cannot listen to or download recordings; cannot dial arbitrary numbers
- ☐ Can upload an attachment; cannot download attachments
- ☐ Reports show own data only; **no export anywhere**; no bulk actions, import, delete, reassign
- ☐ URLs for `/admin/*`, other users' leads, and a crafted report export request → 403 / not found
- ☐ After being reassigned away from a lead, that lead and its notes, attachments, calls, follow-ups and meetings disappear; an open lead page shows "You no longer have access to this lead"

## 7. Telephony staging tests (Exotel)

- ☐ **Release-blocking:** `resources/js/Composables/useTelephony.js` verified against the client's Exotel WebRTC SDK version (constructor, `Initialize`, `MakeCall`, event names, mute/hold). Verified by: ______ SDK version: ______
- ☐ Outbound: answered / busy / no answer / wrong number produce the right final status
- ☐ Inbound: routed to the agent who owns the lead; unknown caller handled
- ☐ Browser calling: microphone prompt once; audio both ways; mute/hold/hang-up work; no CSP errors in console (run first with `CSP_REPORT_ONLY=true`)
- ☐ Callback lost (block the webhook briefly) → `telephony:reconcile-pending` closes the call within ~5 min
- ☐ Recording announcement plays in the Exotel flow; recording notice shown in the softphone; recording playable by Admin (all calls) and Manager (own calls) only
- ☐ **Consent sign-off** obtained from the client before enabling recording in production. By: ______ Date: ______
- ☐ Recording retention and storage mode agreed (default 180 days; provider or private storage)

## 8. Meta live test

- ☐ Lead Ads Testing Tool lead for **each** mapped form arrives within a minute (worker running), with mapped fields and custom answers
- ☐ Duplicate submission (same leadgen id) does not create a second lead
- ☐ Unmapped form: enquiry recorded, visible to admins, no lead lost
- ☐ Token state is **Connected**; Test connection passes

## 9. Cross-cutting reviews

**Browsers** (latest two versions): ☐ Chrome ☐ Edge ☐ Firefox ☐ Safari (macOS) — login, lead list, Lead 360, calendar, reports, softphone (Chrome/Edge for WebRTC calling)

**Mobile** (phone width): ☐ Android Chrome ☐ iPhone Safari — login, sidebar menu, lead list/search, Lead 360, log a call outcome, complete follow-up; tables scroll horizontally, no clipped buttons

**Accessibility**: ☐ keyboard-only login and lead creation (visible focus, Esc closes dialogs) ☐ form errors announced next to fields ☐ text contrast readable in the dark theme ☐ page titles show the page and company name

**Browser console / network** (DevTools open as each role):
- ☐ No console errors or CSP violations on dashboard, leads, Lead 360, calendar, reports, calls, settings
- ☐ Lead list responses contain only the current page (25/50/100 rows), never all leads
- ☐ No tokens, secrets, password hashes, raw recording URLs or other users' data in page props or API responses
- ☐ Static assets are served from `/build/…` with long cache; HTML responses carry `Content-Security-Policy`, `X-Frame-Options`, `Strict-Transport-Security`

**Error pages**: ☐ unknown URL → branded 404 ☐ forbidden page → 403 ☐ expired form (leave a form open > session lifetime) → 419 with refresh option ☐ maintenance mode → 503 page ☐ no stack traces anywhere

**Security**: ☐ 5 wrong passwords → lockout message; admin sees it in login history ☐ password reset email arrives (SMTP) and the link works once ☐ logout ends the session; back button does not show data

## 10. Sign-off

| Role | Tester | Date | Result | Open issues |
|------|--------|------|--------|-------------|
| Super Admin | | | | |
| Admin | | | | |
| Sales Manager | | | | |
| Sales Executive | | | | |
| Telephony | | | | |
| Meta | | | | |

Go-live requires all role sections passed or waived in writing by the client, and the release-blocking telephony SDK item verified.
