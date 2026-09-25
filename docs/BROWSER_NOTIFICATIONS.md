# Browser notifications and sound

Browser notifications are an extra delivery channel on top of the in-app notification centre (the bell and `/notifications`). They don't replace it. Every browser notification is also a normal database notification, with the same id, the same text and the same click target.

| Event | Push event | In-app | Browser push | Sound |
| --- | --- | --- | --- | --- |
| New lead assigned to you (manual, rule, Meta) | `NEW_LEAD_ASSIGNED` | yes | yes | new-lead chime |
| Follow-up reminder ("due at 4:30 PM") | `FOLLOWUP_REMINDER` | yes | yes | follow-up chime |
| Follow-up overdue alert, meetings, calls, imports, … | none | yes | no | none |

## Delivery chain

```
Follow-up due                                    Lead assigned (LeadAssigned event, only on an owner change)
  └─ schedule:run (every minute)                   └─ NotifyLeadAssignee / FacebookLeadNotification
       └─ followups:dispatch-reminders
            └─ ReminderQueue::claimDue  (atomic pending → processing)
                 └─ SendFollowupReminder job  (per-reminder lock, re-checks access)
  ─────────────────────────────────────────────────────────────
  $user->notify(...)          database channel → notifications table (id = UUID)
    └─ WebPushChannel         skipped unless shouldPush(user); queued after commit
         └─ SendWebPushNotification (queue)   unique per notification id, up to 3 attempts
              └─ WebPushService::deliver       VAPID, aes128gcm, TTL, urgency high
                   └─ push service (FCM / WNS / Mozilla / Apple) → browser → public/sw.js
                        └─ open CRM tab (toast + chime) or OS notification
                             └─ click → /notifications/{id}/open → re-authorized redirect
```

- **Reminders:** the Phase 3 pipeline is authoritative and unchanged. The push rides on the single notification that pipeline sends, so re-running the scheduler cannot send a second push. There is no second scheduler.
- **Per-reminder lock:** a claim that goes stale (worker stalled for longer than `ReminderState::STALE_PROCESSING_MINUTES`) is reclaimed and queued again. `SendFollowupReminder` delivers under `Cache::lock("followup-reminder:{id}")` and re-reads the row inside the lock, so two copies of the job still deliver once.
- **New lead:** `LeadAssignmentService` fires `LeadAssigned` only when ownership actually changes. Self-assignment is silent. Only the new owner is notified; the old owner and the team are not. Meta leads keep `FacebookLeadNotification`, which pushes the same `NEW_LEAD_ASSIGNED` event.
- **Failure isolation:** a push problem never fails lead creation, assignment or a reminder, and never removes or re-sends the in-app notification.

## Payload

The push carries only what the OS notification needs:

```json
{ "id": "<notification uuid>", "event": "FOLLOWUP_REMINDER", "title": "Follow-up Reminder",
  "body": "Call with Amit Desai at 4:30 PM.", "url": "/notifications/<uuid>/open",
  "icon": "/images/notification-icon.png", "sound": true, "ts": 1790000000000 }
```

- It never contains a phone number, email, notes, tokens, recording URLs or the lead record. The title is capped at 80 characters and the body at 180.
- Bodies: "A new lead has been assigned to you." or "Amit Desai has been assigned to you."; "Your follow-up is due at 4:30 PM." or "Call with Amit Desai at 4:30 PM.". The name variants are used only while **Show the lead name in browser notifications** (`notifications.browser_show_names`) is on.
- `url` always goes through `/notifications/{id}/open`. That route checks the notification belongs to the signed-in user, marks it read, **re-checks lead or follow-up visibility** and redirects to `/leads/{id}` or `/follow-ups/{id}`, which show the record's current state (completed, cancelled, rescheduled). If access was lost, for example because the lead was reassigned, it shows the stale-notification message and the record itself returns 403. A notification never grants access.

## Retries and dead subscriptions

| Push service answer | What happens |
| --- | --- |
| 201 accepted | Recorded as delivered for that browser (`last_used_at`). |
| 404 / 410 (expired) | Subscription deleted at once, never retried. |
| 429, 5xx, network error, no answer | Retried after 30 s, then 120 s (3 attempts in total), **only for browsers that did not get it yet**. Then logged as "Browser push gave up after retries". |
| Other 4xx | Logged as "Browser push rejected", not retried. |
| Server cannot encrypt (OpenSSL) | Logged as an **error** mentioning `OPENSSL_CONF`; not retried (see Windows below). |

