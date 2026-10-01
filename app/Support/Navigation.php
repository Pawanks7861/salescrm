<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar definition. Items appear only when the user holds one of the listed
 * permissions AND the route exists, so modules light up as phases ship.
 */
final class Navigation
{
    /** @return array<int, array{title: string, items: array}> */
    public static function for(User $user): array
    {
        $sections = [];

        foreach (self::definition() as $section) {
            $items = array_values(array_filter($section['items'], function (array $item) use ($user) {
                return Route::has($item['route'])
                    && ($item['permissions'] === [] || $user->hasAnyPermission(...$item['permissions']));
            }));

            if ($items === []) {
                continue;
            }

            $sections[] = [
                'title' => $section['title'],
                'items' => array_map(fn (array $item) => [
                    'label' => $item['label'],
                    'href' => route($item['route'], $item['params'] ?? [], false),
                    'icon' => $item['icon'],
                    'active' => $item['active'] ?? $item['route'],
                    'badge' => $item['badge'] ?? null,
                ], $items),
            ];
        }

        return $sections;
    }

    private static function definition(): array
    {
        $P = Permissions::class;

        return [
            ['title' => '', 'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'permissions' => []],
                ['label' => 'Chat', 'route' => 'chat.index', 'icon' => 'chat', 'active' => 'chat.*', 'badge' => 'chat', 'permissions' => [$P::CHAT_USE]],
            ]],
            ['title' => 'CRM', 'items' => [
                ['label' => 'Leads', 'route' => 'leads.index', 'icon' => 'users', 'active' => ['leads.index', 'leads.show', 'leads.create', 'leads.edit'], 'permissions' => [$P::LEAD_VIEW, $P::LEAD_VIEW_ALL]],
                ['label' => 'Batches', 'route' => 'batches.index', 'icon' => 'stack', 'active' => 'batches.*', 'permissions' => [$P::BATCH_VIEW]],
                ['label' => 'Follow-ups', 'route' => 'followups.index', 'icon' => 'followup', 'active' => 'followups.*', 'permissions' => [$P::FOLLOWUP_VIEW, $P::FOLLOWUP_VIEW_ALL]],
                ['label' => 'Meetings', 'route' => 'meetings.index', 'icon' => 'video', 'active' => 'meetings.*', 'permissions' => [$P::MEETING_VIEW, $P::MEETING_VIEW_ALL]],
                ['label' => 'Calendar', 'route' => 'calendar.index', 'icon' => 'calendar', 'active' => 'calendar.*', 'permissions' => [$P::MEETING_VIEW, $P::MEETING_VIEW_ALL]],
            ]],
            ['title' => 'Sales', 'items' => [
                ['label' => 'Pipeline', 'route' => 'leads.pipeline', 'icon' => 'columns', 'active' => 'leads.pipeline', 'permissions' => [$P::LEAD_VIEW, $P::LEAD_VIEW_ALL]],
                ['label' => 'Activities', 'route' => 'activities.index', 'icon' => 'activity', 'active' => 'activities.*', 'permissions' => [$P::LEAD_VIEW, $P::LEAD_VIEW_ALL]],
            ]],
            ['title' => 'Reports', 'items' => [
                ['label' => 'Report Centre', 'route' => 'reports.index', 'icon' => 'chart-pie', 'active' => 'reports.index', 'permissions' => [$P::REPORT_VIEW, $P::REPORT_VIEW_ALL]],
                ['label' => 'Sales Overview', 'route' => 'reports.show', 'params' => ['report' => 'overview'], 'icon' => 'presentation', 'active' => ['name' => 'reports.show', 'params' => ['report' => 'overview']], 'permissions' => [$P::REPORT_VIEW, $P::REPORT_VIEW_ALL]],
                ['label' => 'Pipeline Report', 'route' => 'reports.show', 'params' => ['report' => 'pipeline'], 'icon' => 'filter', 'active' => ['name' => 'reports.show', 'params' => ['report' => 'pipeline']], 'permissions' => [$P::REPORT_VIEW, $P::REPORT_VIEW_ALL]],
                ['label' => 'Performance', 'route' => 'reports.show', 'params' => ['report' => 'sales-performance'], 'icon' => 'trophy', 'active' => ['name' => 'reports.show', 'params' => ['report' => 'sales-performance']], 'permissions' => [$P::REPORT_VIEW, $P::REPORT_VIEW_ALL]],
            ]],
            ['title' => 'People', 'items' => [
                ['label' => 'Users', 'route' => 'admin.users.index', 'icon' => 'user', 'active' => 'admin.users.*', 'permissions' => [$P::USER_VIEW]],
            ]],
            ['title' => 'Integrations', 'items' => [
                ['label' => 'Facebook', 'route' => 'admin.integrations.facebook.index', 'icon' => 'facebook', 'active' => 'admin.integrations.facebook.*', 'permissions' => [$P::FACEBOOK_MANAGE]],            ]],
            ['title' => 'Administration', 'items' => [
                ['label' => 'Roles & Permissions', 'route' => 'admin.roles.index', 'icon' => 'shield', 'active' => 'admin.roles.*', 'permissions' => [$P::ROLE_VIEW]],
                ['label' => 'Lead Settings', 'route' => 'admin.lead-settings.index', 'icon' => 'tag', 'active' => 'admin.lead-settings.*', 'permissions' => [$P::LEAD_CONFIGURE]],
                ['label' => 'Custom Fields', 'route' => 'admin.custom-fields.index', 'icon' => 'adjustments', 'active' => 'admin.custom-fields.*', 'permissions' => [$P::LEAD_CONFIGURE]],
                ['label' => 'Assignment Rules', 'route' => 'admin.assignment-rules.index', 'icon' => 'switch', 'active' => 'admin.assignment-rules.*', 'permissions' => [$P::LEAD_ASSIGNMENT_RULES]],
                ['label' => 'Follow-up Settings', 'route' => 'admin.followup-settings.index', 'icon' => 'followup', 'active' => 'admin.followup-settings.*', 'permissions' => [$P::FOLLOWUP_CONFIGURE]],
                ['label' => 'Meeting Settings', 'route' => 'admin.meeting-settings.index', 'icon' => 'video', 'active' => 'admin.meeting-settings.*', 'permissions' => [$P::MEETING_CONFIGURE]],
                ['label' => 'Priority Messages', 'route' => 'priority-broadcasts.index', 'icon' => 'warning', 'active' => 'priority-broadcasts.*', 'permissions' => [$P::PRIORITY_BROADCAST_VIEW_HISTORY]],
                ['label' => 'Audit Logs', 'route' => 'admin.audit-logs.index', 'icon' => 'document', 'active' => 'admin.audit-logs.*', 'permissions' => [$P::AUDIT_VIEW]],
                ['label' => 'Login History', 'route' => 'admin.login-history.index', 'icon' => 'key', 'active' => 'admin.login-history.*', 'permissions' => [$P::LOGIN_HISTORY_VIEW]],
                ['label' => 'System Settings', 'route' => 'admin.settings.index', 'icon' => 'cog', 'active' => 'admin.settings.*', 'permissions' => [$P::SETTINGS_VIEW, $P::SETTINGS_MANAGE]],
            ]],
        ];
    }
}
