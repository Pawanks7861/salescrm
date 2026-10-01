<?php

use App\Http\Controllers\Admin\AssignmentRuleController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CustomFieldController;
use App\Http\Controllers\Admin\FollowupSettingsController;
use App\Http\Controllers\Admin\Integrations\FacebookFormController;
use App\Http\Controllers\Admin\Integrations\FacebookIntegrationController;
use App\Http\Controllers\Admin\Integrations\FacebookWebhookEventController;
use App\Http\Controllers\Admin\LeadSettingsController;
use App\Http\Controllers\Admin\LoginHistoryController;
use App\Http\Controllers\Admin\MeetingSettingsController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Support\SettingDefinitions;
use Illuminate\Support\Facades\Route;

/*
| Loaded with: web, auth, active middleware; prefix /admin; name admin.*
| Route-level permission middleware is the first gate; policies re-check per record.
*/

Route::middleware('throttle:crm')->group(function () {
    Route::get('users', [UserController::class, 'index'])->middleware('permission:user.view')->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->middleware('permission:user.create')->name('users.create');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:user.create')->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('permission:user.edit')->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:user.edit')->name('users.update');
    Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->middleware('permission:user.disable')->name('users.toggle-active');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware(['permission:user.reset_password', 'throttle:sensitive'])->name('users.reset-password');
    Route::put('users/{user}/permissions', [UserController::class, 'updatePermissions'])->middleware('permission:role.manage')->name('users.permissions');

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:role.view')->name('roles.index');
    Route::get('roles/{role}', [RoleController::class, 'edit'])->middleware('permission:role.view')->name('roles.edit');
    Route::middleware('permission:role.manage')->group(function () {
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->name('roles.permissions');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    $groups = array_keys(SettingDefinitions::GROUPS);
    Route::get('settings/{group?}', [SettingController::class, 'index'])
        ->middleware('permission:settings.view|settings.manage')
        ->whereIn('group', $groups)
        ->name('settings.index');
    Route::put('settings/{group}', [SettingController::class, 'update'])
        ->middleware('permission:settings.manage')
        ->whereIn('group', $groups)
        ->name('settings.update');
    Route::post('settings/alert-everyone', [SettingController::class, 'alertEveryone'])
        ->middleware(['permission:settings.manage', 'throttle:sensitive'])
        ->name('settings.alert');
    Route::middleware(['permission:settings.manage', 'throttle:sensitive'])->group(function () {
        Route::post('branding/{type}', [BrandingController::class, 'store'])->whereIn('type', ['logo', 'favicon'])->name('branding.store');
        Route::delete('branding/{type}', [BrandingController::class, 'destroy'])->whereIn('type', ['logo', 'favicon'])->name('branding.destroy');
    });

    Route::middleware('permission:audit.view')->group(function () {
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
    });

    Route::get('login-history', [LoginHistoryController::class, 'index'])->middleware('permission:login_history.view')->name('login-history.index');

    Route::middleware('permission:lead.configure')->group(function () {
        $types = LeadSettingsController::TYPES;
        Route::get('lead-settings/{type?}', [LeadSettingsController::class, 'index'])->whereIn('type', $types)->name('lead-settings.index');
        Route::post('lead-settings/{type}', [LeadSettingsController::class, 'store'])->whereIn('type', $types)->name('lead-settings.store');
        Route::post('lead-settings/{type}/reorder', [LeadSettingsController::class, 'reorder'])->whereIn('type', $types)->name('lead-settings.reorder');
        Route::put('lead-settings/{type}/{id}', [LeadSettingsController::class, 'update'])->whereIn('type', $types)->whereNumber('id')->name('lead-settings.update');
        Route::delete('lead-settings/{type}/{id}', [LeadSettingsController::class, 'destroy'])->whereIn('type', $types)->whereNumber('id')->name('lead-settings.destroy');

        Route::get('custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
        Route::post('custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
        Route::post('custom-fields/reorder', [CustomFieldController::class, 'reorder'])->name('custom-fields.reorder');
        Route::put('custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
        Route::delete('custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');
    });

    Route::middleware('permission:followup.configure')->group(function () {
        Route::get('followup-settings', [FollowupSettingsController::class, 'index'])->name('followup-settings.index');
        Route::put('followup-settings/settings', [FollowupSettingsController::class, 'updateSettings'])->name('followup-settings.settings');
        Route::post('followup-settings/types', [FollowupSettingsController::class, 'store'])->name('followup-settings.types.store');
        Route::post('followup-settings/types/reorder', [FollowupSettingsController::class, 'reorder'])->name('followup-settings.types.reorder');
        Route::put('followup-settings/types/{followupType}', [FollowupSettingsController::class, 'update'])->name('followup-settings.types.update');
        Route::delete('followup-settings/types/{followupType}', [FollowupSettingsController::class, 'destroy'])->name('followup-settings.types.destroy');
    });

    Route::middleware('permission:meeting.configure')->group(function () {
        Route::get('meeting-settings', [MeetingSettingsController::class, 'index'])->name('meeting-settings.index');
        Route::put('meeting-settings/settings', [MeetingSettingsController::class, 'updateSettings'])->name('meeting-settings.settings');
        Route::post('meeting-settings/types', [MeetingSettingsController::class, 'store'])->name('meeting-settings.types.store');
        Route::post('meeting-settings/types/reorder', [MeetingSettingsController::class, 'reorder'])->name('meeting-settings.types.reorder');
        Route::put('meeting-settings/types/{meetingType}', [MeetingSettingsController::class, 'update'])->name('meeting-settings.types.update');
        Route::delete('meeting-settings/types/{meetingType}', [MeetingSettingsController::class, 'destroy'])->name('meeting-settings.types.destroy');
    });

    Route::middleware('permission:lead.assignment_rules')->group(function () {
        Route::get('assignment-rules', [AssignmentRuleController::class, 'index'])->name('assignment-rules.index');
        Route::post('assignment-rules', [AssignmentRuleController::class, 'store'])->name('assignment-rules.store');
        Route::post('assignment-rules/reorder', [AssignmentRuleController::class, 'reorder'])->name('assignment-rules.reorder');
        Route::put('assignment-rules/{rule}', [AssignmentRuleController::class, 'update'])->name('assignment-rules.update');
        Route::post('assignment-rules/{rule}/toggle', [AssignmentRuleController::class, 'toggle'])->name('assignment-rules.toggle');
        Route::delete('assignment-rules/{rule}', [AssignmentRuleController::class, 'destroy'])->name('assignment-rules.destroy');
    });

    Route::middleware('permission:facebook.manage')->prefix('integrations/facebook')->name('integrations.facebook.')->group(function () {
        Route::get('/', [FacebookIntegrationController::class, 'index'])->name('index');
        Route::post('connect', [FacebookIntegrationController::class, 'connect'])->middleware('throttle:sensitive')->name('connect');
        Route::get('callback', [FacebookIntegrationController::class, 'callback'])->middleware('throttle:sensitive')->name('callback');
        Route::post('manual-token', [FacebookIntegrationController::class, 'manualToken'])->middleware('throttle:sensitive')->name('manual-token');
        Route::post('test', [FacebookIntegrationController::class, 'test'])->middleware('throttle:sensitive')->name('test');
        Route::post('disconnect', [FacebookIntegrationController::class, 'disconnect'])->middleware('throttle:sensitive')->name('disconnect');
        Route::post('pages/refresh', [FacebookIntegrationController::class, 'refreshPages'])->middleware('throttle:sensitive')->name('pages.refresh');
        Route::put('pages/{facebookPage}', [FacebookIntegrationController::class, 'updatePage'])->name('pages.update');
        Route::post('pages/{facebookPage}/forms/refresh', [FacebookIntegrationController::class, 'refreshForms'])->middleware('throttle:sensitive')->name('forms.refresh');
        Route::put('settings', [FacebookIntegrationController::class, 'updateSettings'])->name('settings');

        Route::put('forms/{facebookForm}', [FacebookFormController::class, 'update'])->name('forms.update');
        Route::get('forms/{facebookForm}/mapping', [FacebookFormController::class, 'mapping'])->name('forms.mapping');
        Route::put('forms/{facebookForm}/mapping', [FacebookFormController::class, 'saveMapping'])->name('forms.mapping.save');
        Route::post('forms/{facebookForm}/sync-leads', [FacebookFormController::class, 'syncLeads'])->middleware('throttle:sensitive')->name('forms.sync-leads');

        Route::get('events', [FacebookWebhookEventController::class, 'index'])->name('events.index');
        Route::post('events/{facebookEvent}/retry', [FacebookWebhookEventController::class, 'retry'])->name('events.retry');
    });
});
