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

The highest tier held wins. Leads implement this in one class, `App\Services\Leads\LeadVisibility`: `apply()` (SQL) and `canView()` (PHP) mirror each other and are used by the list, search, pipeline, duplicate warnings, `LeadPolicy` and every nested lead route (notes, attachments, assignment, status, follow-ups, meetings). An **unassigned** lead is visible only to `lead.view_all` holders (Admin / Super Admin by default). Team membership, `team_id` columns and `manager_id` never grant access.

Access follows the current owner. When a lead is reassigned, the previous owner loses access to the lead and its notes, attachments, enquiries, follow-ups and meetings on the next request. An open lead page then shows "You no longer have access to this lead" (`Error.vue`, context `lead`).

Assignment (`LeadVisibility::assignableUserIds()`): holders of `lead.assign` / `lead.reassign` may hand a lead **they can see** to any active user. If they are not the new owner and don't hold `lead.view_all`, they lose access. Everyone else may only take ownership themselves. A crafted `assigned_to` / `to_user_id` outside that set is rejected with a validation error, never silently applied.

### Deprecated team permissions

`lead.view_team`, `followup.view_team`, `meeting.view_team`, `report.view_team`, `team.view` and `team.manage` are listed in `App\Support\Permissions::DEPRECATED` (`call.view_team` was deleted together with the other call permissions when telephony was removed):

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

### Calls (removed)

The telephony module and all twelve `call.*` permissions were removed. Migration `2026_10_01_100000_remove_telephony_module` deletes the permission rows together with every role grant and user override that referenced them, and bumps the permission cache version so no session keeps a stale grant. The Roles screen no longer shows a Calls group. Historical audit rows about calls remain readable to `audit.view` holders.

### Batches

A batch is an organisational group of leads (`batches` + `batch_leads` pivot, many-to-many). Batch permissions **never** widen lead visibility:

- `batch.view` lets a user open the batch list and batch pages, but the leads, counts and status cards on them are always filtered by `LeadVisibility`. A batch's `created_by` grants nothing.
- `batch.manage_leads` (plus `lead.view` or `lead.view_all`) allows adding and removing leads. `BatchService` re-checks **every** submitted lead id with one `Lead::visibleTo()` query. If any id is hidden or missing, the whole request is rejected with a validation error. A crafted `POST /batches/{batch}/leads` therefore cannot add hidden leads, and hidden leads cannot be removed either.
- Membership changes only touch `batch_leads`: lead owner, status, follow-ups, meetings and notifications are never changed. Archived batches accept no new leads. Deleting a batch removes its memberships, never its leads.
- There is no batch export.

**Trainers.** A trainer is an ordinary CRM user with the system role `trainer` (no separate trainer table). Assignments live in the `batch_trainers` pivot (unique per batch + trainer).

- `batch.manage_trainers` allows assigning and removing trainers: on the batch form, from the lead list's "Create New Batch", and on the batch page. Without it, any trainer field in a crafted request is answered with 403.
- `BatchService` checks every submitted id server-side: the user must exist, be active, not be deleted, and hold the `trainer` role. Super Admin, Admin and sales users cannot be assigned. The trainer search returns active trainers only, with no email or phone.
- Archived batches accept no new trainers, but existing ones can still be removed. Deactivated trainers keep their history and are shown as "Inactive". Removing a trainer only deletes the pivot row.
- **A batch trainer assignment is not a lead permission override.** Being a batch's trainer grants nothing on its leads; a trainer sees only the leads their own role already allows. The default `trainer` role holds only `batch.view` and `chat.use`, so trainers see batches but no leads.

### Internal chat and priority messages

