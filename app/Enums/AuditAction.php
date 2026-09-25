<?php

namespace App\Enums;

enum AuditAction: string
{
    case Login = 'LOGIN';
    case Logout = 'LOGOUT';
    case LoginFailed = 'LOGIN_FAILED';
    case LoginLockout = 'LOGIN_LOCKOUT';
    case PasswordChanged = 'PASSWORD_CHANGED';

    case LeadCreated = 'LEAD_CREATED';
    case LeadViewed = 'LEAD_VIEWED';
    case LeadUpdated = 'LEAD_UPDATED';
    case LeadAssigned = 'LEAD_ASSIGNED';
    case LeadReassigned = 'LEAD_REASSIGNED';
    case LeadStatusChanged = 'LEAD_STATUS_CHANGED';
    case LeadDeleted = 'LEAD_DELETED';
    case LeadArchived = 'LEAD_ARCHIVED';
    case LeadRestored = 'LEAD_RESTORED';
    case LeadPriorityChanged = 'LEAD_PRIORITY_CHANGED';
    case LeadDuplicateDetected = 'LEAD_DUPLICATE_DETECTED';
    case LeadEnquiryReceived = 'LEAD_ENQUIRY_RECEIVED';
    case LeadCustomFieldUpdated = 'LEAD_CUSTOM_FIELD_UPDATED';

    case LeadNoteCreated = 'LEAD_NOTE_CREATED';
    case LeadNoteUpdated = 'LEAD_NOTE_UPDATED';
    case LeadNoteDeleted = 'LEAD_NOTE_DELETED';

    case LeadAttachmentUploaded = 'LEAD_ATTACHMENT_UPLOADED';
    case LeadAttachmentDownloaded = 'LEAD_ATTACHMENT_DOWNLOADED';
    case LeadAttachmentDeleted = 'LEAD_ATTACHMENT_DELETED';

    case AssignmentRuleCreated = 'ASSIGNMENT_RULE_CREATED';
    case AssignmentRuleUpdated = 'ASSIGNMENT_RULE_UPDATED';
    case AssignmentRuleEnabled = 'ASSIGNMENT_RULE_ENABLED';
    case AssignmentRuleDisabled = 'ASSIGNMENT_RULE_DISABLED';
    case AssignmentRuleDeleted = 'ASSIGNMENT_RULE_DELETED';

    case ConfigurationCreated = 'CONFIGURATION_CREATED';
    case ConfigurationUpdated = 'CONFIGURATION_UPDATED';
    case ConfigurationDeleted = 'CONFIGURATION_DELETED';

    case NoteCreated = 'NOTE_CREATED';
    case NoteUpdated = 'NOTE_UPDATED';
    case NoteDeleted = 'NOTE_DELETED';

    case FollowupCreated = 'FOLLOWUP_CREATED';
    case FollowupUpdated = 'FOLLOWUP_UPDATED';
    case FollowupCompleted = 'FOLLOWUP_COMPLETED';
    case FollowupRescheduled = 'FOLLOWUP_RESCHEDULED';
    case FollowupCancelled = 'FOLLOWUP_CANCELLED';
    case FollowupDeleted = 'FOLLOWUP_DELETED';
    case FollowupRestored = 'FOLLOWUP_RESTORED';
    case FollowupReminderSent = 'FOLLOWUP_REMINDER_SENT';
    case FollowupAccessDenied = 'FOLLOWUP_ACCESS_DENIED';

    case MeetingCreated = 'MEETING_CREATED';
    case MeetingUpdated = 'MEETING_UPDATED';
    case MeetingCancelled = 'MEETING_CANCELLED';
    case MeetingCompleted = 'MEETING_COMPLETED';
    case MeetingAssigned = 'MEETING_ASSIGNED';
    case MeetingConfirmed = 'MEETING_CONFIRMED';
    case MeetingStarted = 'MEETING_STARTED';
    case MeetingRescheduled = 'MEETING_RESCHEDULED';
    case MeetingNoShow = 'MEETING_NO_SHOW';
    case MeetingDeleted = 'MEETING_DELETED';
    case MeetingRestored = 'MEETING_RESTORED';
    case MeetingParticipantAdded = 'MEETING_PARTICIPANT_ADDED';
    case MeetingParticipantRemoved = 'MEETING_PARTICIPANT_REMOVED';
    case MeetingAttendanceUpdated = 'MEETING_ATTENDANCE_UPDATED';
    case MeetingConflictOverridden = 'MEETING_CONFLICT_OVERRIDDEN';
    case MeetingReminderSent = 'MEETING_REMINDER_SENT';
    case MeetingAccessDenied = 'MEETING_ACCESS_DENIED';

