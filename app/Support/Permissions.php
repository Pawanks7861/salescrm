<?php

namespace App\Support;

/**
 * Single source of truth for the permission catalogue.
 * PermissionSeeder syncs this list into the `permissions` table.
 */
final class Permissions
{
    public const LEAD_VIEW = 'lead.view';

    public const LEAD_VIEW_TEAM = 'lead.view_team';

    public const LEAD_VIEW_ALL = 'lead.view_all';

    public const LEAD_CREATE = 'lead.create';

    public const LEAD_EDIT = 'lead.edit';

    public const LEAD_DELETE = 'lead.delete';

    public const LEAD_RESTORE = 'lead.restore';

    public const LEAD_ASSIGN = 'lead.assign';

    public const LEAD_REASSIGN = 'lead.reassign';

    public const LEAD_CHANGE_STATUS = 'lead.change_status';

    public const LEAD_BULK_ACTION = 'lead.bulk_action';

    public const LEAD_IMPORT = 'lead.import';

    public const LEAD_EXPORT = 'lead.export';

    public const LEAD_EDIT_SOURCE = 'lead.edit_source';

    public const LEAD_CONFIGURE = 'lead.configure';

    public const LEAD_ASSIGNMENT_RULES = 'lead.assignment_rules';

    public const BATCH_VIEW = 'batch.view';

    public const BATCH_CREATE = 'batch.create';

    public const BATCH_EDIT = 'batch.edit';

    public const BATCH_DELETE = 'batch.delete';

    public const BATCH_MANAGE_LEADS = 'batch.manage_leads';

    public const BATCH_MANAGE_TRAINERS = 'batch.manage_trainers';

    public const FOLLOWUP_VIEW = 'followup.view';

    public const FOLLOWUP_VIEW_TEAM = 'followup.view_team';

    public const FOLLOWUP_CREATE = 'followup.create';

    public const FOLLOWUP_EDIT = 'followup.edit';

    public const FOLLOWUP_DELETE = 'followup.delete';

    public const FOLLOWUP_COMPLETE = 'followup.complete';

    public const FOLLOWUP_VIEW_ALL = 'followup.view_all';

    public const FOLLOWUP_CANCEL = 'followup.cancel';

    public const FOLLOWUP_ASSIGN = 'followup.assign';

    public const FOLLOWUP_SCHEDULE_PAST = 'followup.schedule_past';

    public const FOLLOWUP_CONFIGURE = 'followup.configure';

    public const MEETING_VIEW = 'meeting.view';

    public const MEETING_VIEW_TEAM = 'meeting.view_team';

    public const MEETING_VIEW_ALL = 'meeting.view_all';

    public const MEETING_CREATE = 'meeting.create';

    public const MEETING_EDIT = 'meeting.edit';

    public const MEETING_CANCEL = 'meeting.cancel';

    public const MEETING_DELETE = 'meeting.delete';

    public const MEETING_OVERRIDE_CONFLICT = 'meeting.override_conflict';

    public const MEETING_COMPLETE = 'meeting.complete';

    public const MEETING_ASSIGN = 'meeting.assign';

    public const MEETING_CREATE_WITHOUT_LEAD = 'meeting.create_without_lead';

    public const MEETING_SCHEDULE_PAST = 'meeting.schedule_past';

    public const MEETING_CONFIGURE = 'meeting.configure';

    public const NOTE_EDIT_ANY = 'note.edit_any';

    public const NOTE_DELETE = 'note.delete';

    public const NOTE_VIEW_MANAGEMENT = 'note.view_management';

    public const NOTE_VIEW_PRIVATE = 'note.view_private';

    public const USER_VIEW = 'user.view';

    public const USER_CREATE = 'user.create';

    public const USER_EDIT = 'user.edit';

    public const USER_DISABLE = 'user.disable';

    public const USER_DELETE = 'user.delete';

    public const USER_RESET_PASSWORD = 'user.reset_password';

    public const TEAM_VIEW = 'team.view';

    public const TEAM_MANAGE = 'team.manage';

    public const ROLE_VIEW = 'role.view';

    public const ROLE_MANAGE = 'role.manage';

    public const REPORT_VIEW = 'report.view';

    public const REPORT_VIEW_TEAM = 'report.view_team';

    public const REPORT_VIEW_ALL = 'report.view_all';

    public const REPORT_EXPORT = 'report.export';

    public const AUDIT_VIEW = 'audit.view';

