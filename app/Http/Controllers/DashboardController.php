<?php

namespace App\Http\Controllers;

use App\Http\Presenters\FollowupPresenter;
use App\Http\Presenters\MeetingPresenter;
use App\Models\AuditLog;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LoginHistory;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Followups\FollowupMetrics;
use App\Services\Followups\FollowupOptions;
use App\Services\Followups\FollowupQueryService;
use App\Services\Followups\FollowupVisibility;
use App\Services\Leads\LeadOptions;
use App\Services\Meetings\MeetingMetrics;
use App\Services\Meetings\MeetingVisibility;
use App\Services\Meta\MetaIntegrationService;
use App\Services\Reports\ReportService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Organisation health for admins (Phase 1), the follow-up workspace
 * (Phase 3) and meeting widgets (Phase 4), each scoped by its visibility rules.
 */
class DashboardController extends Controller
{
    private const LIST_LIMIT = 8;

    public function __construct(
        private readonly FollowupVisibility $followupVisibility,
        private readonly FollowupQueryService $followupQueries,
        private readonly FollowupPresenter $followupPresenter,
        private readonly FollowupMetrics $followupMetrics,
        private readonly FollowupOptions $followupOptions,
        private readonly LeadOptions $leadOptions,
        private readonly MeetingVisibility $meetingVisibility,
        private readonly MeetingMetrics $meetingMetrics,
        private readonly MeetingPresenter $meetingPresenter,
        private readonly MetaIntegrationService $metaIntegrations,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'stats' => $this->safely(function () use ($user) {
                $stats = [];
                if ($user->hasPermission(Permissions::USER_VIEW)) {
                    $stats['users_active'] = User::active()->count();
                    $stats['users_inactive'] = User::where('is_active', false)->count();
                }
                if ($user->hasPermission(Permissions::LOGIN_HISTORY_VIEW)) {
                    $stats['failed_logins_today'] = LoginHistory::where('event', 'failed')->where('created_at', '>=', today())->count();
                }

                return $stats;
            }, []),
            'recentAudit' => $this->safely(fn () => $user->hasPermission(Permissions::AUDIT_VIEW)
                ? AuditLog::with('user:id,name')->latest('id')->limit(10)->get()
                    ->map(fn (AuditLog $log) => [
                        ...$log->only('id', 'action', 'module', 'description'),
                        'user' => $log->user?->name,
                        'created_at' => $log->created_at?->toIso8601String(),
                    ])
                : null, null),
            'sales' => $this->safely(fn () => $this->sales($user), null),
            'meetings' => $this->safely(fn () => $this->meetings($user), null),
            'facebook' => $this->safely(fn () => $this->facebook($user), null),
            'reportKpis' => $this->safely(fn () => app(ReportService::class)->dashboard($user), null),
            'lastLogin' => $this->safely(fn () => LoginHistory::where('user_id', $user->id)
                ->where('event', 'login')
                ->latest('id')
                ->skip(1)
                ->first(['ip_address', 'browser', 'platform', 'created_at']), null),
        ]);
    }

    /** One widget failing must not replace the whole dashboard with an error page. */
    private function safely(callable $resolve, mixed $fallback): mixed
    {
        try {
            return $resolve();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }

    /**
     * Small Meta widget for integration managers. Local data only (no Graph
     * calls); the lead count still respects the viewer's lead visibility.
     */
    private function facebook(User $user): ?array
    {
        if (! $user->hasPermission(Permissions::FACEBOOK_MANAGE)) {
            return null;
        }

        $health = $this->metaIntegrations->health();

        return [
            'status_label' => $health['status_label'],
            'status_color' => $health['status_color'],
            'connected' => $health['connected'],
            'leads_today' => Lead::query()->visibleTo($user)->whereNotNull('facebook_lead_id')->where('created_at', '>=', today())->count(),
            'failed_events' => $health['failed_events'],
            'last_lead_at' => $health['last_lead_at'],
            'last_webhook_at' => $health['last_webhook_at'],
            'token_expiring' => $health['token_expiring'],
            'pages_unsubscribed' => $health['pages_unsubscribed'],
        ];
    }

    /**
     * Follow-up workspace scoped by FollowupVisibility: "My" for sales users,
     * "Company" for view_all. Null when the user has no follow-up access.
     * Every figure comes from one aggregate query.
     */
    private function sales(User $user): ?array
    {
        if (! $user->can('viewAny', Followup::class)) {
            return null;
        }

        $tier = $this->followupVisibility->tier($user);
        $row = fn (Followup $f) => $this->followupPresenter->row($f, $user);
        $list = fn (string $tab) => $this->followupQueries->order($this->followupQueries->applyTab($this->followupQueries->base($user), $tab), $tab)
            ->with(FollowupPresenter::ROW_WITH)
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map($row);

        $canViewLeads = $user->can('viewAny', Lead::class);

        $recentlyAssigned = $canViewLeads
            ? LeadAssignment::query()
                ->where('to_user_id', $user->id)
                ->whereHas('lead', fn ($q) => $q->visibleTo($user))
                ->with('lead:id,lead_number,full_name,company_name,priority,status_id', 'lead.status:id,name,color')
                ->latest('id')
                ->limit(self::LIST_LIMIT * 2)
                ->get()
                ->unique('lead_id')
                ->take(self::LIST_LIMIT)
                ->filter(fn (LeadAssignment $a) => $a->lead !== null)
                ->map(fn (LeadAssignment $a) => [
                    'id' => $a->lead->id,
                    'lead_number' => $a->lead->lead_number,
                    'full_name' => $a->lead->full_name,
                    'company_name' => $a->lead->company_name,
                    'priority' => $a->lead->priority?->value,
                    'status' => $a->lead->status?->only('name', 'color'),
                    'assigned_at' => $a->created_at?->toIso8601String(),
                ])
                ->values()
            : null;

        return [
            'scope' => $tier === FollowupVisibility::ALL ? 'Company' : 'My',
            'leads' => $canViewLeads ? Lead::query()->visibleTo($user)->count() : null,
            'unassigned' => $user->hasPermission(Permissions::LEAD_VIEW_ALL) ? Lead::query()->whereNull('assigned_to')->count() : null,
            'counts' => $this->followupMetrics->summary($user),
            'overdue' => $list('overdue'),
            'today' => $list('today'),
            'recentlyAssigned' => $recentlyAssigned,
            'form' => [
                ...$this->followupOptions->form($user),
                'statuses' => $this->leadOptions->statuses(),
                'lostReasons' => $this->leadOptions->lostReasons(),
                'canChangeLeadStatus' => $user->hasPermission(Permissions::LEAD_CHANGE_STATUS),
            ],
        ];
    }

    /**
     * Meeting widgets scoped by MeetingVisibility (My / Company).
     * Null when the user has no meeting access.
     */
    private function meetings(User $user): ?array
    {
        if (! $user->can('viewAny', Meeting::class)) {
            return null;
        }

        $next = $this->meetingMetrics->next($user, false)?->load(MeetingPresenter::ROW_WITH);

        return [
            'scope' => $this->meetingVisibility->tier($user) === MeetingVisibility::ALL ? 'Company' : 'My',
            'counts' => $this->meetingMetrics->summary($user),
            'next' => $next ? $this->meetingPresenter->row($next, $user) : null,
            'today' => $this->meetingMetrics->today($user, false, self::LIST_LIMIT)
                ->with(MeetingPresenter::ROW_WITH)
                ->get()
                ->map(fn (Meeting $m) => $this->meetingPresenter->row($m, $user))
                ->values(),
            'canCreate' => $user->can('create', Meeting::class),
            'canOverride' => $user->hasPermission(Permissions::MEETING_OVERRIDE_CONFLICT),
        ];
    }
}
