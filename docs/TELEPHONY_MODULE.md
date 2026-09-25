# Telephony, Browser Calling & Call Recording (Phase 6)

Click-to-call from the lead, browser (WebRTC) calling with PSTN fallback,
incoming screen-pop, call history, dispositions with follow-up / meeting /
lead-status actions, and permission-controlled recordings.

Provider: **Exotel** (India). A fake provider exists for local development and
automated tests only and cannot run in production (§ 14).

---

## 1. Provider abstraction

```
Controllers ─► CallService / CallCompletionService / WebRtcSessionService / IncomingCallService
                    │
                    ▼
             TelephonyManager (TELEPHONY_DRIVER)
                    │
      ┌─────────────┴──────────────┐
ExotelTelephonyProvider     FakeTelephonyProvider (local/testing only)
      (TelephonyProviderInterface)
```

- `app/Services/Telephony/Providers/TelephonyProviderInterface.php` —
  `initiateOutboundCall`, `getCall`, `getCallStatus`, `createWebRtcSession`,
  `acceptsRecordingReference`, `getRecording`, `callbackUrl`,
  `validateWebhook`, `parseWebhook`, `getNumbers`, `healthCheck`.
- Provider data is exchanged through DTOs in `Services/Telephony/Data`
  (`OutboundCallRequest`, `ProviderCallResult`, `ProviderCallEvent`,
  `RecordingStream`, `WebRtcSession`, `ProviderHealth`, `WebhookValidation`).
- Controllers never call Exotel; only the adapter builds provider HTTP requests.
- Provider failures are `TelephonyException` with a category:
  `unavailable` (timeouts, 5xx, 429), `not_configured` (missing / rejected
  credentials), `rejected` (4xx), `not_found`. Users see a generic message;
  details are logged redacted.

## 2. Exotel setup

Verified against developer.exotel.com (Sept 2026). Re-verify before go-live.

| Purpose | Exotel API |
| --- | --- |
| Click-to-call | `POST https://{subdomain}/v1/Accounts/{sid}/Calls/connect.json` |
| Call details (reconciliation) | `GET /v1/Accounts/{sid}/Calls/{CallSid}.json?details=true` |
| ExoPhones (number sync) | `GET /v2_beta/Accounts/{sid}/IncomingPhoneNumbers` |
| Health check | `GET /v1/Accounts/{sid}.json` |
| Incoming | Passthru applet in the call flow |

Click-to-call parameters: `From` = agent's registered phone (called first),
`To` = customer, `CallerId` = ExoPhone, `CustomField` = CRM call reference
(UUID), `Record`, `RecordingChannels=dual`, `StatusCallback` with events
`answered` + `terminal`, `StatusCallbackContentType=application/json`.
Authentication is HTTP Basic (API key : API token) in the header — never in
the URL.

Environment (server only; `.env.example` contains empty placeholders):

```
TELEPHONY_DRIVER=exotel
EXOTEL_ACCOUNT_SID=
EXOTEL_API_KEY=
EXOTEL_API_TOKEN=
EXOTEL_SUBDOMAIN=            # api.exotel.com (Singapore) or api.in.exotel.com (Mumbai)
EXOTEL_WEBHOOK_SECRET=       # long random string; appended to callback URLs
EXOTEL_DEFAULT_CALLER_ID=    # ExoPhone used when no number is configured
EXOTEL_WEBHOOK_ALLOWED_IPS=  # optional comma-separated IPs/CIDRs
EXOTEL_WEBRTC_ACCESS_TOKEN=  # CRM Web SDK token (browser calling)
EXOTEL_WEBRTC_SDK_URL=       # CRM Web SDK script URL
```

Optional: `EXOTEL_TIMEZONE` (default Asia/Kolkata — Exotel timestamps are
IST), `EXOTEL_RING_TIMEOUT`, `EXOTEL_TIME_LIMIT`, `TELEPHONY_HTTP_*`,
`TELEPHONY_QUEUE` (default `integrations`), `TELEPHONY_RECORDING_DISK`.

## 3. Browser (WebRTC) calling and PSTN fallback

- **PSTN (click-to-call)** — Exotel rings the agent's registered phone, then
  the customer. Works without a browser microphone. This is the fallback.
- **WebRTC** — the Exotel CRM Web SDK runs in the browser (`useTelephony.js`).
  The CRM creates the call record first and gives the browser a dial
  instruction with a CRM reference; the SDK dials and Exotel callbacks carry
  the reference, which attaches the provider call id.