    case UserCreated = 'USER_CREATED';
    case UserUpdated = 'USER_UPDATED';
    case UserDisabled = 'USER_DISABLED';
    case UserEnabled = 'USER_ENABLED';
    case UserPasswordReset = 'USER_PASSWORD_RESET';

    case RoleCreated = 'ROLE_CREATED';
    case RoleUpdated = 'ROLE_UPDATED';
    case RoleDeleted = 'ROLE_DELETED';
    case PermissionChanged = 'PERMISSION_CHANGED';

    case TeamCreated = 'TEAM_CREATED';
    case TeamUpdated = 'TEAM_UPDATED';
    case TeamDeleted = 'TEAM_DELETED';

    case SettingChanged = 'SETTING_CHANGED';

    case ExportAttempted = 'EXPORT_ATTEMPTED';
    case ExportCompleted = 'EXPORT_COMPLETED';
    case FileDownloaded = 'FILE_DOWNLOADED';
    case AccessDenied = 'ACCESS_DENIED';

    case FacebookWebhookReceived = 'FACEBOOK_WEBHOOK_RECEIVED';
    case FacebookConnected = 'FACEBOOK_CONNECTED';
    case FacebookDisconnected = 'FACEBOOK_DISCONNECTED';
    case FacebookConnectionFailed = 'FACEBOOK_CONNECTION_FAILED';
    case FacebookConnectionChecked = 'FACEBOOK_CONNECTION_CHECKED';
    case FacebookPageSynced = 'FACEBOOK_PAGE_SYNCED';
    case FacebookPageSubscribed = 'FACEBOOK_PAGE_SUBSCRIBED';
    case FacebookPageUnsubscribed = 'FACEBOOK_PAGE_UNSUBSCRIBED';
    case FacebookFormSynced = 'FACEBOOK_FORM_SYNCED';
    case FacebookFormUpdated = 'FACEBOOK_FORM_UPDATED';
    case FacebookFieldMappingUpdated = 'FACEBOOK_FIELD_MAPPING_UPDATED';
    case FacebookSettingsUpdated = 'FACEBOOK_SETTINGS_UPDATED';
    case FacebookWebhookRejected = 'FACEBOOK_WEBHOOK_REJECTED';
    case FacebookWebhookFailed = 'FACEBOOK_WEBHOOK_FAILED';
    case FacebookWebhookRetried = 'FACEBOOK_WEBHOOK_RETRIED';
    case FacebookLeadsSyncRequested = 'FACEBOOK_LEADS_SYNC_REQUESTED';
    case FacebookLeadCreated = 'FACEBOOK_LEAD_CREATED';
    case FacebookEnquiryCreated = 'FACEBOOK_ENQUIRY_CREATED';

    case CallInitiated = 'CALL_INITIATED';
    case CallAnswered = 'CALL_ANSWERED';
    case CallCompleted = 'CALL_COMPLETED';
    case CallFailed = 'CALL_FAILED';
    case CallMissed = 'CALL_MISSED';
    case CallDispositionAdded = 'CALL_DISPOSITION_ADDED';
    case CallDispositionChanged = 'CALL_DISPOSITION_CHANGED';
    case CallNotesUpdated = 'CALL_NOTES_UPDATED';
    case CallRecordingAvailable = 'CALL_RECORDING_AVAILABLE';
    case CallRecordingListened = 'CALL_RECORDING_LISTENED';
    case CallRecordingDownloaded = 'CALL_RECORDING_DOWNLOADED';
    case CallRecordingDeleted = 'CALL_RECORDING_DELETED';
    case CallWebhookRejected = 'CALL_WEBHOOK_REJECTED';
    case CallAccessDenied = 'CALL_ACCESS_DENIED';
    case TelephonyConfigurationChanged = 'TELEPHONY_CONFIGURATION_CHANGED';
    case TelephonyUserChanged = 'TELEPHONY_USER_CHANGED';

    case ReportViewed = 'REPORT_VIEWED';
    case ReportExported = 'REPORT_EXPORTED';
    case ReportExportDownloaded = 'REPORT_EXPORT_DOWNLOADED';

    case BrandingLogoUpdated = 'BRANDING_LOGO_UPDATED';
    case BrandingLogoRemoved = 'BRANDING_LOGO_REMOVED';
    case BrandingFaviconUpdated = 'BRANDING_FAVICON_UPDATED';
    case BrandingFaviconRemoved = 'BRANDING_FAVICON_REMOVED';
    case CompanyNameChanged = 'COMPANY_NAME_CHANGED';

    case BrowserNotificationsEnabled = 'BROWSER_NOTIFICATIONS_ENABLED';
    case BrowserNotificationsDisabled = 'BROWSER_NOTIFICATIONS_DISABLED';
    case NotificationSoundEnabled = 'NOTIFICATION_SOUND_ENABLED';
    case NotificationSoundDisabled = 'NOTIFICATION_SOUND_DISABLED';
}
