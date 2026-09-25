# Permissions & Data Access

## Model

- Each user has exactly one **role** (`users.role_id`).
- A role has many **permissions** (`role_permissions`).
- A user may have **overrides** (`user_permissions.type = grant | deny`). Effective permissions = role permissions + grants − denies.
- **Super Admin** (`roles.slug = super_admin`) holds every permission: `User::hasPermission()` returns `true` and `Gate::before` grants all permission abilities (names containing a dot). Policy methods are **not** bypassed, so structural rules still apply to Super Admin too (the Super Admin role is immutable, system roles cannot be deleted, nobody can deactivate or change the role of their own account).
- The catalogue lives in code: `App\Support\Permissions::all()`. Running `php artisan db:seed --class=PermissionSeeder` syncs new permissions into the DB (idempotent). New modules add constants there — no other wiring needed.
- Effective permissions are cached per user (`PermissionRegistrar`), flushed whenever a role's permissions, a user's role, or a user's overrides change.

## Enforcement layers

| Layer | Mechanism | Example |
|-------|-----------|---------|
| Route | `permission:<name>` middleware (any-of with `\|`) | `Route::post('leads/{lead}/assign')->middleware('permission:lead.assign\|lead.reassign')` |
| Record | Policies (`LeadPolicy::view` checks visibility tier) | `$this->authorize('view', $lead)` |
| Query | Visibility scopes | `Lead::visibleTo($user)->paginate()` |
| Field | Services ignore/validate ownership fields | `assigned_to` from a sales user is discarded unless `lead.assign` |
| UI | `usePermissions().can()` | hide buttons (convenience only) |

## Data visibility tiers

There are exactly **two** tiers. There is no team tier and no team fallback.

| Tier | Permission | SQL |
|------|------------|-----|
| Own | `lead.view` | `assigned_to = :user` |
| All | `lead.view_all` | no restriction |
| None | — | `1 = 0` |

The highest tier held wins. Leads implement this in one class, `App\Services\Leads\LeadVisibility`: `apply()` (SQL) and `canView()` (PHP) mirror each other and are used by the list, search, pipeline, duplicate warnings, `LeadPolicy` and every nested lead route (notes, attachments, assignment, status, follow-ups, meetings, calls). An **unassigned** lead is visible only to `lead.view_all` holders (Admin / Super Admin by default). Team membership, `team_id` columns and `manager_id` never grant access.

Access follows the current owner. When a lead is reassigned, the previous owner loses access to the lead and its notes, attachments, enquiries, follow-ups, meetings and calls on the next request. An open lead page then shows "You no longer have access to this lead" (`Error.vue`, context `lead`).

Assignment (`LeadVisibility::assignableUserIds()`): holders of `lead.assign` / `lead.reassign` may hand a lead **they can see** to any active user. If they are not the new owner and don't hold `lead.view_all`, they lose access. Everyone else may only take ownership themselves. A crafted `assigned_to` / `to_user_id` outside that set is rejected with a validation error, never silently applied.

### Deprecated team permissions

`lead.view_team`, `followup.view_team`, `meeting.view_team`, `call.view_team`, `report.view_team`, `team.view` and `team.manage` are listed in `App\Support\Permissions::DEPRECATED`:

- They are **not** in the catalogue, are never seeded, and don't appear on the Roles screen.
- `PermissionRegistrar` strips them from every resolved permission set, so an old role or user-override row granting them has **no effect** (`hasPermission()` returns `false`).
- Existing rows are kept for rollback safety. Migration `2026_09_24_800001_deprecate_team_permissions` only relabels them (module `Deprecated`, label suffix "(deprecated, no effect)"). Its `down()` restores the old labels.

### Note visibility

| Visibility | Label in UI | Readable by |
|------------|-------------|-------------|
| `team` (stored value, kept for compatibility) | **Shared** | anyone who can view the lead |
| `private` | Private | the author, or holders of `note.view_private` |
| `management` | Management | the author, or holders of `note.view_management` (only those users can choose it) |