    public const LOGIN_HISTORY_VIEW = 'login_history.view';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const FACEBOOK_MANAGE = 'facebook.manage';

    public const FILE_VIEW = 'file.view';

    public const FILE_UPLOAD = 'file.upload';

    public const FILE_DOWNLOAD = 'file.download';

    public const CHAT_USE = 'chat.use';

    public const PRIORITY_BROADCAST_SEND = 'priority_broadcast.send';

    public const PRIORITY_BROADCAST_VIEW_HISTORY = 'priority_broadcast.view_history';

    /**
     * Legacy team-level permissions. Data visibility is OWN or ALL only, so
     * these are not in the catalogue, are never seeded, and are stripped from
     * every resolved permission set even when an old database row grants them.
     */
    public const DEPRECATED = [
        self::LEAD_VIEW_TEAM,
        self::FOLLOWUP_VIEW_TEAM,
        self::MEETING_VIEW_TEAM,
        self::REPORT_VIEW_TEAM,
        self::TEAM_VIEW,
        self::TEAM_MANAGE,
    ];

    /**
     * @return array<string, array{module: string, label: string}>
     */
    public static function all(): array
    {
        return [
            self::LEAD_VIEW => ['module' => 'Leads', 'label' => 'View own leads'],
            self::LEAD_VIEW_ALL => ['module' => 'Leads', 'label' => 'View all leads'],
            self::LEAD_CREATE => ['module' => 'Leads', 'label' => 'Create leads'],
            self::LEAD_EDIT => ['module' => 'Leads', 'label' => 'Edit leads'],
            self::LEAD_DELETE => ['module' => 'Leads', 'label' => 'Archive leads'],
            self::LEAD_RESTORE => ['module' => 'Leads', 'label' => 'Restore archived leads'],
            self::LEAD_ASSIGN => ['module' => 'Leads', 'label' => 'Assign leads'],
            self::LEAD_REASSIGN => ['module' => 'Leads', 'label' => 'Reassign leads'],
            self::LEAD_CHANGE_STATUS => ['module' => 'Leads', 'label' => 'Change lead status'],
            self::LEAD_BULK_ACTION => ['module' => 'Leads', 'label' => 'Bulk actions on leads'],
            self::LEAD_IMPORT => ['module' => 'Leads', 'label' => 'Import leads'],
            self::LEAD_EXPORT => ['module' => 'Leads', 'label' => 'Export leads'],
            self::LEAD_EDIT_SOURCE => ['module' => 'Leads', 'label' => 'Change lead source & campaign'],
            self::LEAD_CONFIGURE => ['module' => 'Leads', 'label' => 'Configure statuses, sources, campaigns, lost reasons & custom fields'],
            self::LEAD_ASSIGNMENT_RULES => ['module' => 'Leads', 'label' => 'Manage lead assignment rules'],

            self::BATCH_VIEW => ['module' => 'Batches', 'label' => 'View batches (only leads the user can already see)'],
            self::BATCH_CREATE => ['module' => 'Batches', 'label' => 'Create batches'],
            self::BATCH_EDIT => ['module' => 'Batches', 'label' => 'Edit batch details'],
            self::BATCH_DELETE => ['module' => 'Batches', 'label' => 'Archive / delete batches'],
            self::BATCH_MANAGE_LEADS => ['module' => 'Batches', 'label' => 'Add / remove leads in batches'],
            self::BATCH_MANAGE_TRAINERS => ['module' => 'Batches', 'label' => 'Assign / remove trainers on batches'],

            self::FOLLOWUP_VIEW => ['module' => 'Follow-ups', 'label' => 'View own follow-ups'],
            self::FOLLOWUP_CREATE => ['module' => 'Follow-ups', 'label' => 'Create follow-ups'],
            self::FOLLOWUP_EDIT => ['module' => 'Follow-ups', 'label' => 'Edit follow-ups'],
            self::FOLLOWUP_DELETE => ['module' => 'Follow-ups', 'label' => 'Delete follow-ups'],
            self::FOLLOWUP_COMPLETE => ['module' => 'Follow-ups', 'label' => 'Complete follow-ups'],
            self::FOLLOWUP_VIEW_ALL => ['module' => 'Follow-ups', 'label' => 'View all follow-ups'],
            self::FOLLOWUP_CANCEL => ['module' => 'Follow-ups', 'label' => 'Cancel follow-ups'],
            self::FOLLOWUP_ASSIGN => ['module' => 'Follow-ups', 'label' => 'Assign follow-ups to other users'],
            self::FOLLOWUP_SCHEDULE_PAST => ['module' => 'Follow-ups', 'label' => 'Schedule follow-ups in the past'],
            self::FOLLOWUP_CONFIGURE => ['module' => 'Follow-ups', 'label' => 'Configure follow-up types & settings'],

            self::MEETING_VIEW => ['module' => 'Meetings', 'label' => 'View own meetings'],
            self::MEETING_VIEW_ALL => ['module' => 'Meetings', 'label' => 'View all meetings'],
            self::MEETING_CREATE => ['module' => 'Meetings', 'label' => 'Schedule meetings'],
            self::MEETING_EDIT => ['module' => 'Meetings', 'label' => 'Edit / reschedule meetings'],
            self::MEETING_CANCEL => ['module' => 'Meetings', 'label' => 'Cancel meetings'],
            self::MEETING_DELETE => ['module' => 'Meetings', 'label' => 'Delete meetings'],
            self::MEETING_OVERRIDE_CONFLICT => ['module' => 'Meetings', 'label' => 'Override calendar conflicts'],
            self::MEETING_COMPLETE => ['module' => 'Meetings', 'label' => 'Complete meetings / mark no-show'],
            self::MEETING_ASSIGN => ['module' => 'Meetings', 'label' => 'Schedule meetings for other hosts'],
            self::MEETING_CREATE_WITHOUT_LEAD => ['module' => 'Meetings', 'label' => 'Schedule meetings not linked to a lead'],
            self::MEETING_SCHEDULE_PAST => ['module' => 'Meetings', 'label' => 'Schedule meetings in the past'],
            self::MEETING_CONFIGURE => ['module' => 'Meetings', 'label' => 'Configure meeting types & settings'],

            self::NOTE_EDIT_ANY => ['module' => 'Notes', 'label' => "Edit other users' notes"],
            self::NOTE_DELETE => ['module' => 'Notes', 'label' => 'Delete notes'],
            self::NOTE_VIEW_MANAGEMENT => ['module' => 'Notes', 'label' => 'View management-only notes'],
            self::NOTE_VIEW_PRIVATE => ['module' => 'Notes', 'label' => "View other users' private notes"],

            self::USER_VIEW => ['module' => 'Users', 'label' => 'View users'],
            self::USER_CREATE => ['module' => 'Users', 'label' => 'Create users'],
            self::USER_EDIT => ['module' => 'Users', 'label' => 'Edit users'],
            self::USER_DISABLE => ['module' => 'Users', 'label' => 'Activate / deactivate users'],
            self::USER_DELETE => ['module' => 'Users', 'label' => 'Archive users'],
            self::USER_RESET_PASSWORD => ['module' => 'Users', 'label' => 'Reset user passwords'],

            self::ROLE_VIEW => ['module' => 'Roles', 'label' => 'View roles & permissions'],
            self::ROLE_MANAGE => ['module' => 'Roles', 'label' => 'Manage roles & permissions'],

            self::REPORT_VIEW => ['module' => 'Reports', 'label' => 'View own reports'],
            self::REPORT_VIEW_ALL => ['module' => 'Reports', 'label' => 'View all reports'],
            self::REPORT_EXPORT => ['module' => 'Reports', 'label' => 'Export reports'],

            self::AUDIT_VIEW => ['module' => 'Audit', 'label' => 'View audit logs'],
            self::LOGIN_HISTORY_VIEW => ['module' => 'Audit', 'label' => 'View login history'],

            self::SETTINGS_VIEW => ['module' => 'Settings', 'label' => 'View settings'],
            self::SETTINGS_MANAGE => ['module' => 'Settings', 'label' => 'Manage settings'],

            self::FACEBOOK_MANAGE => ['module' => 'Integrations', 'label' => 'Manage Facebook integration'],

            self::FILE_VIEW => ['module' => 'Files', 'label' => 'View attachments'],
            self::FILE_UPLOAD => ['module' => 'Files', 'label' => 'Upload attachments'],
            self::FILE_DOWNLOAD => ['module' => 'Files', 'label' => 'Download attachments'],

            self::CHAT_USE => ['module' => 'Chat', 'label' => 'Use internal one-to-one chat'],
            self::PRIORITY_BROADCAST_SEND => ['module' => 'Chat', 'label' => 'Send priority team messages'],
            self::PRIORITY_BROADCAST_VIEW_HISTORY => ['module' => 'Chat', 'label' => 'View priority message history'],
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }
}
