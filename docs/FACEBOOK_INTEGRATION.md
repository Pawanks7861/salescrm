# Meta / Facebook Lead Ads Integration (Phase 5)

Leads submitted through Meta Instant Forms (on Facebook **and** Instagram) arrive in the CRM automatically:

```
Instant Form → Meta webhook → signature check → event ledger (leadgen_id) → queue
            → fetch lead from Graph API → field mapping → duplicate policy
            → Lead / Enquiry → assignment engine → activity + audit → notification
```

Admin screen: **Admin → Integrations → Facebook** (`/admin/integrations/facebook`), permission `facebook.manage` (Super Admin by default; it can be granted to other roles on the Roles screen).

---

## 1. Meta app setup

1. Create a **Business** app at <https://developers.facebook.com/apps> and add the **Facebook Login for Business** and **Webhooks** products.
2. Complete **Business Verification** and **App Review** for these permissions (Advanced Access is required to read leads of Pages your business does not own):

| Permission | Why |
|---|---|
| `pages_show_list` | List the Pages the admin manages |
| `pages_read_engagement` | Read Page metadata |
| `pages_manage_metadata` | Subscribe the app to the Page `leadgen` webhook |
| `pages_manage_ads` | Required by Meta for lead retrieval on Pages |
| `leads_retrieval` | Read lead answers |
| `ads_management` | Campaign / ad names on leads (requested; optional) |
| `business_management` | Pages owned through Business Manager (requested; optional) |

   The first five are required; the integration shows **Permission Missing** if any is not granted.
3. **Facebook Login → Settings → Valid OAuth Redirect URIs:** add the redirect URI shown on the CRM screen (default `https://YOUR-CRM/admin/integrations/facebook/callback`).
4. **Webhooks → Page → Subscribe to `leadgen`:** callback URL `https://YOUR-CRM/webhooks/meta/leads`, verify token = `META_WEBHOOK_VERIFY_TOKEN`.
5. Switch the app to **Live** mode. Development-mode apps only deliver test leads for app roles.
6. For each Page, give the app **Leads Access** (Meta Business Suite → Settings → Integrations → Leads Access) if the Page restricts CRM access.

### Environment (server only, never in the database or UI)

```
META_APP_ID=
META_APP_SECRET=
META_WEBHOOK_VERIFY_TOKEN=          # long random string, also entered in the Meta app
META_GRAPH_VERSION=v25.0
META_OAUTH_REDIRECT_URI=            # optional; defaults to the callback route
META_ALLOW_MANUAL_TOKEN=false       # Super Admin system-user token form; keep false in production
```

Optional tuning (see `config/meta.php`): `META_QUEUE` (`integrations`), `META_MAX_ATTEMPTS` (5), `META_HTTP_CONNECT_TIMEOUT` (5 s), `META_HTTP_TIMEOUT` (15 s), `META_OAUTH_SCOPES`.

### Graph API version

The version is configuration (`META_GRAPH_VERSION`), never hard-coded in code paths. On 2026-09-24 Meta's version table listed **v25.0** as supported by both the Graph API and the Marketing API (v26.0 had just shipped for Graph only), so v25.0 is the default. Before upgrading, read the [Graph API changelog](https://developers.facebook.com/docs/graph-api/changelog) and the Lead Ads docs, change the env value, run `php artisan config:cache`, and use **Test connection**.

---

## 2. Connecting (OAuth)

1. Super Admin clicks **Connect with Facebook**. The CRM builds `https://www.facebook.com/{version}/dialog/oauth` with the app id, configured redirect URI, required scopes and a random 48-character **state**. Only a SHA-256 hash of the state is kept in the admin's session, for 10 minutes.
2. Meta redirects back to the callback. The callback (authenticated, active, `facebook.manage`) **validates the state first** (hash compare, single use, expiry). It then exchanges the code server-side for a short-lived token and then a long-lived user token. It verifies the token with `debug_token`: it must be valid and issued to **this** app id. Finally it reads the account name and granted scopes.
3. The token is stored with Laravel's `encrypted` cast (`APP_KEY`). It is never returned to the browser, logged or audited. Pages are loaded right away.
4. Choose the Pages that should send leads. Toggling a Page **on** calls `POST /{page-id}/subscribed_apps?subscribed_fields=leadgen` with the Page token; toggling it off removes only this app's subscription.
5. **Load forms** reads `/{page-id}/leadgen_forms` (name, status, questions). New forms are also discovered automatically when a lead arrives (see §6).

