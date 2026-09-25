# Lead Module (Phase 2)

How the lead CRM behaves, where each rule lives, and what is deliberately left for later phases. Schema: [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md#phase-2--lead-crm-implemented). Permissions: [PERMISSIONS.md](PERMISSIONS.md).

> **Estimated value (Phase 7.1).** Lead estimated value functionality is temporarily hidden from the CRM UI and exports. Existing stored values are retained in the database for future reactivation.
>
> While `crm.features.lead_value` (`CRM_LEAD_VALUE_ENABLED`) is `false`, which is the default:
>
> - `LeadRequest` has no `estimated_value` rule, so the create/edit forms neither show nor accept it. A submitted value is dropped by validation, and editing a lead never overwrites the stored value.
> - `LeadPresenter` and `LeadController` omit `estimated_value` (and the status `probability`) from list, card, pipeline, form and Lead 360 props.
> - The Vue pages hide the field when the key is absent.
>
> The `leads.estimated_value` column, its data, the model cast and the Meta merge protection are unchanged. The column is still documented in DATABASE_SCHEMA.md. Setting the flag to `true` restores the field everywhere.

---

## 1. Who sees what

Every lead read goes through `App\Services\Leads\LeadVisibility` (bound as a scoped singleton, memoised per user per request).

| Tier | Permission | Sees |
|------|------------|------|
| All | `lead.view_all` | every lead, including unassigned |
| Own | `lead.view` | leads where `assigned_to` = themselves |
| None | — | nothing |

There is no team tier. `leads.team_id`, team membership and `users.manager_id` never grant access (the column is deprecated, kept for history, and no longer written on new leads). Unassigned leads are visible only to `lead.view_all` holders. After a reassignment the previous owner gets 403 on the lead and every nested route, and the UI shows "You no longer have access to this lead."

Applied by: list, pipeline, pipeline "more", global search, duplicate warnings, 360° page, timeline, notes, attachments, assignment and status routes (`LeadPolicy` → `canView()`). A user who guesses another lead id gets **403** (audited as `ACCESS_DENIED`), not a filtered page.

The browser only receives what `App\Http\Presenters\LeadPresenter` maps — no raw models, no attachment paths, no fields the user may not see.

## 2. Lead numbers

`LeadNumberService::next()` → `LD-2026-000001`.

- Prefix: setting `lead.number_prefix` (sanitised to `A-Z0-9`, default `LD`). Period: current year in the app timezone.
- Row in `number_sequences` is created with `insertOrIgnore`, then `lockForUpdate` + increment inside a transaction — safe under concurrency, never `MAX()+1`.
- Numbers are never reused, even when a lead is archived.

## 3. Phone normalisation

`PhoneNormalizer::normalize()` produces digits-only international numbers without `+`:

| Input | Output (country code 91) |
|-------|-------------------------|
| `+91 98765-43210` | `919876543210` |
| `098765 43210` | `919876543210` |
| `9876543210` | `919876543210` |
| `0044 20 7946 0958` | `442079460958` |

`+` or `00` means "already international". Otherwise the default country code (setting `lead.default_country_code`) is prepended when the length matches that country's national number length. Fewer than 6 digits → `null`. Both `phone` and `alternate_phone` are normalised and indexed.

## 4. Duplicate handling

`LeadDuplicateService` matches in this order: normalised phone (primary **or** alternate, both sides) → email (case-insensitive) → `facebook_lead_id`. Archived leads are ignored.

Setting `lead.duplicate_handling`:

| Mode | Manual create (UI) | Inbound (`createFromInbound`: Facebook, import) |
|------|--------------------|-----------------------------------------------|
| `merge` (default) | warn → user confirms → new lead flagged `is_duplicate` | new `lead_enquiries` row on the existing lead + activity + `LEAD_ENQUIRY_RECEIVED`; no new lead |
| `flag` | warn → confirm → flagged new lead | new lead flagged `is_duplicate`, `duplicate_of_id` set |
| `allow` | no warning | new lead, not flagged |

**No leakage rule:** the warning (`POST /leads/duplicate-check` and the create validation) only lists matches from `findVisibleMatches()` — leads the current user can already see. If the only match belongs to someone else, the user sees no warning at all (not even "a duplicate exists"); the lead is still flagged internally via `findAnyMatch()` so `lead.view_all` users see it. Flagging writes `LEAD_DUPLICATE_DETECTED`.

## 5. Assignment

All ownership changes go through `LeadAssignmentService::assign()`, which writes `lead_assignments` history, a timeline activity, an audit entry (`LEAD_ASSIGNED` for unassigned → user, `LEAD_REASSIGNED` otherwise) and fires `LeadAssigned`.

Manual assignment (`POST /leads/{lead}/assign`):

- Needs `lead.assign` (lead currently unassigned) or `lead.reassign` (already owned), **and** view access to the lead.
- Target user is re-resolved server-side: with `lead.assign` / `lead.reassign`, any active user; without, only yourself. Out-of-scope or inactive ids return a validation error. Only the new owner is notified; a previous owner who doesn't hold `lead.view_all` loses access immediately.
- Sales Executives have neither permission, so they can never claim or hand over leads by crafting requests.

On create (`LeadService::create`):

1. User **without** `lead.assign` → becomes the owner; any submitted `assigned_to` / `team_id` is ignored.
2. User with `lead.assign` who picked an assignee → that assignee (scope-checked as above).
3. Otherwise → assignment rules run. If no rule applies, or the result would hide the lead from its creator, the creator becomes the owner.

### Assignment rules

Admin UI: `/admin/assignment-rules` (`lead.assignment_rules`). Active rules run in `priority` order (lower first, then id); the first rule that **matches and resolves a target** wins.

| Condition | Matches when |
|-----------|--------------|
| `any` | always |
| `source` / `campaign` | lead's id equals the value |
| `team` | **deprecated** — rules using it never run |
| `city` / `state` | case-insensitive text equality |
| `facebook_form` | lead's `facebook_form_id` equals the Meta form id (validated against synced forms; Phase 5) |

| Assignment | Target |
|------------|--------|
| `user` | a specific active user |
| `round_robin` | next active user from a selected pool of users |
| `team` / `team_round_robin` | **deprecated** — never executed |

Round-robin rotation stores `last_assigned_user_id` on the rule and is taken under `lockForUpdate`, so concurrent inbound leads do not double-assign. The pointer resets when the pool or type changes. Inactive users are skipped. Every rule change is audited (`ASSIGNMENT_RULE_*`). If no rule resolves, the lead stays **unassigned** (visible to admins only). Rules never write `leads.team_id`.

**Deprecated team rules.** Existing rules with a `team` / `team_round_robin` assignment or a `team` condition are kept for history. `LeadAssignmentRule::isDeprecated()` flags them:

- The engine skips them, and the next matching user rule applies.
- The admin list shows "Deprecated — not running".
- They can't be switched back on.
- Validation no longer accepts team types or conditions for new or edited rules.

Recreate them as user or round-robin rules with selected users.

### Assignment notifications (Phase 7.1)

`App\Listeners\NotifyLeadAssignee` handles `LeadAssigned`. Manual and rule assignment, whether on create or later, sends the new owner an in-app `LeadAssignedNotification` ("New lead assigned to you: …" or "Lead reassigned to you: …"). If the owner opted in, it also sends a browser push ("New Lead Assigned — Amit Desai has been assigned to you.").

- Self-assignment is silent.
- Meta inbound leads keep `FacebookLeadNotification`, so the listener skips them.
- The setting `notifications.notify_on_assignment` still applies.
- `LeadAssigned` fires only on a real ownership change, so repeating an assignment never notifies twice.
- A notification or push failure never fails the assignment.

Details: [BROWSER_NOTIFICATIONS.md](BROWSER_NOTIFICATIONS.md).

## 6. Statuses, Won and Lost

Statuses are configurable (`/admin/lead-settings/statuses`); exactly one is default; flags `is_won` / `is_lost` drive behaviour. System statuses and any status in use cannot be deleted.

`LeadService::changeStatus()` (list, 360° page, pipeline drag & drop all use it):

- **Lost** → requires an active lost reason (setting `lead.require_lost_reason`, default on); sets `lost_at`, `lost_reason_id`, `lost_reason_notes`. Pipeline drops onto a lost column open the reason modal first.
- **Won** → sets `converted_at` (kept if already set).
- **Reopen** (from won/lost to any other status) → clears won/lost fields; the timeline entry records the previous lost reason.
- The create form cannot start a lead as won or lost.
- Writes an activity (`status_changed`, `lead_won`, `lead_lost`, `lead_reopened`), `LEAD_STATUS_CHANGED` audit with old/new, and fires `LeadStatusChanged`.

Priority (`low`/`medium`/`high`/`urgent`) changes from the 360° sidebar write `LEAD_PRIORITY_CHANGED`.

## 7. Notes

Visibilities `team` (labelled **Shared** in the UI: anyone who can view the lead) / `private` / `management` (see PERMISSIONS.md). Edits keep full history (`lead_note_histories`: old/new content and visibility, editor, time) viewable from the note menu. Deletes are soft. Audit entries store only the note id and visibility — **never the content**. Timeline entries about a note (added / edited / deleted) are hidden from users who cannot read that note (`LeadNoteService::scopeTimeline()`), so even the existence of a private or management note is not revealed.

## 8. Custom fields

Admin UI: `/admin/custom-fields` (`lead.configure`). Types: text, textarea, number, date, dropdown, multiselect, checkbox. Validation is generated on the backend by `LeadCustomFieldService::rules()` (required flag, type rules, allowed options); the frontend only renders inputs. Unknown field keys are dropped. Value changes are audited as `LEAD_CUSTOM_FIELD_UPDATED` with old/new values.

## 9. Attachments

- Stored on the private `local` disk at `leads/{lead_id}/{uuid}.{ext}`; never under `public/`.
- Max 10 MB; allowed: pdf, doc, docx, xls, xlsx, csv, txt, jpg, jpeg, png, webp (extension **and** MIME checked).
- Downloads stream through `LeadAttachmentController@download` only: `file.download` + lead visibility + `throttle:sensitive`, `Cache-Control: no-store`, audited `LEAD_ATTACHMENT_DOWNLOADED`.
- Sales Executives lack `file.download` by default → they can upload and see the file list but cannot download.

## 10. List, search and pipeline

- `GET /leads`: every filter (status, source, campaign, owner or unassigned, priority, age bucket, created-date range, city/state prefix, Facebook Page, Facebook form, duplicates only, archived) and every sort is applied in SQL by `LeadQueryService`; results are paginated (25/50/100). There is no team filter. Owner filter options are offered only to `lead.view_all` users.
- Age buckets: `0-1`, `2-3`, `4-7`, `8-15`, `15+` days since creation.
- Search (list and topbar `GET /search/leads`, 8 results):
  - `LD-…` → lead number prefix
  - ≥ 10 digits → exact normalised phone (primary or alternate)
  - 4–9 digits → phone suffix `LIKE`
  - otherwise → prefix `LIKE` on name, email, company (wildcards escaped)
- Pipeline (`/leads/pipeline`): one column per active status, 25 cards per column + "load more", grouped counts; drag & drop requires `lead.change_status` and is re-authorized per lead on the server.
- Archive = soft delete (`lead.delete`); restore needs `lead.restore` (Super Admin by default). Archived leads disappear from lists, pipeline and duplicate checks.

## 11. Activity vs audit

| | `activities` | `audit_logs` |
|--|--------------|--------------|
| Purpose | business timeline shown on the lead | security / compliance trail |
| Written by | `ActivityService::record()` | `AuditService::log()` |
| Contains | readable text ("Status changed from New to Contacted") | action enum, old/new values, IP, user agent, route |
| Visible to | anyone who can view the lead | `audit.view` holders |

Audit events added in Phase 2: `LEAD_CREATED`, `LEAD_UPDATED`, `LEAD_VIEWED` (once per user/lead per 30 min), `LEAD_ASSIGNED`, `LEAD_REASSIGNED`, `LEAD_STATUS_CHANGED`, `LEAD_PRIORITY_CHANGED`, `LEAD_ARCHIVED`, `LEAD_RESTORED`, `LEAD_DUPLICATE_DETECTED`, `LEAD_ENQUIRY_RECEIVED`, `LEAD_CUSTOM_FIELD_UPDATED`, `LEAD_NOTE_CREATED/UPDATED/DELETED`, `LEAD_ATTACHMENT_UPLOADED/DOWNLOADED/DELETED`, `ASSIGNMENT_RULE_CREATED/UPDATED/ENABLED/DISABLED/DELETED`, `CONFIGURATION_CREATED/UPDATED/DELETED` (lookups and custom fields).

## 12. Demo data (local only)

`php artisan db:seed` in the `local` environment runs `LeadDemoSeeder` through the real services: 26 leads (`LD-2026-000001` … `000026`) spread across Rahul, Priya and the managers, 2 unassigned inbound leads, 3 Facebook leads distributed by a round-robin rule, sample campaigns and rules, and one flagged duplicate (Priya's "Amit D." duplicates Rahul's "Amit"). All demo users use password `Password@123`.

## 13. Not in Phase 2

- Follow-ups were added in Phase 3 — see [FOLLOWUP_MODULE.md](FOLLOWUP_MODULE.md); they now write `next_followup_at` / `last_contacted_at`.
- Meetings were added in Phase 4 — see [MEETING_MODULE.md](MEETING_MODULE.md). The 360° page's Meetings tab lists the lead's visible meetings (Upcoming / Past / Completed / Cancelled) with a Schedule meeting action, and completing a meeting can mark the lead contacted, change its status (through `LeadService::changeStatus`, Lost still requires a reason) and schedule the next follow-up (through `FollowupService`). Meetings on a lead are only visible to users who can see the lead.
- Bulk actions, import and **export** — no routes exist. Export will be added in Phase 6 behind `lead.export`.
- Event listeners/notifications for `LeadCreated`, `LeadAssigned`, `LeadStatusChanged` (Phase 3).
- Facebook ingestion was added in Phase 5 — see [FACEBOOK_INTEGRATION.md](FACEBOOK_INTEGRATION.md) and §14 below.
- Setting `lead.stale_after_days` is stored but not yet used (Phase 3/6 dashboards).

## 14. Meta / Facebook leads (Phase 5)

- Meta leads enter only through `LeadService::createFromInbound()`, called by `MetaLeadIngestionService`. That means the same duplicate policy (§4), assignment rules (§5, including the `facebook_form` condition), activity and audit as other inbound leads. The creator is the system (`created_by` null).
- **Enquiries:**
  - Every Meta submission creates a `lead_enquiries` row with `channel = facebook` and `external_id = leadgen_id` (unique together).
  - `enquiry_data_json` holds all answers. `metadata_json` holds the platform (Facebook / Instagram), Page, form, campaign, ad set and ad names, `is_organic` and the question labels.
  - The Enquiries tab shows these with readable labels. Answers are rendered as text, never HTML.
- **Merges:** a repeat submission with `lead.duplicate_handling = merge` adds an enquiry to the existing lead. It fills only empty contact and custom fields and never changes owner, status, priority, value or notes.
- **Stored ids:** leads keep `facebook_lead_id` (unique), `facebook_form_id`, `facebook_page_id`, `facebook_campaign_id`, `facebook_adset_id` and `facebook_ad_id`. The campaign is linked through `campaigns (platform facebook, external_id)`.
- **Lead 360 integration panel:** shown when the lead came from Meta. It lists platform, Page, form, campaign, ad set, ad, received time and enquiry count. The "View webhook event" link appears only for `facebook.manage` holders.
- **Lead list:** the Page and form filters are applied in SQL. The options come from synced Pages and forms.
- **Notifications:** the assignee gets "New Facebook lead assigned" (or "Instagram"), and the owner gets "New Facebook enquiry from existing lead" on a merge. Both are sent once, after commit.

## 15. Calls (Phase 6)

- **Call button** (header and quick actions on Lead 360): offers one entry per filled contact field (`phone`, `alternate_phone`) and per available mode (browser / phone). The browser sends only `lead_id` + `contact_field`, and the server resolves the number from the lead. The button is disabled with a reason when the user has no calling account or calling is switched off. Starting a call while an outcome is still owed for the previous call is refused ("Save the outcome of your last call first."). The `tel:` link stays as a fallback.
- **Calls tab:** shows the lead's visible calls (up to 50, newest first) with status, duration, agent, disposition, "Add outcome" and an inline player when the user may listen.
- **Lead updates from calls:** a completed call sets `last_contacted_at` and adds a `call_completed` activity. Busy, no-answer and failed calls add an activity but do not mark the lead contacted. The outcome modal can change the lead status through `LeadService::changeStatus` (Lost still requires a lost reason), schedule a follow-up (`FollowupService`) or schedule a meeting (`MeetingService`).
- **Incoming calls:** matched by `normalized_phone` / `normalized_alternate_phone`. An unknown number never creates a lead automatically; the screen-pop offers **Create lead**, which opens `leads.create?phone=` prefilled.
- **Visibility:** calls on a lead are visible only to users who can see the lead. After reassignment, the previous agent no longer sees the lead's calls, although the historical agent is kept on the call. See [TELEPHONY_MODULE.md](TELEPHONY_MODULE.md).
## 16. Status history and reports (Phase 7)

Every lead creation and status change through `LeadService` also writes a `lead_status_changes` row (from, to, when, by whom, and the owner at that moment; the legacy `team_id` column is no longer written). Existing history was backfilled from the activity timeline. Reports use it for the funnel, stage durations and "won/lost in the period"; the lead's current status is unchanged by this. Leads created outside `LeadService` (direct imports) have no history, and reports fall back to their current status instead of inventing transitions. Lead metrics in reports are attributed to the lead's current owner. See [REPORTING_MODULE.md](REPORTING_MODULE.md).