- `chat.use` (every default role) allows one-to-one chat with other active users who also hold `chat.use`. Every conversation, message, read, typing and attachment endpoint checks that the session user is a **participant** of that conversation. A conversation id or message id alone grants nothing. Self-chat is refused.
- **No role can read other people's private chats**, including Admin and Super Admin. Opening someone else's conversation returns 403 and audits `ACCESS_DENIED` with the path only. Message text is never copied into the audit log.
- Chat attachments are private files under `storage/app/private/chat/{conversation}` with UUID names. They are downloaded only through the participant-checked route, and non-images are always served as attachments, never inline.
- Presence (`last_seen_at`, "online" = seen within 2 minutes) is only shown inside chat to users who can chat with that person. There is no public presence endpoint.
- `priority_broadcast.send` (Admin and Super Admin by default) sends an urgent message to every active user except the sender. Recipients are snapshotted at send time.
- `priority_broadcast.view_history` (Admin and Super Admin by default) opens the history and per-recipient read/acknowledged list. Recipients can always open their own message, and only they can mark it read or acknowledge it.

### Reports

`App\Services\Reports\ReportScope` uses the same two tiers: `report.view` (own records) and `report.view_all` (company, including unassigned leads). It **ANDs** the module visibility services (`LeadVisibility`, `FollowupVisibility`, `MeetingVisibility`) with a report-tier cap on the owner columns, so `lead.view_all` alone never widens a report beyond OWN. Only `report.view_all` users get the salesperson filter. Filter options (people, cities, states) come from the same scope. Out-of-scope user ids and any `team` parameter in a request are ignored. There is no team report or team filter (`/reports/teams` returns 404). `report.export` is required for CSV exports; without it the backend returns 403 and audits `EXPORT_ATTEMPTED`. Downloads are owner-only. See [REPORTING_MODULE.md](REPORTING_MODULE.md).

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
| Batches | `batch.view` | View batches (only the leads the user can already see) |
| | `batch.create` | Create batches |
| | `batch.edit` | Edit batch details |
| | `batch.delete` | Archive, restore and delete batches (leads are never deleted) |
| | `batch.manage_leads` | Add / remove visible leads to / from batches |
| | `batch.manage_trainers` | Assign / remove trainers on batches (active `trainer`-role users only) |
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
| Notes | `note.edit_any`, `note.delete`, `note.view_management`, `note.view_private` | Edit others' notes, delete notes, read "management" notes, read other users' private notes |
| Users | `user.view`, `user.create`, `user.edit`, `user.disable`, `user.delete`, `user.reset_password` | |
| Roles | `role.view`, `role.manage` | View roles / edit role permissions and user overrides |
| Reports | `report.view`, `report.view_all`, `report.export` | Own / company reports, CSV export |
| Audit | `audit.view`, `login_history.view` | |
| Settings | `settings.view`, `settings.manage` | `settings.manage` also covers company logo / favicon upload and removal (`admin.branding.*`) and the global browser-notification and sound switches |
| Integrations | `facebook.manage` | Connect/disconnect Meta, choose Pages, manage forms and field mapping, view and retry webhook events, backfill leads, Meta settings. Super Admin only by default |
| Files | `file.view`, `file.upload`, `file.download` | |
| Chat | `chat.use` | One-to-one internal chat with other `chat.use` holders (participants only; no admin read access) |
| Priority messages | `priority_broadcast.send`, `priority_broadcast.view_history` | Send urgent team-wide messages / view history with read and acknowledgement counts |

## Default roles

| Role | Slug | Notes |
|------|------|-------|
| Super Admin | `super_admin` | System role, all access, cannot be edited or deleted |
| Admin | `admin` | Broad operational access; **no** `role.manage`, `facebook.manage`, `lead.restore`, `user.delete` by default |
| Sales Manager | `sales_manager` | **Own records only** (no `*.view_all`, no team visibility). It keeps explicit extras: `lead.assign` / `lead.reassign` / `lead.edit_source`, `followup.assign` / `followup.delete`, `meeting.assign` / `meeting.override_conflict` / `meeting.create_without_lead`, `note.edit_any` / `note.delete` / `note.view_management`, `user.view`, `file.download`. These all apply only to records the manager can see. Grant a `*.view_all` permission on the Roles screen if a manager should see everything |
| Sales Executive | `sales_executive` | Own records and own-data reports only; no export, import, bulk, delete, reassign, file download, audit, settings |
| Trainer | `trainer` | Can be assigned to batches. Holds only `batch.view` and `chat.use` by default: sees batches but no leads |