There is no token paste box in normal operation. With `META_ALLOW_MANUAL_TOKEN=true`, Super Admins get a **Development / Advanced** form for a Business Manager *system-user* token. The token is validated the same way (`debug_token`, app id match).

**Disconnect** best-effort unsubscribes every Page, deletes all stored tokens and marks the integration disconnected. Leads, enquiries, events and audit history are kept. Reconnecting re-uses existing Page/form rows and mappings.

### Token health states

| State | Meaning |
|---|---|
| Connected | Token valid, all required permissions granted |
| Token Expiring | Connected, but the token expires within 7 days — reconnect |
| Needs Reauthorization | Meta rejected the token (expired, password change, revoked) |
| Permission Missing | A required permission was not granted or was removed |
| Error | A Page token or configuration problem was detected |
| Disconnected | No stored token |

Health is computed from local data only (page load never calls Meta). **Test connection** and the daily `meta:check` run `debug_token` and verify each receiving Page's `leadgen` subscription.

---

## 3. Webhook security — three separate trust models

| Surface | Trust |
|---|---|
| Admin screens | Session auth + active user + `facebook.manage` + CSRF (normal `web` stack) |
| OAuth callback | The above **plus** the single-use OAuth `state` |
| `GET/POST /webhooks/meta/leads` | Meta's verify token (GET) / **HMAC signature** (POST). No session, no cookies, no CSRF (the route is registered outside the `web` group). CSRF remains enabled everywhere else. |

**GET verification:** `hub.mode` must be `subscribe` and `hub.verify_token` must match `META_WEBHOOK_VERIFY_TOKEN` (constant-time `hash_equals`). Then the raw `hub.challenge` is returned as `text/plain`; otherwise 403. The token is never logged.

**POST signature:** `X-Hub-Signature-256: sha256=<hex>` must equal `hash_hmac('sha256', RAW_BODY, META_APP_SECRET)`, compared with `hash_equals`. The HMAC is computed over `$request->getContent()`, the exact bytes Meta sent. The body is **never** decoded and re-encoded before hashing, because re-encoding changes whitespace and escapes. A test proves a signature over re-encoded JSON is rejected. Missing, malformed or invalid signatures, or an unset app secret, get **403** and nothing is stored.

Further hardening:

- body > 2 MB → 413;
- more than 20 invalid signatures per IP per minute → 429;
- general flood limit of 1200 requests per IP per minute;
- rejections are logged and audited (`FACEBOOK_WEBHOOK_REJECTED`) at most once per IP and reason per minute;
- webhook errors are always plain text, never HTML or debug pages.

**There is no HTTP endpoint that bypasses the signature.** Local testing uses the CLI (`meta:test-lead`, §9), which refuses to run unless `APP_ENV` is `local` or `testing`.

---

## 4. Processing pipeline

**Synchronous part (webhook request):**

1. Verify the signature, then decode the JSON.
2. Process each `entry[].changes[]` with `field = leadgen` independently. A malformed change is skipped without affecting the batch.
3. For each change, insert a `facebook_webhook_events` row keyed by the **unique `leadgen_id`**. The row holds ids only (leadgen, page, form, ad, created_time) — no answers or PII.
4. Route the event:
   - unknown Page → `ignored` (`unknown_page`);
   - Page not enabled → `ignored` (`page_disabled`);
   - known form that is disabled → `ignored` (`form_disabled`);
   - otherwise → `queued`, and `ProcessMetaLeadEvent` is dispatched on the **`integrations`** queue.
5. Return `200 EVENT_RECEIVED`. No Graph call, lead creation or notification happens before Meta gets its 200.

**Queued part: `MetaLeadIngestionService::process()`, the single pipeline for webhook, sync and test origins:**

