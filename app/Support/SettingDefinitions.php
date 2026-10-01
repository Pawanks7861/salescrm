<?php

namespace App\Support;

/**
 * Declares every configurable system setting. Keys are "<group>.<name>".
 * Add new settings here; SettingSeeder inserts missing ones with defaults.
 */
final class SettingDefinitions
{
    public const GROUPS = [
        'general' => 'General',
        'lead' => 'Lead',
        'followup' => 'Follow-up',
        'meeting' => 'Meeting',
        'report' => 'Reports',
        'notifications' => 'Notifications',
        'security' => 'Security',
        'email' => 'Email',
    ];

    /**
     * @return array<string, array{type: string, label: string, default: mixed, rules: array, options?: array<string,string>, help?: string}>
     */
    public static function all(): array
    {
        return [
            'general.crm_name' => ['type' => 'string', 'label' => 'CRM name', 'default' => 'Sales CRM', 'rules' => ['required', 'string', 'max:100']],
            'general.company_name' => ['type' => 'string', 'label' => 'Company name', 'default' => '', 'rules' => ['nullable', 'string', 'max:150']],
            'general.timezone' => ['type' => 'string', 'label' => 'Timezone', 'default' => 'Asia/Kolkata', 'rules' => ['required', 'timezone:all']],
            'general.date_format' => ['type' => 'string', 'label' => 'Date format', 'default' => 'd M Y', 'rules' => ['required', 'string', 'max:20'],
                'options' => ['d M Y' => '24 Sep 2026', 'd/m/Y' => '24/09/2026', 'm/d/Y' => '09/24/2026', 'Y-m-d' => '2026-09-24']],
            'general.time_format' => ['type' => 'string', 'label' => 'Time format', 'default' => 'h:i A', 'rules' => ['required', 'string', 'max:20'],
                'options' => ['h:i A' => '03:30 PM', 'H:i' => '15:30']],
            'general.currency' => ['type' => 'string', 'label' => 'Currency', 'default' => 'INR', 'rules' => ['required', 'string', 'size:3']],

            'lead.number_prefix' => ['type' => 'string', 'label' => 'Lead number prefix', 'default' => 'LD', 'rules' => ['required', 'alpha_dash', 'max:10']],
            'lead.duplicate_handling' => ['type' => 'string', 'label' => 'Duplicate handling', 'default' => 'merge', 'rules' => ['required', 'in:merge,flag,allow'],
                'options' => ['merge' => 'Merge automatically', 'flag' => 'Flag duplicate', 'allow' => 'Allow duplicate']],
            'lead.default_country_code' => ['type' => 'string', 'label' => 'Default phone country code', 'default' => '91', 'rules' => ['required', 'digits_between:1,4']],
            'lead.require_lost_reason' => ['type' => 'boolean', 'label' => 'Require reason when lead is lost', 'default' => true, 'rules' => ['boolean']],
            'lead.stale_after_days' => ['type' => 'integer', 'label' => 'Highlight leads not contacted for (days)', 'default' => 3, 'rules' => ['required', 'integer', 'min:1', 'max:90']],

            'followup.default_reminder_minutes' => ['type' => 'integer', 'label' => 'Default reminder', 'default' => 15, 'rules' => ['required', 'integer', 'in:-1,0,15,30,60,120,1440'],
                'options' => ['-1' => 'No reminder', '0' => 'At the scheduled time', '15' => '15 minutes before', '30' => '30 minutes before', '60' => '1 hour before', '120' => '2 hours before', '1440' => '1 day before']],
            'followup.overdue_alert_after_minutes' => ['type' => 'integer', 'label' => 'Send overdue alert after (minutes past due, 0 = off)', 'default' => 60, 'rules' => ['required', 'integer', 'min:0', 'max:10080']],
            'followup.due_soon_minutes' => ['type' => 'integer', 'label' => '"Due soon" threshold (minutes)', 'default' => 60, 'rules' => ['required', 'integer', 'min:5', 'max:1440']],
            'followup.allow_past' => ['type' => 'boolean', 'label' => 'Allow everyone to schedule follow-ups in the past', 'default' => false, 'rules' => ['boolean']],
            'followup.require_outcome' => ['type' => 'boolean', 'label' => 'Require an outcome to complete a follow-up', 'default' => true, 'rules' => ['boolean']],
            'followup.require_cancellation_reason' => ['type' => 'boolean', 'label' => 'Require a reason to cancel a follow-up', 'default' => true, 'rules' => ['boolean']],

            'meeting.number_prefix' => ['type' => 'string', 'label' => 'Meeting number prefix', 'default' => 'MTG', 'rules' => ['required', 'alpha_dash', 'max:10']],
            'meeting.default_duration_minutes' => ['type' => 'integer', 'label' => 'Default meeting duration (minutes, when the type has none)', 'default' => 30, 'rules' => ['required', 'integer', 'min:5', 'max:480']],
            'meeting.default_reminder_minutes' => ['type' => 'integer', 'label' => 'Default reminder', 'default' => 30, 'rules' => ['required', 'integer', 'in:-1,0,15,30,60,120,1440'],
                'options' => ['-1' => 'No reminder', '0' => 'At the start time', '15' => '15 minutes before', '30' => '30 minutes before', '60' => '1 hour before', '120' => '2 hours before', '1440' => '1 day before']],
            'meeting.require_notes_on_complete' => ['type' => 'boolean', 'label' => 'Require notes to complete a meeting', 'default' => true, 'rules' => ['boolean']],
            'meeting.require_outcome' => ['type' => 'boolean', 'label' => 'Require an outcome to complete a meeting', 'default' => true, 'rules' => ['boolean']],
            'meeting.require_cancellation_reason' => ['type' => 'boolean', 'label' => 'Require a reason to cancel a meeting', 'default' => true, 'rules' => ['boolean']],
            'meeting.allow_past' => ['type' => 'boolean', 'label' => 'Allow everyone to schedule meetings in the past', 'default' => false, 'rules' => ['boolean']],
            'meeting.conflict_checking' => ['type' => 'boolean', 'label' => 'Check host and participant calendar conflicts', 'default' => true, 'rules' => ['boolean']],

            'notifications.email_enabled' => ['type' => 'boolean', 'label' => 'Send email notifications', 'default' => false, 'rules' => ['boolean']],
            'notifications.notify_on_assignment' => ['type' => 'boolean', 'label' => 'Notify users when a lead is assigned', 'default' => true, 'rules' => ['boolean']],
            'notifications.browser_enabled' => ['type' => 'boolean', 'label' => 'Allow browser (push) notifications for new leads and follow-up reminders', 'default' => true, 'rules' => ['boolean'],
                'help' => 'Master switch. Users still opt in per browser from My profile.'],
            'notifications.sound_enabled' => ['type' => 'boolean', 'label' => 'Allow notification sounds', 'default' => true, 'rules' => ['boolean']],
            'notifications.browser_show_names' => ['type' => 'boolean', 'label' => 'Show the lead name in browser notifications (they can appear on device lock screens)', 'default' => true, 'rules' => ['boolean']],

            // Managed by the Branding card on Settings → General (BrandingService), not as plain text fields.
            'branding.logo_path' => ['type' => 'string', 'label' => 'Company logo', 'default' => '', 'rules' => ['nullable', 'string', 'max:191']],
            'branding.favicon_path' => ['type' => 'string', 'label' => 'Favicon', 'default' => '', 'rules' => ['nullable', 'string', 'max:191']],

            'security.password_min_length' => ['type' => 'integer', 'label' => 'Minimum password length', 'default' => 12, 'rules' => ['required', 'integer', 'min:8', 'max:64']],
            'security.max_login_attempts' => ['type' => 'integer', 'label' => 'Failed logins before lockout', 'default' => 5, 'rules' => ['required', 'integer', 'min:3', 'max:20']],
            'security.office_wifi_only' => ['type' => 'boolean', 'label' => 'Allow the CRM only on the Medawk office WiFi', 'default' => false, 'rules' => ['boolean'],
                'help' => 'Sign-in and every signed-in page are refused from any other network. Meta and phone webhooks are not affected. Save at least one IP below or this stays off. If you lock yourself out, set OFFICE_WIFI_BYPASS=true in the server environment and reload config.'],
            'security.office_wifi_ips' => ['type' => 'string', 'label' => 'Medawk WiFi public IPs', 'default' => '', 'rules' => ['nullable', 'string', 'max:1000'],
                'help' => 'Comma or line separated. Use the public IP shown below while you are on the Medawk WiFi, or a range such as 203.0.113.0/24.'],

            // Managed on Admin → Integrations → Facebook (facebook.manage), not the generic settings screen.
            'facebook.placeholder_name' => ['type' => 'string', 'label' => 'Name used when a Meta lead has no name', 'default' => 'Facebook Lead', 'rules' => ['required', 'string', 'max:100']],
            'facebook.use_instagram_source' => ['type' => 'boolean', 'label' => 'Use the "Instagram" lead source for leads submitted on Instagram', 'default' => true, 'rules' => ['boolean']],
            'facebook.auto_enable_new_forms' => ['type' => 'boolean', 'label' => 'Ingest leads from newly discovered forms automatically', 'default' => true, 'rules' => ['boolean']],
            'facebook.auto_assign_user_id' => ['type' => 'integer', 'label' => 'Automatically assign new Facebook leads to', 'default' => 0, 'rules' => ['nullable', 'integer', 'min:0'],
                'help' => 'When a person is selected, every new Facebook lead is assigned to them. Leave this on assignment rules to keep form and source routing.'],
            'facebook.notify_on_repeat_enquiry' => ['type' => 'boolean', 'label' => 'Notify the owner when an existing lead submits another Meta form', 'default' => true, 'rules' => ['boolean']],
            'facebook.event_retention_days' => ['type' => 'integer', 'label' => 'Keep completed webhook event records for (days)', 'default' => 180, 'rules' => ['required', 'integer', 'min:7', 'max:730']],

            'report.qualified_status' => ['type' => 'string', 'label' => 'A lead counts as "qualified" once it reaches this status (or any later non-lost stage)', 'default' => 'interested',
                'rules' => ['required', 'string', 'exists:lead_statuses,slug'], 'help' => 'Lead status slug, e.g. interested, meeting_scheduled. Stage order follows the status sort order.'],
            'report.response_target_minutes' => ['type' => 'integer', 'label' => 'First-response target (minutes)', 'default' => 15, 'rules' => ['required', 'integer', 'in:5,15,30,60,240,1440'],
                'options' => ['5' => '5 minutes', '15' => '15 minutes', '30' => '30 minutes', '60' => '1 hour', '240' => '4 hours', '1440' => '24 hours']],
            'report.untouched_new_lead_hours' => ['type' => 'integer', 'label' => 'Neglected: new lead with no response after (hours)', 'default' => 4, 'rules' => ['required', 'integer', 'min:1', 'max:168']],
            'report.inactive_days' => ['type' => 'integer', 'label' => 'Neglected: open lead with no activity for (days)', 'default' => 7, 'rules' => ['required', 'integer', 'min:1', 'max:180']],
            'report.export_queue_threshold' => ['type' => 'integer', 'label' => 'Queue exports larger than (rows)', 'default' => 2000, 'rules' => ['required', 'integer', 'min:100', 'max:50000']],
            'report.export_retention_hours' => ['type' => 'integer', 'label' => 'Keep generated export files for (hours)', 'default' => 24, 'rules' => ['required', 'integer', 'in:1,6,24,72,168'],
                'options' => ['1' => '1 hour', '6' => '6 hours', '24' => '24 hours', '72' => '3 days', '168' => '7 days']],

            'email.from_name' => ['type' => 'string', 'label' => 'From name', 'default' => 'Sales CRM', 'rules' => ['nullable', 'string', 'max:100']],
            'email.from_address' => ['type' => 'string', 'label' => 'From address', 'default' => '', 'rules' => ['nullable', 'email', 'max:150']],
        ];
    }

    public static function group(string $key): string
    {
        return strtok($key, '.');
    }

    /** @return array<string, array> */
    public static function forGroup(string $group): array
    {
        return array_filter(self::all(), fn ($_, $key) => self::group($key) === $group, ARRAY_FILTER_USE_BOTH);
    }
}