Session security (`POST /telephony/session`, `WebRtcSessionService`):

- Issued only if the user is active, has `call.make`, has an **enabled
  calling account** (`telephony_users`), and the integration is active with
  browser calling enabled.
- Each agent has their own provider identity (`provider_user_id`); there is
  no shared SIP account.
- Response is `Cache-Control: no-store`, never an Inertia prop, never logged.
  The softphone keeps credentials in a closure (memory only), clears the
  access token after registration, and never uses localStorage /
  sessionStorage.
- `GET /telephony/config` returns only non-secret boot data (modes, default
  mode, recording notice).

Softphone behaviour: one registered softphone per browser (Web Locks); other
tabs are told "Calling is active in another CRM tab." (BroadcastChannel). The
call state is polled from `calls/{id}/status` every 2 s while active; after a
connected call the outcome modal opens. If a result is still missing after 3
minutes the widget stops waiting and reconciliation takes over.

**Must be verified in staging:** the Exotel CRM Web SDK method / event names
used in `exotelDriver()` (`Initialize`, `MakeCall`, `AcceptCall`,
`HangupCall`, `ToggleMute`, `ToggleHold`) against the SDK version supplied
with your account.

## 4. Numbers and agents

Admin → Integrations → Telephony (`call.configure`):

- **Numbers** (`telephony_numbers`): ExoPhones, one default; unique per
  integration by normalized number. Outbound calls always use the active
  default outbound number as caller ID (`TelephonyDirectory::outboundNumber`).
  The legacy `team_id` column is deprecated and ignored. "Sync numbers" imports new
  ExoPhones as **inactive** for review.
- **Calling accounts** (`telephony_users`): one per user per integration;
  registered phone (PSTN leg), Exotel app user id (WebRTC), preferred mode.
  Disabling an account immediately blocks calls and sessions.
- **Dispositions**, **call & recording settings**, **health check**, callback
  URLs (shown without the secret), recent failed events, queue backlog and a
  read-only matrix of call permissions per role.
- Page load never contacts the provider; credentials are shown as
  configured / missing only.

## 5. Call lifecycle

Statuses: `initiated → queued → dialing → ringing → answered → completed`, or
terminal `busy`, `no_answer`, `failed`, `cancelled`, `missed` (inbound).

- Status only moves forward (`CallStatus::canTransitionTo`). Duplicate or
  out-of-order callbacks never downgrade a call; facts (timestamps,
  durations, recording) are filled once and never overwritten.
- Provider facts (direction, numbers, timestamps, durations, provider id,
  agent) are never editable by users; there is no update or delete
  route for calls.
- `agent_user_id` is historical and is not rewritten when the lead is
  reassigned. `calls.team_id` is deprecated: kept for existing rows, no
  longer written, never used for access.
- Numbering: `CALL-YYYY-000001` (`number_sequences`, row-locked).

## 6. Outgoing workflow

1. The user clicks **Call** on the lead (or Calls → manual dial).
2. The browser sends `lead_id` + `contact_field` (`phone` /
   `alternate_phone`). The server resolves the number from the lead.
   A raw `number` is accepted only with `call.manual_dial`.
3. `CallService::startOutbound` checks `call.make`, active user, lead
   visibility, enabled calling account, active integration and mode.
4. The call row is committed **before** the provider request; no DB
   transaction is held open during provider HTTP. A provider failure marks
   the call `failed` — a fake "successful" call is never created.
5. Callbacks drive the status. Connected calls (and optionally unconnected
   ones) require a disposition; the user must save it before placing the
   next call.
6. A completed call updates `leads.last_contacted_at` and the lead timeline.

## 7. Incoming workflow

1. Exotel Passthru (`/webhooks/telephony/exotel/passthru`) → inbound call row
   (never creates a lead).
2. Matching by normalized number against `normalized_phone` /
   `normalized_alternate_phone`. The agent is the one whose leg Exotel dialled
   (`DialWhomNumber`), else the owner of the single matching lead.