1. **Claim** the event atomically: status `queued` → `processing`, `attempt_count + 1`. An event stuck in `processing` for more than 10 minutes can be re-claimed.
2. **Fetch** `GET /{leadgen_id}?fields=id,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,field_data,is_organic,platform` with the Page token. This happens **outside any DB transaction**. Tokens travel in the `Authorization` header together with `appsecret_proof`, never in the URL.
3. Resolve the form. An unknown form is registered from Meta; see §6.
4. **Map** the answers (§5), resolve the source and upsert the campaign.
5. **One transaction:**
   - lock the event row;
   - skip if it is already final;
   - skip if an enquiry `(facebook, leadgen_id)` or a lead with that `facebook_lead_id` already exists (→ `duplicate`);
   - otherwise `LeadService::createFromInbound()`, which runs the duplicate policy, enquiry, custom fields, **assignment engine**, activity and audit;
   - mark the event `processed` with `lead_id`, `lead_enquiry_id` and outcome `created` / `merged` / `flagged`;
   - write `FACEBOOK_LEAD_CREATED` or `FACEBOOK_ENQUIRY_CREATED`.
6. **After commit:** notify (§7).

`MetaGraphClient` is the only HTTP client. It has connect and request timeouts. Only idempotent GETs are retried in-process, and only on network errors. Every failure is converted into a sanitized `MetaApiException` carrying a category.

---

## 5. Field mapping

Standard questions map automatically:

| Meta question keys | CRM field |
|---|---|
| `full_name`, `first_name`, `last_name` | name fields |
| `email`, `work_email` | email |
| `phone_number`, `phone` | phone |
| `work_phone_number` | alternate phone |
| `city`, `state`, `province`, `country`, `zip_code`, `post_code` | address fields |
| `company_name`, `job_title` | company, designation |

Per form, admins can remap any question to an **allow-listed** lead field, an active **custom field**, or "Keep in enquiry only". The mapping screen shows a live preview.

- **Never mappable:** `id`, `lead_number`, owner, team, creator/updater, status, priority, estimated value, notes, duplicate flags, `deleted_at`, `converted_at`, `lost_at`. Any other target is rejected with a validation error.
- **Every answer** is always stored on the enquiry, with the question labels, whether or not it is mapped.
- **Bounds:**
  - answers are trimmed, stripped of control characters and truncated to 1000 characters;
  - at most 100 fields are kept;
  - lead fields are cut to their column sizes;
  - invalid emails or phones stay on the enquiry but are not copied onto the lead.
- **Name:** a full name is split at the last space. If no name is present, the placeholder setting is used (default "Facebook Lead").
- **Phone:** normalised by `PhoneNormalizer` using the CRM default country code setting (not a hard-coded +91).
- **Source:** the form's override if set; otherwise **Instagram** when `platform = ig` (setting `facebook.use_instagram_source`); otherwise **Facebook**.
- **Campaign:** upserted into `campaigns` by `(platform = facebook, external_id = campaign_id)`. The name is refreshed on each lead. Ad and ad set ids are stored on the lead; their names are stored on the enquiry metadata.
- **Created by:** system (null). **Status:** the default status.

---

## 6. Idempotency, duplicates, disabled and unknown forms

**Delivery idempotency** is separate from the CRM duplicate policy:

- `facebook_webhook_events.leadgen_id` is unique. A redelivery only increments `delivery_count`.
- A final event (`processed` / `ignored` / `duplicate`) is never processed again.
- `lead_enquiries (channel, external_id)` is unique, and `leads.facebook_lead_id` is unique. Even after old events are pruned, or after a crash between commit and status update, a retry detects the existing enquiry or lead and marks the event `duplicate`. No second lead, enquiry, assignment, round-robin step or notification can occur. Release check H covers this.

**CRM duplicates** follow the lead setting `lead.duplicate_handling`:

- `merge`: attach a new enquiry to the existing lead and fill **empty** contact and custom fields only. Owner, status, priority, estimated value and notes are never changed.
- `flag`: create a new lead flagged as a duplicate.
- `allow`: create an independent lead.

Every submission becomes an enquiry, so repeated form fills stay visible on the lead's **Enquiries** tab with page, form, campaign and readable answers.