### Access matrix (Own / All)

| Capability | Super Admin | Admin | Sales Manager (default) | Sales Executive |
|------------|-------------|-------|-------------------------|-----------------|
| Leads, notes, attachments, enquiries | All | All | Own | Own |
| Unassigned leads | ✔ | ✔ | ✖ | ✖ |
| Follow-ups / meetings | All | All | Own (+ on own leads) | Own (+ on own leads) |
| Reports / dashboard | Company (incl. Unassigned) | Company (incl. Unassigned) | Own ("My") | Own ("My") |
| Salesperson report filter | ✔ | ✔ | ✖ | ✖ |
| Assign / reassign a visible lead | ✔ | ✔ | ✔ (own leads) | ✖ |
| Report export | ✔ | ✔ | ✖ | ✖ |

"Own" means `leads.assigned_to = me`. For follow-ups and meetings it also covers records assigned to or hosted by me, and every one of them requires the parent lead to be visible.

Permissions added by a later release are granted to **existing** system roles only if they are in that role's defaults (`RoleSeeder` uses `syncWithoutDetaching` on newly created permissions), so admin customisations are never overwritten. Phase 2 therefore gave Admin `lead.edit_source`, `lead.configure`, `lead.assignment_rules`, `note.view_private`, and Sales Manager `lead.edit_source`.

Phase 3 added `followup.view_all`, `followup.cancel`, `followup.assign`, `followup.schedule_past`, `followup.configure`. Defaults: Sales Executive gets `followup.cancel` (plus the existing view/create/edit/complete); Sales Manager gets `followup.cancel`, `followup.assign`; Admin gets all five. Sales Executives have no follow-up delete, assign, back-dating or bulk completion.

Phase 4 added `meeting.complete`, `meeting.assign`, `meeting.create_without_lead`, `meeting.schedule_past`, `meeting.configure` (no duplicates of existing names; the spec's `meeting.reschedule` / `meeting.restore` are covered by `meeting.edit` / `meeting.delete`). Defaults: Sales Executive gets `meeting.complete` (plus the existing view/create/edit/cancel); Sales Manager gets `meeting.assign`, `meeting.create_without_lead` (it already held `meeting.override_conflict`; its former `meeting.view_team` is now deprecated); Admin gets all five. Sales Executives cannot assign hosts, override conflicts, delete meetings, schedule in the past or configure meeting types.

Batch Management added `batch.view`, `batch.create`, `batch.edit`, `batch.delete`, `batch.manage_leads`. Defaults: Sales Executive gets `batch.view`, `batch.manage_leads`; Sales Manager also gets `batch.create`, `batch.edit`; Admin gets all five. All of them stay limited to leads the user can already see.

Trainer Assignment added `batch.manage_trainers` and the `trainer` system role. Defaults: Admin and Sales Manager get `batch.manage_trainers`; Sales Executive does not. `db:seed --class=CoreSeeder` creates the permission and the role and grants it without touching other customisations.

Phase 5 added **no new permission**; it uses the existing `facebook.manage`, which stays granted to Super Admin only (Admin does not hold it by default; it can be granted on the Roles screen). The permission gates:

- every `/admin/integrations/facebook/*` route, including the OAuth callback, which additionally requires the single-use `state`;
- the dashboard Meta widget;
- the "View webhook event" link on Lead 360.

The manual system-user token form additionally requires the Super Admin role **and** `META_ALLOW_MANUAL_TOKEN=true`. The public webhook (`/webhooks/meta/leads`) is authenticated by Meta's signature, not by users or permissions. Facebook and Instagram leads follow the normal lead visibility tiers, so a Sales Executive sees only the Facebook leads assigned to them. The Lead 360 integration panel and Enquiries tab show only Page, form and campaign names, never tokens.

Phase 6 (telephony) has been removed. Its twelve `call.*` permissions no longer exist in the catalogue, in `RoleSeeder` defaults or in the database (see "Calls (removed)" above).

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