Browsers that already received a notification are remembered in the cache under `webpush:delivered:{id}` for a day, so a retry or re-run never shows the same notification twice. Logs contain only user, notification and subscription ids and HTTP status codes, never endpoints or keys.

## Delivery rules

A push is sent only when **all** of these are true (re-checked when the job runs):

1. VAPID keys are configured (`WEBPUSH_VAPID_PUBLIC_KEY`, `WEBPUSH_VAPID_PRIVATE_KEY`).
2. The admin switch **Allow browser (push) notifications for new leads and follow-up reminders** is on (System Settings → Notifications, `notifications.browser_enabled`).
3. The user is active and turned **Browser notifications** on in their profile. The default is **off**.
4. The user has at least one subscribed browser (a row in `push_subscriptions`).

The sound needs the admin switch **Allow notification sounds** (`notifications.sound_enabled`) **and** the user's **Sound** preference.

## Behaviour by tab state

| CRM state | What the user gets |
| --- | --- |
| Tab open and focused | In-app toast + CRM chime. No OS popup (the service worker waits up to 1.5 s for the tab to confirm; if it doesn't, an OS notification is shown instead). |
| Tab in the background, or browser minimized, or user in another app | OS notification. One CRM tab is asked to play the CRM chime; the OS notification is silent only if that tab confirms it played, otherwise it uses the system sound. |
| All CRM tabs closed, browser still running | OS notification with the **system default sound** (a closed page cannot play audio). |
| Browser fully closed | See the limitations below. |

Clicking the notification focuses an existing CRM tab and navigates it (in-app, or with `WindowClient.navigate` if the page does not answer). A new tab opens only when no CRM tab exists.

## Service worker (`public/sw.js`)

Scope `/`, registered when the user enables notifications. It needs no open window.

- **`push`:** the focused CRM tab gets `{type: 'crm:push', mode: 'foreground'}` over a `MessageChannel` and must answer `{handled: true}`. With no focused tab, one tab (visible first) gets `mode: 'background'` and answers `{played}`. Every other tab gets `mode: 'passive'` (unread count and de-duplication only). The OS notification uses `tag = notification id` and `renotify: false`, so a repeat replaces instead of stacking.
- **`notificationclick`:** as described above. Only same-origin URLs are opened.
- **`pushsubscriptionchange`:** re-subscribes with the same VAPID key and posts the new endpoint to `/push-subscriptions` with `replaces` = the old endpoint (CSRF token from the `XSRF-TOKEN` cookie). If that isn't possible, open tabs are asked to re-register.
- Tabs also re-register whenever this browser's endpoint differs from the one registered in this session, or the VAPID key changed (old endpoint sent as `replaces`).

## Sound (`resources/js/notifications/sound.js`)

`NotificationSoundService` / `useNotificationSound()` is the only place that makes sound. It exposes `playNewLeadSound()`, `playFollowupReminderSound()`, `playTestSound(type)` and `unlockAudio()`, and shares one `AudioContext`. The tones are generated with the Web Audio API; there are no audio files.

| Sound | Tone | Length |
| --- | --- | --- |
| New lead | Bright rising two-tone, sine, E5 → A5 | about 0.46 s |
| Follow-up reminder | Lower "single … double" knock, triangle, A4 | about 0.65 s |

- **Autoplay:** browsers allow audio only after a user gesture. The context is unlocked on the first click or key press, by the sound toggle or by a test button. Until then playback is skipped without errors. A context suspended by the browser is resumed when allowed; if it can't be, the tab reports "not played" and the OS notification keeps its system sound.
- **De-duplication:** each notification id toasts and chimes once. Ids are kept **in memory** for 10 minutes (max 200) and shared between tabs over the `crm-notifications` BroadcastChannel. Nothing is stored in `localStorage`.
- The in-app poll of `/notifications/recent` (every 45 s while a tab is visible) remains the fallback for users without browser push. Only the focused tab announces from the poll when push is active.

## Preferences (My profile → Notification preferences)

- **Browser notifications:** Enable / Turn off, with the status **Enabled**, **Not enabled**, **Blocked**, **Not supported** or **Turned off** (by the administrator). When blocked, the card says "Browser notifications are blocked. Enable them in your browser settings." and offers no button: the permission prompt is only ever shown after an explicit **Enable** click and never repeated automatically.
- **Sound notifications:** On/Off, **Test New Lead Sound**, **Test Follow-up Sound**.
- **Test browser notification** (when enabled): **Test new lead** / **Test follow-up**, sent now or in 10 / 30 seconds, through the real queue and push service (`POST /profile/notifications/test`, rate-limited, only to the caller's own browsers, nothing stored in the notification centre). The delay lets you switch away, minimize or close the tab first.

## Diagnosing delivery: `php artisan notifications:doctor`

Checks each link separately and prints PASS / WARN / FAIL. Endpoints, keys and message text are never printed.

```powershell
php artisan notifications:doctor
php artisan notifications:doctor --user=rahul@example.com
php artisan notifications:doctor --user=rahul@example.com --send-test=reminder   # or lead
```

| Row | Fails when |
| --- | --- |
| Scheduler | No heartbeat in the last 2 minutes (`schedule:work` / cron not running). |
| Reminder claim | Due reminders more than 2 minutes old are still `pending` (the dispatch command isn't running). |
| Reminder delivery | Claimed reminders more than 2 minutes old are still `processing` (queue worker not running). |
| Queue worker | Jobs waiting for more than 2 minutes. |
| VAPID keys / admin switches | Keys missing; switches off (WARN). |
| Push encryption | OpenSSL in **this** process cannot create P-256 keys (set `OPENSSL_CONF`). |
| User rows | Inactive, preference off, no subscribed browser, recent notification count. |

`php artisan app:production-check` also includes the push encryption check.

## Windows / WAMP (development and on-premise)

PHP's OpenSSL on Windows needs `OPENSSL_CONF` to create the per-message encryption keys. Without it **every push fails** (logged as the `OPENSSL_CONF` error). PHP reads it at startup, so it must be in the environment of the process that sends pushes, which is the **queue worker**:

```powershell
$env:OPENSSL_CONF = "C:\wamp64\bin\php\php8.3.6\extras\ssl\openssl.cnf"
php artisan queue:work --queue=default,integrations
# second terminal
php artisan schedule:work
```

Set it system-wide (System Properties → Environment Variables) for services and scheduled tasks. Verify with `php artisan notifications:doctor` run from the same shell.

## Manual Windows verification (Chrome and Edge)

Automated tests cover the server chain and the service-worker logic with mocks; the OS popup itself must be checked by hand. Prerequisites: queue worker (with `OPENSSL_CONF`) and scheduler running, `notifications:doctor --user=<you>` all PASS, Windows **Settings → System → Notifications** on for Chrome/Edge, Focus / Do Not Disturb off.

1. Sign in, open *My profile → Notification preferences*, click **Enable**, allow the prompt. Status: **Enabled**.
2. **Sound test:** click **Test New Lead Sound**, then **Test Follow-up Sound**. They must sound clearly different.
3. **A, foreground:** keep the CRM focused, click **Test new lead** (now). Expected: toast + new-lead chime, no OS popup.
4. **B, background tab:** choose "in 10 seconds", click **Test follow-up**, switch to another tab. Expected: Windows notification "Follow-up Reminder" + the follow-up chime (or the system sound if the tab could not play).
5. **C, minimized:** "in 10 seconds", click **Test new lead**, minimize the browser. Expected: Windows notification + sound.
6. **D, tab closed:** "in 30 seconds", click **Test follow-up**, close every CRM tab but keep the browser open. Expected: Windows notification with the system sound.
7. **E, click:** click the notification from B–D. Expected: the existing CRM tab is focused (or one opens if none) on the target page; no duplicate tabs.
8. **Real events:** have an admin assign a lead to you (expected: "New Lead Assigned", opens the lead), and schedule a follow-up with a reminder 1–2 minutes ahead (expected: "Follow-up Reminder" once, opens the follow-up).
9. Check the browser console (F12) on the CRM tab: no errors.

Repeat on Edge. Record differences (see below).

## Browser and OS limitations

| Situation | Behaviour |
| --- | --- |
| Tab closed, browser open | Delivered by the service worker with the **system** sound. The CRM chime cannot play without a page. |
| Browser fully closed | Chrome/Edge deliver only while a browser process is running. Closing the last window usually ends the browser unless "Continue running background apps when … is closed" (Chrome) / "Continue running background extensions and apps when Microsoft Edge is closed" (Edge) keeps it alive. Otherwise the push service holds the message until the browser starts again, for up to `webpush.ttl` (1 hour by default), then drops it. The in-app notification is always kept. **The CRM does not promise delivery with the browser fully closed.** |
| Windows Focus / Do Not Disturb, notifications off for the browser | Windows hides or silences the popup; the in-app notification is still there. |
| Permission denied | Nothing can be shown; the CRM shows the blocked message and does not prompt again. |
| Embedded browsers (IDE previews, some WebViews) | Often no push service; **Enable** reports "This browser has no push service available." |
| iOS / iPadOS | Push only after adding the CRM to the Home Screen (16.4+). |
| Browser suspended the tab's audio | The tab reports "not played" and the OS notification keeps the system sound. |
| Chrome vs Edge | Same Web Push API (Chrome via FCM, Edge via WNS `*.notify.windows.com`). Edge groups notifications under "Microsoft Edge" in the Windows notification centre; Chrome under "Google Chrome". |

## Subscriptions

Table `push_subscriptions`: `user_id` (cascade delete), `endpoint_hash` (SHA-256, unique), `endpoint`, `public_key` and `auth_token` **encrypted at rest**, `content_encoding`, `user_agent`, `last_used_at`.

- A user can have several browsers. `POST /push-subscriptions` always registers the browser for the **signed-in user** (a shared browser moves to the current user). `replaces` removes only the caller's own old endpoint.
- `DELETE /push-subscriptions` removes only the caller's own subscription. **Logout** deletes the subscription registered in that session.
- Endpoints must be HTTPS on a known push service (FCM, Mozilla, WNS, Apple). Admins never see raw endpoints; responses never echo endpoints or keys.

## VAPID keys

```powershell
php artisan webpush:vapid
```

Copy the printed values into `.env` (`WEBPUSH_VAPID_PUBLIC_KEY`, `WEBPUSH_VAPID_PRIVATE_KEY` (secret: never commit), `WEBPUSH_VAPID_SUBJECT=mailto:admin@your-company.com`), then `php artisan config:clear` (or `config:cache` in production). Only the public key reaches the browser. After a key change, open tabs re-subscribe automatically; browsers that aren't opened stay unsubscribed until the user signs in again.

## Privacy and audit

- OS notifications can appear on a locked screen; the body contains at most the customer's name.
- Audited: `BROWSER_NOTIFICATIONS_ENABLED` / `DISABLED`, `NOTIFICATION_SOUND_ENABLED` / `DISABLED`. Individual pushes and test pushes are not audited.

## Production checklist

1. **HTTPS is required.** Service workers and push work only in a secure context (`localhost` is fine for development).
2. VAPID keys set, `php artisan config:cache`.
3. Scheduler (cron `schedule:run` every minute) and a supervised queue worker are running.
4. Windows servers: `OPENSSL_CONF` in the worker's environment.
5. Outbound HTTPS allowed to `fcm.googleapis.com`, `*.notify.windows.com`, `updates.push.services.mozilla.com`, `*.push.apple.com`.
6. `php artisan notifications:doctor` all PASS.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Nothing arrives at all | `php artisan notifications:doctor --user=<email>`; the first FAIL row is the broken link. |
| Status **Blocked** | Site settings → Notifications → Allow for the CRM URL, then reload. |
| Status **Not supported** | The page must be HTTPS (or localhost) in a current browser. |
| Status **Turned off** | Admin switch off or VAPID keys missing (`php artisan config:clear` after editing `.env`). |
| "OPENSSL_CONF" error in the log | Start the queue worker with `OPENSSL_CONF` set (Windows section). |
| Push arrives, no popup | Windows notifications / Focus settings for the browser. |
| No chime | Click the page once (autoplay), check both Sound switches, use the test buttons. |