Editing someone else's note needs `note.edit_any`; deleting needs `note.delete` (authors can edit/delete their own). Note content is never copied into activities or audit logs.

### Files

Listing needs `file.view`; upload needs `file.upload`; both require view access to a non-archived lead. Deleting is allowed for the uploader or `lead.delete` holders. Download needs `file.download` + view access to the lead and is audited (`LEAD_ATTACHMENT_DOWNLOADED`). Sales Executives do **not** hold `file.download` by default, so they cannot download attachments even on their own leads.

### Follow-ups

`App\Services\Followups\FollowupVisibility` uses the same two tiers:

- `followup.view`: follow-ups assigned to me, or on a lead I own.
- `followup.view_all`: every follow-up.

It **always AND-s lead visibility** (`whereHas('lead', LeadVisibility::apply)`). A user who cannot view the parent lead cannot see the follow-up, its notes/outcome/reminder info, or lead data through follow-up lists, search, dashboard widgets or notifications. Assignment targets are yourself only without `followup.assign`, or any active user with it. Either way, the assignee must be able to see the lead. Notification payloads are never treated as authorization; access is re-checked when listing and opening. See [FOLLOWUP_MODULE.md](FOLLOWUP_MODULE.md).

### Meetings

`App\Services\Meetings\MeetingVisibility` uses the same two tiers:

- `meeting.view`: meetings I host, I'm an internal participant in, or that are on a lead I own.
- `meeting.view_all`: every meeting.

It **always AND-s lead visibility** for meetings linked to a lead. Being invited never grants access to someone else's lead: a participant who can't see the lead can't see the meeting.

- **Who can change a meeting:** only the host (OWN) or `view_all` users. Participants may view and RSVP.
- **Hosts:** yourself without `meeting.assign`, or any active user with it.
- **Invitees:** any active user with a meeting tier.

For a lead meeting, both the host and the invitees must be able to see the lead. Conflict messages only describe a clashing meeting the actor can view; otherwise the person is "unavailable during the selected time". See [MEETING_MODULE.md](MEETING_MODULE.md).

### Calls

`App\Services\Telephony\CallVisibility` uses the same two tiers — `call.view` (I was the agent, or the call is on a lead I own) and `call.view_all` — and **always AND-s lead visibility** for calls linked to a lead (archived leads hide their calls below `view_all`). Having made a call never grants access to a lead that has since been reassigned; the historical `agent_user_id` is kept but the call becomes invisible to the former agent. Calls without a lead (unknown callers, manual dials) follow the call tier only. Recordings additionally require `call.recording.listen` / `call.recording.download`; a guessed URL returns 403. The incoming screen-pop only reveals leads the receiving user can see ("Lead information unavailable." otherwise). Outbound calls use a lead contact field resolved on the server; a raw number needs `call.manual_dial`. See [TELEPHONY_MODULE.md](TELEPHONY_MODULE.md).

### Reports

`App\Services\Reports\ReportScope` uses the same two tiers: `report.view` (own records) and `report.view_all` (company, including unassigned leads). It **ANDs** the module visibility services (`LeadVisibility`, `CallVisibility`, `FollowupVisibility`, `MeetingVisibility`) with a report-tier cap on the owner columns, so `lead.view_all` alone never widens a report beyond OWN. Only `report.view_all` users get the salesperson filter. Filter options (people, cities, states) come from the same scope. Out-of-scope user ids and any `team` parameter in a request are ignored. There is no team report or team filter (`/reports/teams` returns 404). `report.export` is required for CSV exports; without it the backend returns 403 and audits `EXPORT_ATTEMPTED`. Downloads are owner-only. See [REPORTING_MODULE.md](REPORTING_MODULE.md).

Lead estimated value is hidden from every role while `crm.features.lead_value` is off (Phase 7.1). This is a feature flag, not a permission, so no role (including Super Admin) sees value KPIs, columns or export fields until it's turned back on.