**Disabled form or Page:** the event is `ignored`, Meta still gets 200, and there is no Graph call.

**Unknown form on an enabled Page:** the form is fetched from Meta (`GET /{form_id}`) and registered.

- With setting `facebook.auto_enable_new_forms` (default on), the lead is ingested immediately.
- With it off, the event fails with `form_pending_review`. After the admin enables the form, **Retry** ingests the lead.
- If the form cannot be fetched, the event fails with the error category.

In no case is a lead silently dropped.

---

## 7. Assignment and notifications

Assignment uses the existing `LeadAssignmentEngine`. The new rule condition **Facebook form** (`facebook_form`, value = Meta form id, validated against synced forms) extends the generic conditions (source, campaign, city, …). With no matching rule the lead stays **unassigned**. Round robin advances only inside the single ingestion transaction; retries and redeliveries never advance it (release check D).

Notifications go through the in-app database channel, category `lead`, and only from the invocation that created the lead:

- **new lead with an owner:** "New Facebook lead assigned: Amit Desai" (or "Instagram"), with lead number, name, source, form and campaign, sent to the assignee (respects `notifications.notify_on_assignment`);
- **merged repeat enquiry:** "New Facebook enquiry from existing lead: …" to the owner (setting `facebook.notify_on_repeat_enquiry`).

The link opens Lead 360. `NotificationController` re-checks lead visibility when a notification is opened, so a reassigned lead is not revealed. Notifications contain no answers.

Visibility is unchanged: Facebook leads follow the normal Own / All rules (release check F). A Meta lead is assigned to a user by an assignment rule, or stays **unassigned** and is visible to admins only. Team rules no longer run.

---

## 8. Retries, failures and backfill

| Category | Examples | Behaviour |
|---|---|---|
| `rate_limit` | codes 4, 17, 32, 613, 80000–80014, HTTP 429 | Retry; honours `X-Business-Use-Case-Usage` wait time |
| `temporary` | `is_transient`, codes 1/2, HTTP 5xx | Retry |
| `network` | timeout, connection refused | Retry |
| `authentication` / `page_unavailable` | code 190 (user / Page token) | Fail; integration → Needs Reauthorization / Error |
| `permission` | codes 10, 200–299 | Fail; integration → Permission Missing |
| `not_found`, `malformed`, `validation`, `configuration` | deleted lead, bad response, CRM save error, disconnected | Fail |

- **Backoff:** 60 s, 5 min, 15 min, 1 h. After `META_MAX_ATTEMPTS` (5) attempts the event is **dead-lettered** as `failed`, with a `FACEBOOK_WEBHOOK_FAILED` audit. Permanent errors fail on the first attempt, so there are no infinite loops.
- **Error text:** stored errors are sanitized category messages. Unexpected exceptions store only the exception class, never raw messages, which could contain SQL bindings or PII.
- **Webhook events screen:** filter by status, category, Page, form, date or leadgen id; view safe details; **Retry** failed events. Retry resets the attempt budget. Auth and permission failures can only be retried once the connection is healthy again, via Reconnect or Test connection.
- **`meta:retry-failed`** (every 10 minutes): re-dispatches events whose job was lost (queued past the due time, or stuck in `processing`). With `--failed` it also retries failed events in transient or configuration categories; `--id=` targets specific events.
- **Sync recent leads:** per form, 1–90 days (Meta keeps leads 90 days). It is queued (`SyncMetaFormLeads`, unique per form) and reads `/{form_id}/leads` filtered by `time_created`. Each lead goes through the same ledger and pipeline, so leads already received by webhook are skipped.

---

## 9. Local testing

Meta can only deliver webhooks to a public **HTTPS** URL.

1. Run the CRM locally and expose it through any HTTPS tunnel you trust. Set `APP_URL` to the tunnel URL and use `https://<tunnel>/webhooks/meta/leads` as the callback in a development-mode Meta app.
2. Create a test lead with Meta's **Lead Ads Testing Tool** (<https://developers.facebook.com/tools/lead-ads-testing>).
3. Run a worker: `php artisan queue:work --queue=default,integrations`.

Without Meta (no network), feed synthetic leads through the real pipeline:

```
php artisan meta:test-lead --setup                     # creates a local test Page 100000000000001 + form 200000000000001
php artisan meta:test-lead --name="Amit Desai" --phone=+919812345678
php artisan meta:test-lead --leadgen=900000000001      # run twice to see idempotency
php artisan meta:test-lead --platform=ig               # Instagram source
```

The command is disabled unless `APP_ENV` is `local` or `testing`. It never calls Meta and there is no HTTP equivalent.

You can check signature handling with a hand-signed request. Compute the HMAC over the exact bytes you send:

```
BODY='{"object":"page","entry":[{"id":"100000000000001","changes":[{"field":"leadgen","value":{"leadgen_id":"1","page_id":"100000000000001"}}]}]}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$META_APP_SECRET" | sed 's/^.* //')
curl -X POST https://localhost/webhooks/meta/leads -H "Content-Type: application/json" -H "X-Hub-Signature-256: sha256=$SIG" --data-binary "$BODY"
```

---

## 10. Production deployment checklist

- **HTTPS** with a valid certificate for the webhook and OAuth callback URLs.
- **App Review** approved for the required permissions, Business Verification done, app in **Live** mode.
- **Environment:** `META_APP_ID`, `META_APP_SECRET`, `META_WEBHOOK_VERIFY_TOKEN` (random, ≥ 32 chars), `META_GRAPH_VERSION`; `META_ALLOW_MANUAL_TOKEN=false`. Then `php artisan config:cache`.
- **Migrations:** `php artisan migrate --force`.
- **Queue worker** (Supervisor or systemd) including the integrations queue:
  `php artisan queue:work --queue=default,integrations --tries=1 --timeout=120`.
  Retries are managed by the event ledger, not by worker `--tries`.
- **Cron:** `* * * * * php artisan schedule:run`. This runs `meta:retry-failed` every 10 minutes, `meta:check` daily at 03:15 and `meta:prune-events` daily at 03:45.
- **Logs:** the `single` and `daily` channels pass through `App\Logging\RedactSecrets`. Add the tap to any other channel you enable.
- **Monitoring:** watch the dashboard widget, the Integration health cards, the failed-events count and the `FACEBOOK_WEBHOOK_FAILED` audits.
- **Retention:** completed events older than `facebook.event_retention_days` (default 180) are pruned. Failed and pending events are kept. Leads and enquiries are never pruned.

---

## 11. Secrets policy

| Secret | Where it lives | Never appears in |
|---|---|---|
| App Secret | `.env` only | DB, UI, props, logs, audit, exceptions |
| Webhook verify token | `.env` only | DB, UI (only "configured" / "not configured"), logs, audit |
| User / system-user token | `facebook_integrations.access_token_encrypted` (encrypted, `$hidden`) | UI, props, logs, audit, notifications |
| Page tokens | `facebook_pages.page_access_token_encrypted` (encrypted, `$hidden`) | same |
| OAuth code / state | Request only (state hash in session for ≤ 10 min) | logs, audit |

`SecretRedactor` redacts tokens, `appsecret_proof`, `client_secret`, `code`, `state`, Bearer headers, `EAA…` tokens, `appid|secret` pairs and `sha256=` signatures. It applies to log messages and context, and to audit old/new values (any key containing token, secret, authorization, password, signature, credential or cookie, plus `code`). `leadgen_id` and Meta object ids are not secrets and stay readable. Tests assert that no token or secret reaches Vue props, logs, audit rows or event rows (release check E).

---

## 12. Commands

| Command | Purpose |
|---|---|
| `meta:sync [--pages] [--forms]` | Refresh Pages and forms of receiving Pages |
| `meta:check` | Token, permission and subscription health check |
| `meta:retry-failed [--failed] [--id=*]` | Recover lost or stuck events, retry failed ones |
| `meta:prune-events` | Delete completed events past retention |
| `meta:test-lead [--setup] …` | Local/testing only: synthetic lead through the pipeline |

## 13. Not included

- Exports or downloads of Meta leads: none exist, by design.
- Reporting and analytics dashboards: planned for Phase 6.
- Writing back to Meta (Conversions API / lead quality signals).