3. Screen pop (`POST /telephony/incoming/identify`) returns only leads the
   receiving user may see:
   `matched` (one), `multiple` (up to 5), `restricted` ("Lead information
   unavailable." — no name, status, owner, campaign or notes), `unknown`
   (offer "Create lead" prefilled with the number if `lead.create`).
4. Missed incoming calls notify the lead owner (setting
   `telephony.notify_missed_calls`). Notifications re-check `CallVisibility`
   when displayed and opened.

## 8. Recordings

- Captured by Exotel (`Record=true`, dual channel). The recording URL
  arrives in the terminal callback (or via reconciliation) and is stored
  **encrypted** in `call_recordings.provider_reference_encrypted`. It is
  never sent to the browser.
- Recordings are only fetched from allow-listed HTTPS hosts
  (`exotel.com`, `exotel.in`, `amazonaws.com`; no credentials in URL, no
  redirects). Basic auth is only sent to Exotel hosts.
- **Storage mode** (`telephony.recording_storage`): `provider` (proxy-stream
  from Exotel) or `private_storage` (queued `ProcessCallRecording` copies the
  audio to the private disk).
- **Playback**: `GET /calls/{call}/recording` streams through the CRM with
  HTTP Range support, `Cache-Control: private, no-store`, `nosniff`. Listens
  are audited (once per user / call / 10 minutes) without the URL.
- **Download**: `GET /calls/{call}/recording/download` — separate permission,
  throttled, audited, single recording only.
- **Retention** (`telephony.recording_retention_days`, 30 days – 3 years):
  `telephony:prune` expires recordings (deletes archived audio and the
  provider reference); the call record and metadata remain.
- Known limitation: the player's `controlslist="nodownload"` is a UI hint. A
  user with listen permission can technically save streamed audio; grant
  `call.recording.listen` accordingly.

### Recording permissions

| | Listen | Download |
| --- | --- | --- |
| Sales Executive | ✗ | ✗ |
| Sales Manager | ✓ (calls it can see: own / on own leads) | ✗ |
| Admin | ✓ | ✗ (grant explicitly) |
| Super Admin | ✓ | ✓ |

Both always require the call to be visible (§ 10). A manual URL returns 403.

## 9. Dispositions and next actions

Seeded (system, deactivate-only): Connected, Interested, Not Interested, Call
Back, Follow-up Required, Meeting Required, Proposal Required, Busy, No
Answer, Wrong Number, Converted, Other. Admins add custom dispositions
(`requires_note`, `requires_next_action`, `is_contact`, colour); they are
never deleted.

`CallCompletionService` saves in one transaction:

- disposition + notes (edit window: `telephony.notes_edit_window_hours`,
  0 = unlimited; the first note is always allowed; `call.configure` bypasses),
- next action **follow-up** → Phase 3 `FollowupService` (duplicate check,
  reminders, `leads.next_followup_at`),
- next action **meeting** → Phase 4 `MeetingService` (conflicts, override
  permission, reminders),
- lead status → Phase 2 `LeadService` (Lost still requires a lost reason).

Any failure rolls everything back; errors are reported under `followup.*`
/ `meeting.*`. Changes are audited with old/new values
(`CALL_DISPOSITION_ADDED/CHANGED`, `CALL_NOTES_UPDATED`).

## 10. Visibility

`CallVisibility` (SQL `apply()` + in-memory `canView()`):

- `call.view_all` → any call; `call.view` → calls I handled (agent) or on a
  lead assigned to me. There is no team tier.
- Only the lead owner (or a `lead.view_all` user) can place a call on a
  lead; `POST /calls` on a lead you can't see returns 403/404 and creates no
  call row.
- **and** if the call is linked to a lead, that lead must be visible through
  `LeadVisibility` and not archived. Having made a call never grants access
  to a lead that has since been reassigned.

All lists, counters, dashboards, notifications and lead tabs query through
`Call::visibleTo($user)` and paginate server-side.

## 11. Webhook security

Exotel does not sign voice callbacks, so the CRM does **not** invent a
signature scheme. Instead:

- Secret token in the callback URL (`?token=`), compared in constant time.
- Optional source IP allow-list (`EXOTEL_WEBHOOK_ALLOWED_IPS`).
- `AccountSid` must match when present.
- Status callbacks are only applied to calls the CRM already knows (by
  provider id or CRM reference); unknown ones are stored as `ignored`.
- Payload size limit, invalid-attempt rate limit, plain-text responses,
  rejections audited (`CALL_WEBHOOK_REJECTED`) without the payload.
- Payloads are sanitised (credentials redacted, recording URL removed) and
  stored encrypted in `call_events.payload_encrypted`. Full callbacks and
  phone numbers are not written to the Laravel log.

## 12. Idempotency, ordering and reconciliation

- `call_events.dedupe_key` (unique) = provider + call id + event id / status
  hash; a replay returns `DUPLICATE` and changes nothing.
- The call row is locked (`lockForUpdate`) while an event is applied.
- `telephony:reconcile-pending` (every 5 minutes, no overlap): open calls
  older than 10 minutes are checked with `getCall`; terminal results are
  applied; calls open for 24 h are closed as `failed / reconcile_timeout`;
  never-dialled browser calls are closed as `cancelled`. Pending recordings
  are retried and given up after 24 h. A provider outage stops the run early.

## 13. Retention

`telephony:prune` (daily 04:15): expires recordings per retention setting;
deletes processed / ignored `call_events` older than
`telephony.event_retention_days` (min 7). Failed events are kept for review.
Call records are never deleted.

## 14. Fake provider (local development)

`TELEPHONY_DRIVER=fake` works only when `APP_ENV` is `local` or `testing`.
In any other environment resolving it throws, and the simulator routes
(`/telephony/fake/*`) return 404. It places no real calls; the softphone
shows a simulator panel that sends callbacks through the same pipeline as
Exotel.

## 15. Compliance considerations

- A recording notice is shown in the softphone during calls
  (`telephony.recording_notice_enabled` / `_text`). There is no stealth
  recording.
- **Before enabling recording in production, the business must review
  consent requirements** (customer announcement in the Exotel call flow,
  DPDP Act 2023 obligations, sector rules). This is a legal decision, not a
  software setting.
- No call CSV export, no bulk recording download, no ZIP.
- Access to recordings is audited; recordings expire per retention.

## 16. Permissions

`call.view`, `call.view_all`, `call.make`, `call.receive`,
`call.manual_dial`, `call.add_disposition`, `call.edit_notes`,
`call.recording.listen`, `call.recording.download`, `call.configure`,
`call.monitor`. `call.view_team` is deprecated and has no effect. Defaults:
see `docs/PERMISSIONS.md`.

## 17. Production setup

1. HTTPS is mandatory (WebRTC microphone access and callbacks).
2. Set the Exotel env values; `php artisan config:cache`.
3. Configure the Exotel call flow:
   - Status callback: `https://<crm>/webhooks/telephony/exotel/status?token=<EXOTEL_WEBHOOK_SECRET>`
     (click-to-call sets it automatically per call).
   - Incoming flow: Passthru applet → `https://<crm>/webhooks/telephony/exotel/passthru?token=<secret>`
     at the start and after the Connect applet.
   - Add a recording announcement if recording is enabled.
4. Admin → Telephony: add ExoPhones (caller IDs), set the default number,
   create a calling account per agent (registered phone and/or Exotel app
   user id), enable the integration, run the health check.
5. Browser calling: set `EXOTEL_WEBRTC_*`, enable browser calling; agents
   must allow microphone access for the CRM origin.
6. Queue worker must process the `integrations` queue
   (`php artisan queue:work --queue=default,integrations`).
7. Scheduler (`php artisan schedule:run` every minute) for reconciliation and
   pruning.
8. Choose recording storage and retention in Admin → Telephony.
9. Never place real credentials in documentation, tickets or the database.

## 18. Routes

```
GET   /calls                                 calls.index             call.view|view_all
GET   /calls/active                          calls.active
GET   /calls/{call}                          calls.show
GET   /calls/{call}/status                   calls.status
GET   /calls/{call}/outcome                  calls.outcome.options   call.add_disposition
POST  /calls/{call}/outcome                  calls.outcome           call.add_disposition
PATCH /calls/{call}/notes                    calls.notes             call.edit_notes
GET   /calls/{call}/recording                calls.recording         call.recording.listen
GET   /calls/{call}/recording/download       calls.recording.download call.recording.download (throttled)
POST  /calls                                 calls.store             call.make (throttled)
GET   /telephony/config                      telephony.config
POST  /telephony/session                     telephony.session       call.make (throttled)
POST  /telephony/incoming/identify           telephony.identify      call.receive
POST  /telephony/fake/calls/{call}           telephony.fake.simulate local/testing + fake driver only
POST  /telephony/fake/incoming               telephony.fake.incoming local/testing + fake driver only
GET|POST /webhooks/telephony/{provider}/status  (no session/CSRF, token-authenticated, throttle:telephony-webhook)
GET|POST /webhooks/telephony/{provider}/passthru
/admin/integrations/telephony/*              admin.integrations.telephony.*  call.configure
```

## 19. Tests

`tests/Feature/Telephony/*` — outbound, webhooks (idempotency, ordering,
inbound matching), recordings (streaming, range, permissions, retention),
outcomes (follow-up / meeting / lead status integration, audit), visibility,
WebRTC sessions and production guard, reconciliation, Exotel adapter
(`Http::fake`), admin, release checks A–M.