### Branding and browser notifications (Phase 7.1)

- **Company branding:** upload and removal need `settings.manage` (Admin / Super Admin by default). Sales Managers and Executives get 403. The logo and favicon are public brand assets (`GET /branding/{logo|favicon}`, no login) because the login page shows them.
- **Browser notifications and sound:** every signed-in user manages **only their own** preferences (`PUT /profile/notifications`) and browser subscriptions (`POST` / `DELETE /push-subscriptions`). No permission is needed, and the owner is always the session user. Nobody, including admins, can view or manage another user's subscriptions, and raw endpoints are never shown. The admin-wide switches are ordinary notification settings (`settings.manage`).
- **Push content** follows existing visibility: a push goes only to the lead's new owner or the follow-up's assignee. Clicking it opens `/notifications/{id}/open`, which re-checks that the notification is yours and that you can still see the lead or follow-up.

## Catalogue

| Module | Permission | Description |
|--------|------------|-------------|
| Leads | `lead.view` | View own (assigned) leads |
| | `lead.view_all` | View every lead, including unassigned |
| | `lead.create` | Create leads |
| | `lead.edit` | Edit visible leads (allowed fields) |
| | `lead.delete` | Archive (soft delete) leads |
| | `lead.restore` | Restore archived leads |
| | `lead.assign` | Assign a visible lead to any active user |
| | `lead.reassign` | Change the owner of a visible lead to any active user |
| | `lead.change_status` | Change lead status |
| | `lead.bulk_action` | Bulk assign / status / archive |
| | `lead.import` | Import leads |
| | `lead.export` | Export leads (no export endpoint exists yet — Phase 6) |
| | `lead.edit_source` | Change a lead's source / campaign after creation |
| | `lead.configure` | Manage statuses, sources, lost reasons, campaigns and custom fields |
| | `lead.assignment_rules` | Manage automatic assignment rules |
| Follow-ups | `followup.view` | View follow-ups assigned to me or on leads I own |
| | `followup.view_all` | View every follow-up (still limited to visible leads) |
| | `followup.create` | Schedule follow-ups on visible leads |
| | `followup.edit` | Edit and reschedule pending follow-ups |
| | `followup.complete` | Complete pending follow-ups |
| | `followup.cancel` | Cancel pending follow-ups (with reason) |
| | `followup.assign` | Assign follow-ups to other users (who can see the lead) |
| | `followup.delete` | Soft delete / restore follow-ups (clean-up) |
| | `followup.schedule_past` | Schedule follow-ups in the past (back-dating) |
| | `followup.configure` | Manage follow-up types and follow-up settings |
| Meetings | `meeting.view` | View meetings I host or attend, or on leads I own |
| | `meeting.view_all` | View every meeting (still limited to visible leads) |
| | `meeting.create` | Schedule meetings on visible leads |
| | `meeting.edit` | Edit, confirm, reschedule and manage participants of upcoming meetings |
| | `meeting.complete` | Start, complete and mark no-show |
| | `meeting.cancel` | Cancel open meetings (with reason) |
| | `meeting.delete` | Soft delete / restore meetings (clean-up) |
| | `meeting.assign` | Make another user (who can see the lead) the host |
| | `meeting.create_without_lead` | Schedule internal meetings not linked to a lead |
| | `meeting.override_conflict` | Schedule despite a calendar conflict (confirmed, audited) |
| | `meeting.schedule_past` | Schedule meetings in the past |
| | `meeting.configure` | Manage meeting types and meeting settings |
| Calls | `call.view` | View calls I handled or on leads I own |
| | `call.view_all` | View every call (still limited to visible leads) |
| | `call.make` | Place outbound calls (click-to-call / browser) |
| | `call.receive` | Receive incoming calls in the softphone (screen-pop) |
| | `call.manual_dial` | Dial a number that is not a lead contact field |
| | `call.add_disposition` | Save a call outcome (disposition, next action) |
| | `call.edit_notes` | Edit call notes |
| | `call.recording.listen` | Play recordings of visible calls (audited) |
| | `call.recording.download` | Download a single recording (audited, throttled) |
| | `call.configure` | Admin → Telephony: integration, numbers, calling accounts, dispositions, settings |
| | `call.monitor` | View provider callback events on the call page |
| Notes | `note.edit_any`, `note.delete`, `note.view_management`, `note.view_private` | Edit others' notes, delete notes, read "management" notes, read other users' private notes |
| Users | `user.view`, `user.create`, `user.edit`, `user.disable`, `user.delete`, `user.reset_password` | |
| Roles | `role.view`, `role.manage` | View roles / edit role permissions and user overrides |
| Reports | `report.view`, `report.view_all`, `report.export` | Own / company reports, CSV export |
| Audit | `audit.view`, `login_history.view` | |
| Settings | `settings.view`, `settings.manage` | `settings.manage` also covers company logo / favicon upload and removal (`admin.branding.*`) and the global browser-notification and sound switches |
| Integrations | `facebook.manage` | Connect/disconnect Meta, choose Pages, manage forms and field mapping, view and retry webhook events, backfill leads, Meta settings. Super Admin only by default |
| | `call.configure` | Telephony integration (see Calls) |
| Files | `file.view`, `file.upload`, `file.download` | |

