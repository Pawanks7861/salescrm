# Database Schema

Conventions: `id` BIGINT unsigned PK; `timestamps` = `created_at`, `updated_at`; FKs `ON DELETE RESTRICT` unless stated (historic CRM records must never cascade-delete); soft deletes (`deleted_at`) on business entities; UTC storage; utf8mb4.

Legend: Phases 1–2 implemented; Phases 3–5 designed and created in that phase.

> **Deprecated team data (access model change).** CRM visibility is Own / All only ([CRM_ARCHITECTURE.md §2.4](CRM_ARCHITECTURE.md#24-access-model-own--all-team-visibility-removed)). The following are **kept, not dropped, and never used for authorization or report scope**. Dropping them needs explicit approval and a separate reviewed migration.
>
> - Tables `teams` and `team_users`.
> - Columns `users.team_id` and `users.manager_id`. Both are hidden from the UI and ignored if posted.
> - Columns `leads.team_id`, `followups.team_id`, `meetings.team_id` and `lead_status_changes.team_id`. They're **no longer written** on new rows; existing values stay as history.
> - Columns `lead_assignments.from_team_id` / `to_team_id` and `lead_assignment_rules.assigned_team_id`.
> - The `team` / `team_round_robin` assignment types and the `team` condition. Rules using them never execute.
>
> Marked **(deprecated)** below.

---

## Phase 1 — Foundation (implemented)

### users (Breeze table + additive migration)
| Column | Type | Notes |
|--------|------|-------|
| name, email (unique), email_verified_at, password, remember_token, timestamps | | Breeze |
| employee_code | varchar(50) null | unique |
| phone | varchar(30) null | |
| role_id | FK roles null | index |
| team_id | FK teams null, `SET NULL` | **(deprecated)** legacy primary team, index; not shown or written by the user form |
| manager_id | FK users null, `SET NULL` | **(deprecated)** legacy reporting line, index; hidden, never used for access |
| designation | varchar(100) null | |
| is_active | bool default 1 | index |
| last_login_at | timestamp null | |
| last_login_ip | varchar(45) null | |
| deleted_at | timestamp null | archive only; users with history are never hard-deleted |

### roles
`id, name, slug (unique), description null, is_system bool, timestamps`

### permissions
`id, name (unique, e.g. lead.view), module, label, description null, timestamps`

### role_permissions
`role_id FK CASCADE, permission_id FK CASCADE` — PK(role_id, permission_id)

### user_permissions
`user_id FK CASCADE, permission_id FK CASCADE, type enum(grant,deny), timestamps` — PK(user_id, permission_id)

### teams **(deprecated — kept, unused)**
`id, name (unique), description null, manager_id FK users null SET NULL, is_active bool, timestamps, deleted_at`

### team_users **(deprecated — kept, unused)**
`id, team_id FK CASCADE, user_id FK CASCADE, timestamps` — unique(team_id, user_id)

### settings
`id, group (index), key (unique, e.g. general.crm_name), value text null, type enum(string,integer,boolean,json,encrypted), timestamps`

### audit_logs (append-only)
| Column | Type |
|--------|------|
| user_id | FK users null SET NULL, index |
| action | varchar(64), index |
| module | varchar(50), index |
| entity_type | varchar(100) null, index |
| entity_id | bigint null, index |
| description | text null |
| old_values_json / new_values_json | json null |
| ip_address | varchar(45) null |
| user_agent | varchar(512) null |
| route | varchar(191) null |
| request_method | varchar(10) null |
| created_at | timestamp, index |

Composite index `(entity_type, entity_id)`. No `updated_at`.

### login_histories
`id, user_id FK null SET NULL (index), email varchar null, event enum(login,logout,failed,lockout), successful bool, ip_address, user_agent, browser, platform, device, session_id null, logged_in_at null, logged_out_at null, created_at (index)`

### notifications
Laravel standard (`id uuid, type, notifiable morphs, data json, read_at, timestamps`).

---

## Phase 2 — Lead CRM (implemented)

Migrations: `2026_09_24_100001_create_lead_lookup_tables`, `2026_09_24_100002_create_leads_table`, `2026_09_24_100003_create_lead_related_tables`. All additive — no Phase 1 column was changed or dropped.

Enum-like columns are stored as `varchar` and cast to PHP enums (`App\Enums\*`) so new values need no schema change.

### number_sequences
`id, prefix varchar(20), period varchar(10), last_value bigint unsigned default 0, timestamps` — unique(prefix, period).
`LeadNumberService` does `insertOrIgnore` + `lockForUpdate` inside a transaction; numbers are never derived from `MAX(id)` and never reused (archiving/deleting does not free a number). `period` is the year in the app timezone.

### lead_statuses
`id, name, slug unique, description null, color (palette key, default slate), icon null, sort_order (index), probability tinyint, is_won, is_lost, is_default, is_active (index), is_system, timestamps`

### lead_sources
`id, name, slug unique, description null, color, icon null, sort_order (index), is_default, is_active (index), is_system, timestamps`

### lost_reasons
`id, name unique, sort_order (index), is_active (index), timestamps`

### campaigns
`id, name, source_id FK lead_sources null SET NULL, platform varchar(30) default manual (index), external_id null, external_parent_id null (index), description null, starts_at date null, ends_at date null, is_active (index), metadata_json null, timestamps` — unique(platform, external_id)

### lead_assignment_rules
| Column | Notes |
|--------|-------|
| name | |
| condition_type | `any, source, campaign, city, state` (`App\Enums\RuleCondition`); legacy `team` is deprecated and never evaluated |
| condition_value | id or text depending on condition (city/state compared case-insensitively) |
| assignment_type | `user, round_robin` (`App\Enums\RuleAssignment`); legacy `team` / `team_round_robin` are deprecated, never executed, cannot be enabled |
| assigned_user_id, assigned_team_id | FK null SET NULL; `assigned_team_id` **(deprecated)** |
| user_pool_json | json array of user ids for `round_robin` |
| last_assigned_user_id | round-robin pointer, reset when the pool/type changes |
| priority | unsigned int, lower runs first |
| is_active | |
| created_by, updated_by | FK users null |
| timestamps, deleted_at | index(is_active, priority) |

### leads
| Column | Notes |
|--------|-------|
| lead_number | varchar(30) unique, `LD-YYYY-NNNNNN` |
| first_name, last_name null, full_name | full_name indexed (composed by the service) |
| email | index, stored lower-case |
| phone, normalized_phone | both indexed; normalized = digits with country code |
| alternate_phone, normalized_alternate_phone | normalized indexed |
| company_name (index), designation | |
| source_id | FK lead_sources RESTRICT |
| campaign_id | FK campaigns null SET NULL |
| facebook_lead_id (unique null), facebook_form_id, facebook_page_id, facebook_ad_id, facebook_adset_id, facebook_campaign_id | populated in Phase 5 |
| assigned_to | FK users null SET NULL, index |
| team_id | FK teams null SET NULL, index — **(deprecated)** not written on new leads, never used for access |
| status_id | FK lead_statuses RESTRICT, index |
| priority | varchar(10) default medium, index (`low, medium, high, urgent`) |
| city, state (indexed), country, pincode | |
| estimated_value | decimal(14,2) null. Hidden from UI and exports since Phase 7.1 (`CRM_LEAD_VALUE_ENABLED`); data retained |
| last_contacted_at, next_followup_at (index) | written by the follow-up module (Phase 3) |
| converted_at, lost_at, lost_reason_id FK null, lost_reason_notes | reflect the **current** state; cleared on reopen |
| is_duplicate (index), duplicate_of_id FK leads null | |
| created_by, updated_by | FK users null |
| timestamps, deleted_at | index(created_at), index(assigned_to, status_id), index(team_id, status_id) |

Not mass assignable: `lead_number`, `assigned_to`, `team_id`, `status_id`, `created_by`, `updated_by`, won/lost columns, duplicate flags, Facebook ids — services set these with `forceFill`.

### lead_enquiries
`id, lead_id FK RESTRICT, source_id FK null, campaign_id FK null, external_id null (index), enquiry_data_json null, is_duplicate bool, received_at (index), created_by FK null, timestamps` — index(lead_id, received_at)

### lead_assignments (history, append-only)
`id, lead_id FK RESTRICT, from_user_id, to_user_id (index), from_team_id, to_team_id (deprecated, not written), assigned_by (null = system), assignment_type varchar(20) (manual, automatic, round_robin, rule, import, facebook), rule_id FK null, reason null, created_at` — index(lead_id, created_at). No `updated_at`.

### lead_custom_fields
`id, name, slug unique, field_type (text, textarea, number, date, dropdown, multiselect, checkbox), options_json null, validation_rules_json null, help_text null, is_required, is_active (index), sort_order, timestamps, deleted_at`

### lead_custom_field_values
`id, lead_id FK CASCADE, lead_custom_field_id FK CASCADE, value text null, timestamps` — unique(lead_id, lead_custom_field_id). Multiselect values are stored as JSON arrays.

### lead_notes
`id, lead_id FK RESTRICT, note text, visibility varchar(20) default team (private, team, management; `team` is labelled "Shared" in the UI), created_by, updated_by, timestamps, deleted_at` — index(lead_id, created_at)

### lead_note_histories
`id, lead_note_id FK CASCADE, old_content text, new_content text, old_visibility null, new_visibility null, edited_by FK null, created_at`

### activities (business timeline, separate from audit_logs)
`id, subject_type, subject_id, user_id FK null, type varchar(64) (index), description text, properties json null, created_at (index)` — index(subject_type, subject_id, id) supports cursor pagination (`?before=<id>`).

### attachments
`id, attachable_type, attachable_id (morphs index), original_name, stored_name (random), disk, path, mime_type null, size, uploaded_by FK null, timestamps, deleted_at`. `disk`, `path`, `stored_name` are `$hidden` and never sent to the browser. Files live on the private `local` disk under `leads/{lead_id}/`.

---

## Phase 3 — Follow-ups (implemented)

Migration `2026_09_24_200001_create_followup_tables`. Additive only; no Phase 1/2 column changed. All timestamps UTC. See `FOLLOWUP_MODULE.md`.

### followup_types
`id, name(100), slug(100) unique, icon(50) null, color(20) default slate, sort_order (index), is_active (index), is_system bool, timestamps`. Seeded system types: Call, WhatsApp, Email, Demo, Site Visit, Other (`FollowupReferenceSeeder`). System types and types in use cannot be deleted — deactivate them.

### followups
| Column | Notes |
|---|---|
| lead_id | FK leads RESTRICT, index |
| assigned_to | FK users null SET NULL, index |
| team_id | FK teams null SET NULL, index — **(deprecated)** legacy team; no longer written, never used for access |
| followup_type_id | FK followup_types RESTRICT |
| title(191) null, description text null | |
| scheduled_at | timestamp (UTC), index |
| timezone(64) | CRM timezone used to interpret the input |
| status(20) | `pending, completed, cancelled, rescheduled`, index. **No `overdue`/`missed` value** — overdue is derived (`pending AND scheduled_at < now()`) |
| priority(10) | `LeadPriority` values (`low, medium, high, urgent`) |
| outcome(30) null, notes text null, next_action(255) null, completed_at null, completed_by FK null | completion |
| cancelled_at null, cancelled_by FK null, cancellation_reason text null | cancellation |
| rescheduled_from_id FK followups null SET NULL, reschedule_reason text null | reschedule chain (reason stored on the original) |
| reminder_minutes_before unsigned null | null = no reminder, 0 = at the time |
| created_by, updated_by | FK users null (server-set) |
| timestamps, deleted_at | index(created_at); composite index(assigned_to, status, scheduled_at), (team_id, status, scheduled_at), (lead_id, status, scheduled_at) |

Not mass assignable: `lead_id, assigned_to, team_id, followup_type_id, scheduled_at, timezone, status`, completion/cancellation columns, `created_by, updated_by`.

### followup_reminders
`id, followup_id FK CASCADE, kind(20) (reminder, overdue), remind_at, status(20) default pending (pending, processing, sent, cancelled, failed), attempts tinyint, claimed_at null, sent_at null, last_error(500) null, timestamps` — index(status, remind_at), **unique(followup_id, kind, remind_at)**. The row status is the idempotency guard for reminder delivery.

### Existing columns now written
- `leads.next_followup_at` — earliest pending follow-up (may be past = overdue), null when none. Synced by `LeadFollowupSyncService` without touching `leads.updated_at`.
- `leads.last_contacted_at` — set on completion with a contact-proving outcome; never moves backwards.

### Settings
Added group `followup`: `default_reminder_minutes`, `overdue_alert_after_minutes`, `due_soon_minutes`, `allow_past`, `require_outcome`, `require_cancellation_reason`. Removed definition `followup.mark_missed_after_minutes` (conflicted with derived overdue); any existing `settings` row is left in place and ignored.

---

## Phase 4 — Meetings

Migration `2026_09_24_300001_create_meeting_tables` (additive; no existing table changed). Behaviour: [MEETING_MODULE.md](MEETING_MODULE.md).

### meeting_types
`id, name(100), slug(100) unique, icon(50) null, color(20) default slate, location_mode(20) default flexible (physical, online, phone, flexible), default_duration_minutes smallint default 30, sort_order (index), is_active (index), is_system, timestamps`

### meetings
| Column | Notes |
|---|---|
| id, meeting_number(30) unique | `MTG-YYYY-NNNNNN` via `number_sequences` (key `M:<prefix>`, per year) |
| lead_id FK leads null RESTRICT (index) | null = internal meeting (`meeting.create_without_lead`) |
| title(191), description text null, agenda text null | |
| meeting_type_id FK meeting_types RESTRICT (index) | |
| host_user_id FK users null SET NULL (index), team_id FK teams null SET NULL (index) | `team_id` **(deprecated)**: no longer written, never used for access |
| start_at (index), end_at (index) | UTC instants |
| timezone(64) | zone the time was entered in |
| location_type(20), location(191) null, address(500) null, meeting_url(500) null | `MeetingLocationType`; URL only as entered (http/https) |
| status(20) default scheduled (index) | scheduled, confirmed, in_progress, completed, cancelled, no_show, rescheduled |
| priority(10) default medium | `LeadPriority` values |
| reminder_offsets json null | minutes before start, e.g. `[1440, 30]` |
| confirmed_at, started_at null | |
| outcome(30) null, outcome_notes text null, completed_at null, completed_by FK null | completion (`MeetingOutcome`) |
| cancelled_at null, cancelled_by FK null, cancellation_reason text null | cancellation |
| rescheduled_from_id FK meetings null SET NULL, reschedule_reason text null | reschedule chain (reason stored on the original) |
| created_by, updated_by | FK users null (server-set) |
| timestamps, deleted_at | index(created_at); composite (host_user_id, status, start_at), (team_id, status, start_at), (lead_id, status, start_at), (start_at, end_at) |

Mass assignable: descriptive fields only (`title, description, agenda, location_type, location, address, meeting_url, priority, reminder_offsets`). Number, lead, type, host, team, schedule, status and audit columns are set by `MeetingService`.

### meeting_participants
`id, meeting_id FK CASCADE, participant_type(20) (user, lead, external), user_id FK users null SET NULL (index), lead_id FK leads null SET NULL (index), name(191), email(191) null, phone(30) null, attendance_status(20) default pending (pending, confirmed, attended, absent, declined), invitation_status(20) default not_sent (not_sent, notified), timestamps` — index(meeting_id, participant_type), **unique(meeting_id, user_id)**, **unique(meeting_id, lead_id)**. name/email/phone are a snapshot; the host is not a participant row.

### meeting_reminders
`id, meeting_id FK CASCADE, user_id FK users CASCADE, minutes_before int, remind_at, channel(20) default database, status(20) default pending (pending, processing, sent, cancelled, failed), attempts tinyint, claimed_at null, sent_at null, failed_at null, last_error(500) null, timestamps` — index(status, remind_at), **unique(meeting_id, user_id, channel, remind_at)**. Claimed through the shared `ReminderQueue` (same state machine as `followup_reminders`).

### Settings
Group `meeting`: `number_prefix` (default `MTG`), `default_duration_minutes`, `default_reminder_minutes` (-1 = none), `require_notes_on_complete`, `require_outcome`, `require_cancellation_reason`, `allow_past`, `conflict_checking`.

---

## Phase 5 — Meta / Facebook Lead Ads (implemented)

Migration `2026_09_24_400001_create_facebook_tables`. It is additive: new tables plus nullable columns and indexes on `lead_enquiries` and `leads`, and no existing column is changed or dropped. Behaviour is described in [FACEBOOK_INTEGRATION.md](FACEBOOK_INTEGRATION.md).

**Secrets:** the Meta App Secret and the webhook verify token are **not stored in the database** (`.env` only). Access tokens are stored only in `*_encrypted` columns, which use Laravel's `encrypted` cast (`APP_KEY`) and are `$hidden` on the models.

### facebook_integrations
| Column | Notes |
|---|---|
| id, name(100) default `Meta Lead Ads` | one active row |
| app_id(64) null | the app id the token was issued to (checked with `debug_token`) |
| access_token_encrypted text null | long-lived user or system-user token (encrypted, hidden) |
| token_type(20) null, token_expires_at null, data_access_expires_at null | `user` / `system_user`; null expiry = non-expiring |
| facebook_user_id(64), facebook_user_name(191), facebook_business_id(64) null | display only |
| graph_version(10) | version in use when connected |
| granted_scopes_json, missing_scopes_json | JSON arrays |
| status(30) default disconnected (index) | `FacebookIntegrationStatus`: connected, token_expiring, needs_reauthorization, permission_missing, error, disconnected |
| last_connected_at, last_verified_at, last_error_at, last_error_code(50), last_error_message(500) | sanitized error text only |
| last_webhook_at, last_lead_at, disconnected_at | health |
| created_by, updated_by FK users null | |
| timestamps, deleted_at | |

### facebook_pages
`id, facebook_integration_id FK RESTRICT, page_id(64) (index), page_name(191), page_access_token_encrypted text null (encrypted, hidden), category(100) null, picture_url(500) null, tasks_json null, is_selected bool (receive leads), is_subscribed bool (leadgen subscription confirmed), is_active bool (still returned by Meta), subscription_error(300) null, last_synced_at, last_subscription_check_at, timestamps`, with **unique(facebook_integration_id, page_id)**.

### facebook_forms
`id, facebook_page_id FK RESTRICT, form_id(64) (index), form_name(191), status(30) null (Meta status: ACTIVE, ARCHIVED, …), locale(20) null, is_enabled bool default true, lead_source_id FK lead_sources null (source override), questions_json null (key, label, type), metadata_json null, last_synced_at, last_lead_at, timestamps`, with **unique(facebook_page_id, form_id)**.

### facebook_field_mappings
`id, facebook_form_id FK CASCADE, meta_field(100), meta_label(191) null, target_type(20) (lead_field, custom_field, ignore), lead_field(50) null (allow-listed in `MetaFieldMappingService`), lead_custom_field_id FK null SET NULL, updated_by FK null, timestamps`, with **unique(facebook_form_id, meta_field)**. Questions without a row use the automatic standard mapping.

### facebook_webhook_events (idempotency ledger)
One row per Meta lead, shared by webhook delivery, manual sync and local test ingestion.

| Column | Notes |
|---|---|
| leadgen_id(64) **unique** | the idempotency key |
| event_type(30) default leadgen, origin(20) default webhook | origin: webhook, sync, test |
| page_id(64) (index), form_id(64) (index), ad_id(64) null | Meta ids from the notification |
| facebook_page_id, facebook_form_id FK null SET NULL | resolved local rows |
| payload_json null | **ids only** (leadgen, page, form, ad, created_time); never answers or PII |
| meta_created_at null, received_at (index), delivery_count default 1, last_delivered_at | redeliveries increment the count |
| processing_status(20) default received | `FacebookEventStatus`: received, queued, processing, processed, duplicate, ignored, failed |
| attempt_count, next_attempt_at, processing_started_at, processed_at, failed_at | retry and backoff state |
| error_category(30), error_code(50), error_message(500) null | sanitized; unexpected exceptions store only the class name |
| outcome(20) null | created, merged, flagged, already_ingested, unknown_page, page_disabled, form_disabled |
| lead_id FK leads null SET NULL, lead_enquiry_id FK lead_enquiries null SET NULL | result |
| timestamps | index(processing_status, received_at) |

### Changes to existing tables
- `lead_enquiries.channel(30) null` is `facebook` for Meta leads. `lead_enquiries.metadata_json null` holds platform, Page, form, campaign, ad set and ad names, `is_organic` and question labels. There is a **unique(channel, external_id)** index, and `external_id` is the `leadgen_id`. Existing rows have `channel = null`, so they are unaffected by the unique index.
- `leads`: indexes on `facebook_form_id` and `facebook_page_id` support the lead list filters. The `facebook_lead_id` column (unique, Phase 2) is now written.

### Settings
Group `facebook`: `placeholder_name`, `use_instagram_source`, `auto_enable_new_forms`, `notify_on_repeat_enquiry`, `event_retention_days`. These are managed on the Facebook admin screen, and changes are audited as `SETTING_CHANGED`.

### Audit actions
`FACEBOOK_CONNECTED`, `FACEBOOK_DISCONNECTED`, `FACEBOOK_CONNECTION_FAILED`, `FACEBOOK_CONNECTION_CHECKED`, `FACEBOOK_PAGE_SYNCED`, `FACEBOOK_PAGE_SUBSCRIBED`, `FACEBOOK_PAGE_UNSUBSCRIBED`, `FACEBOOK_FORM_SYNCED`, `FACEBOOK_FORM_UPDATED`, `FACEBOOK_FIELD_MAPPING_UPDATED`, `FACEBOOK_WEBHOOK_REJECTED`, `FACEBOOK_WEBHOOK_FAILED`, `FACEBOOK_WEBHOOK_RETRIED`, `FACEBOOK_LEADS_SYNC_REQUESTED`, `FACEBOOK_LEAD_CREATED`, `FACEBOOK_ENQUIRY_CREATED`. Values pass through `SecretRedactor`.


## Phase 6 — Telephony — Removed

The calling module and its seven tables were removed. Migration `2026_09_24_500001_create_telephony_tables` (history, unchanged) created them; migration `2026_10_01_100000_remove_telephony_module` drops them children-first: `call_recordings`, `call_events`, `calls`, `call_dispositions`, `telephony_users`, `telephony_numbers`, `telephony_integrations`. No other table had a foreign key into them.

The same migration deletes:

- `permissions` rows named `call.*`, with their `role_permissions` and `user_permissions` links;
- `settings` rows with keys `telephony.*`;
- `number_sequences` rows with prefix `C:*` (the old call numbers). Lead (`LD`) and meeting (`M:*`) sequences are untouched;
- stored missed-call rows in `notifications`.

**Kept:** every lead phone column (`phone`, `alternate_phone`, `normalized_phone`), `audit_logs` rows with call or telephony actions such as `CALL_STARTED` (plain strings, still viewable and filterable), and `activities` rows of type `call_*` (their subject is the lead, not a call).

`down()` recreates the seven tables empty. Call data, `call.*` permissions and `telephony.*` settings are not restored, so restore from a backup if the old data is ever needed.

## Phase 7 — Reporting (implemented)

Migration `2026_09_24_600001_create_reporting_tables` is additive: two new tables and one index. No existing column is changed or dropped. Behaviour is described in [REPORTING_MODULE.md](REPORTING_MODULE.md).

### lead_status_changes (status history)
`id, lead_id FK RESTRICT, from_status_id FK lead_statuses null RESTRICT, to_status_id FK lead_statuses RESTRICT, changed_at, changed_by FK users null SET NULL, assigned_to FK users null SET NULL, team_id FK teams null SET NULL (deprecated; legacy team at the time of the change, no longer written), is_backfilled bool default false, created_at`. Indexes: (lead_id, changed_at), (to_status_id, changed_at), (changed_at).

**Why:** funnel "reached stage", stage duration and period wins/losses need queryable transitions. Parsing the JSON activity timeline per report would be slow and fragile. `LeadService` writes one row on creation (initial status) and one per status change. The migration backfills from the `activities` timeline (`status_changed`, `lead_won`, `lead_lost`, `lead_reopened`, which has recorded every change since Phase 2) with `is_backfilled = 1`, and an initial row at `leads.created_at`. Rows are append-only; no application code updates or deletes them.

### report_exports
`id, uuid **unique** (route key), user_id FK RESTRICT (owner), report(40), section(40), format(10) default csv, filters_json null, status(20) (index) (queued, processing, ready, failed, expired), row_count null, disk(50) null, path(500) null (**hidden**, private disk), file_size null, error(255) null, completed_at, expires_at (index), downloaded_at, timestamps`, with index(user_id, created_at).

**Why:** queued exports need a record the owner can poll, and expiry needs a record the prune command can act on. The row survives the file (status `expired`, `path` null) as the audit trail. Report data is never stored in the table.

### lead_assignments
Added index `created_at` for assignment analytics by period (first assignment, reassignments, time to assignment).

### Role data
The migration grants the existing `report.view` permission to the `sales_executive` role (own-data reports). No other grant changes; managers do not receive `report.export`.

### Settings
Group `report`: `qualified_status`, `response_target_minutes`, `untouched_new_lead_hours`, `inactive_days`, `export_queue_threshold`, `export_retention_hours`.

### Audit actions
`REPORT_VIEWED` (at most once per user/report per 10 minutes; report and filters only), `REPORT_EXPORTED` (report, section, format, filters, row count), `REPORT_EXPORT_DOWNLOADED`, and `EXPORT_ATTEMPTED` for denied exports. Result data is never written to audit values.

## Phase 7.1 — Branding & browser notifications (implemented)

Migration `2026_09_24_700001_create_push_subscriptions_table` is additive. No column is dropped or changed. `leads.estimated_value` is kept with its data; it's only hidden from the UI and exports (see [LEAD_MODULE.md](LEAD_MODULE.md)).

### push_subscriptions
`id, user_id FK users CASCADE, endpoint_hash char(64) **unique** (SHA-256 of the endpoint), endpoint text (**encrypted**, hidden), public_key text (**encrypted**, hidden), auth_token text (**encrypted**, hidden), content_encoding(20) default aes128gcm, user_agent(255) null, last_used_at null, timestamps`, with index(user_id).

**Why:** Web Push needs one row per browser or device, and a user may have several. The hash gives a unique lookup without storing the endpoint in plain text. Expired rows (404/410 from the push service) are deleted after a send, and logout deletes the row registered in that session.

### users
Added `browser_notifications_enabled` bool default **false** (opt-in) and `notification_sound_enabled` bool default true.

### Settings
- `notifications.browser_enabled`, `notifications.sound_enabled`, `notifications.browser_show_names` (admin switches).
- `branding.logo_path`, `branding.favicon_path`: relative paths on the `branding` disk, written only by `BrandingService`. They aren't in a generic settings group.

### Audit actions
- Branding: `BRANDING_LOGO_UPDATED`, `BRANDING_LOGO_REMOVED`, `BRANDING_FAVICON_UPDATED`, `BRANDING_FAVICON_REMOVED` (file name, MIME type and size only).
- Company name: `COMPANY_NAME_CHANGED`.
- User preferences: `BROWSER_NOTIFICATIONS_ENABLED` / `DISABLED` and `NOTIFICATION_SOUND_ENABLED` / `DISABLED`.
- Individual pushes are not audited.