## Default roles

| Role | Slug | Notes |
|------|------|-------|
| Super Admin | `super_admin` | System role, all access, cannot be edited or deleted |
| Admin | `admin` | Broad operational access; **no** `role.manage`, `facebook.manage`, `lead.restore`, `user.delete` by default |
| Sales Manager | `sales_manager` | **Own records only** (no `*.view_all`, no team visibility). It keeps explicit extras: `lead.assign` / `lead.reassign` / `lead.edit_source`, `followup.assign` / `followup.delete`, `meeting.assign` / `meeting.override_conflict` / `meeting.create_without_lead`, `note.edit_any` / `note.delete` / `note.view_management`, `user.view`, `file.download`, `call.recording.listen`. These all apply only to records the manager can see. Grant a `*.view_all` permission on the Roles screen if a manager should see everything |
| Sales Executive | `sales_executive` | Own records and own-data reports only; no export, import, bulk, delete, reassign, recording listen/download, file download, audit, settings |

### Access matrix (Own / All)

| Capability | Super Admin | Admin | Sales Manager (default) | Sales Executive |
|------------|-------------|-------|-------------------------|-----------------|
| Leads, notes, attachments, enquiries | All | All | Own | Own |
| Unassigned leads | ✔ | ✔ | ✖ | ✖ |
| Follow-ups / meetings / calls | All | All | Own (+ on own leads) | Own (+ on own leads) |
| Reports / dashboard | Company (incl. Unassigned) | Company (incl. Unassigned) | Own ("My") | Own ("My") |
| Salesperson report filter | ✔ | ✔ | ✖ | ✖ |
| Assign / reassign a visible lead | ✔ | ✔ | ✔ (own leads) | ✖ |
| Recording listen | ✔ | ✔ | ✔ (visible calls) | ✖ |
| Recording download / report export | ✔ | export only | ✖ | ✖ |

"Own" means `leads.assigned_to = me`. For follow-ups, meetings and calls it also covers records assigned to, hosted by or handled by me, and every one of them requires the parent lead to be visible.

Permissions added by a later release are granted to **existing** system roles only if they are in that role's defaults (`RoleSeeder` uses `syncWithoutDetaching` on newly created permissions), so admin customisations are never overwritten. Phase 2 therefore gave Admin `lead.edit_source`, `lead.configure`, `lead.assignment_rules`, `note.view_private`, and Sales Manager `lead.edit_source`.

Phase 3 added `followup.view_all`, `followup.cancel`, `followup.assign`, `followup.schedule_past`, `followup.configure`. Defaults: Sales Executive gets `followup.cancel` (plus the existing view/create/edit/complete); Sales Manager gets `followup.cancel`, `followup.assign`; Admin gets all five. Sales Executives have no follow-up delete, assign, back-dating or bulk completion.

Phase 4 added `meeting.complete`, `meeting.assign`, `meeting.create_without_lead`, `meeting.schedule_past`, `meeting.configure` (no duplicates of existing names; the spec's `meeting.reschedule` / `meeting.restore` are covered by `meeting.edit` / `meeting.delete`). Defaults: Sales Executive gets `meeting.complete` (plus the existing view/create/edit/cancel); Sales Manager gets `meeting.assign`, `meeting.create_without_lead` (it already held `meeting.override_conflict`; its former `meeting.view_team` is now deprecated); Admin gets all five. Sales Executives cannot assign hosts, override conflicts, delete meetings, schedule in the past or configure meeting types.

Phase 5 added **no new permission**; it uses the existing `facebook.manage`, which stays granted to Super Admin only (Admin does not hold it by default; it can be granted on the Roles screen). The permission gates:

- every `/admin/integrations/facebook/*` route, including the OAuth callback, which additionally requires the single-use `state`;
- the dashboard Meta widget;
- the "View webhook event" link on Lead 360.

The manual system-user token form additionally requires the Super Admin role **and** `META_ALLOW_MANUAL_TOKEN=true`. The public webhook (`/webhooks/meta/leads`) is authenticated by Meta's signature, not by users or permissions. Facebook and Instagram leads follow the normal lead visibility tiers, so a Sales Executive sees only the Facebook leads assigned to them. The Lead 360 integration panel and Enquiries tab show only Page, form and campaign names, never tokens.

Phase 6 added the twelve `call.*` permissions. Defaults:

| Role | Call permissions |
|------|------------------|
| Sales Executive | `call.view`, `call.make`, `call.receive`, `call.add_disposition`, `call.edit_notes` |
| Sales Manager | the above + `call.recording.listen` (the original `call.view_team` grant is deprecated and has no effect) |
| Admin | all except `call.recording.download` |
| Super Admin | everything (bypass) |

Nobody except Super Admin can download recordings by default; grant `call.recording.download` explicitly on the Roles screen. Sales Executives cannot listen to recordings, manual-dial or configure telephony. There is no call export permission because no call export exists.

Phase 7 added **no new permission**. It uses the existing `report.*` permissions, and the Phase 7 migration grants `report.view` to the existing Sales Executive role (also in `RoleSeeder` defaults) so executives see reports on **their own data only**. Defaults:

| Role | Report permissions |
|------|--------------------|
| Sales Executive | `report.view` (own), no export |
| Sales Manager | `report.view` (own), **no export**; grant `report.export` / `report.view_all` explicitly if needed (the original `report.view_team` grant is deprecated and has no effect) |
| Admin | `report.view_all`, `report.export` |
| Super Admin | everything (bypass) |

### Phase 3 pre-flight (documented, not changed)

Verified by `tests/Feature/Security/RoleDefaultsPreflightTest.php` before Phase 3 work: Super Admin can use every Phase 2 lead function; Sales Executives do not hold `file.download`; `lead.restore` is not granted to Admin by default (Super Admin only). No discrepancy with the documented defaults was found, so no default was changed.

The exact default grants are defined in `database/seeders/RoleSeeder.php` and summarised in [CRM_ARCHITECTURE.md §4](CRM_ARCHITECTURE.md#4-rbac-matrix).

## Rules that are never permission-configurable

- Audit logs cannot be edited or deleted by anyone through the application.
- Super Admin role cannot be deleted, renamed, or have permissions removed.
- A user cannot change their own role or deactivate themselves.
- Only a Super Admin can assign the Super Admin role.
