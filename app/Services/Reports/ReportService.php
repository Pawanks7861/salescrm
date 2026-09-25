<?php

namespace App\Services\Reports;

use App\Enums\AuditAction;
use App\Enums\LeadPriority;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Support\CrmTime;
use App\Support\LeadValue;
use Illuminate\Support\Facades\Cache;

/**
 * Builds report pages for the controller and records REPORT_VIEWED once per
 * visit (throttled so filter changes do not flood the audit log). Report
 * results are never written to logs or audit entries — only report name and
 * filter values.
 */
class ReportService
{
    private const VIEW_AUDIT_TTL_MINUTES = 10;

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportLookups $lookups,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    public function queries(User $user, array $input): ReportQueries
    {
        $scope = ReportScope::for($user);

        return new ReportQueries($scope, ReportFilters::fromInput($input, $scope));
    }

    public function page(User $user, string $slug, array $input): array
    {
        $q = $this->queries($user, $input);
        $report = $this->registry->resolve($slug, $q->scope);
        $links = new ReportLinks($q);

        $this->recordView($user, $slug, $q->filters);

        return [
            'report' => $report::meta(),
            'sections' => LeadValue::sections($report->sections($q, $links)),
            'filters' => $q->filters->toArray(),
            'comparison' => $q->filters->compare ? [
                'from' => $q->filters->previous()->fromDay(),
                'to' => $q->filters->previous()->toDay(),
            ] : null,
            'options' => $this->options($q),
            'context' => $this->context($q),
            'reports' => $this->registry->available($q->scope),
        ];
    }

    public function index(User $user): array
    {
        $scope = ReportScope::for($user);

        return [
            'reports' => $this->registry->available($scope),
            'categories' => ReportRegistry::CATEGORIES,
            'context' => ['scope' => $scope->label(), 'tier' => $scope->tier, 'can_export' => $scope->canExport()],
        ];
    }

    /**
     * Compact "this month" KPIs for the dashboard: personal for sales users,
     * company for view_all — via the same ReportScope.
     * Dashboard visits are not audited as report views.
     */
    public function dashboard(User $user): ?array
    {
        if (ReportScope::tierFor($user) === null) {
            return null;
        }

        $q = $this->queries($user, ['preset' => 'this_month']);
        $p = $q->previous();
        $leads = app(Metrics\LeadMetrics::class);
        $links = new ReportLinks($q);

        $cohort = $leads->cohortSummary($q);
        $pCohort = $leads->cohortSummary($p);
        $won = $leads->periodOutcome($q, 'won');
        $pWon = $leads->periodOutcome($p, 'won');
        $lost = $leads->periodOutcome($q, 'lost');
        $pLost = $leads->periodOutcome($p, 'lost');
        $pipeline = app(Metrics\PipelineMetrics::class)->totals($q);
        $response = app(Metrics\ResponseMetrics::class)->summary($q);

        $kpis = [
            Metric::kpi('new_leads', 'New leads', $cohort['leads'], 'number', 'Leads created this month.', $pCohort['leads'], link: $links->leads([], true)),
            Metric::kpi('won', 'Leads won', $won['count'], 'number', 'Leads moved to won this month.', $pWon['count']),
            Metric::kpi('won_value', 'Won value', $won['value'], 'currency', 'Estimated value of leads won this month.', $pWon['value']),
            Metric::kpi('win_rate', 'Win rate', $leads->periodWinRate($won, $lost), 'percent', 'Won ÷ (won + lost) this month.', $leads->periodWinRate($pWon, $pLost)),
            Metric::kpi('pipeline', 'Open pipeline', Metric::money($pipeline->value ?? 0), 'currency', 'Estimated value of open leads now.', snapshot: true, link: $links->report('pipeline')),
            Metric::kpi('response', 'Median first response', $response['median_attempt'], 'duration', 'Leads created this month: creation → first response attempt.', higherIsBetter: false, link: $links->report('response-time')),
        ];

        if ($user->can('viewAny', Call::class)) {
            $calls = app(Metrics\CallMetrics::class);
            $c = $calls->summary($q);
            $pc = $calls->summary($p);
            $kpis[] = Metric::kpi('calls', 'Calls', $c['total'], 'number', 'Calls this month.', $pc['total'], link: $links->report('calls'));
            $kpis[] = Metric::kpi('connection_rate', 'Connection rate', $c['connection_rate'], 'percent', 'Connected ÷ finished outbound calls.', $pc['connection_rate']);
        }

        return [
            'scope' => $q->scope->label(),
            'period' => 'This month vs same days last month',
            'kpis' => LeadValue::kpis($kpis),
            'currency' => (string) $this->settings->get('general.currency', 'INR'),
            'link' => route('reports.show', 'overview'),
        ];
    }

    public function context(ReportQueries $q): array
    {
        return [
            'scope' => $q->scope->label(),
            'tier' => $q->scope->tier,
            'can_export' => $q->scope->canExport(),
            'currency' => (string) $this->settings->get('general.currency', 'INR'),
            'timezone' => CrmTime::tz(),
            'generated_at' => CrmTime::format(now(), 'M j, Y g:i A'),
        ];
    }

    /**
     * Filter options, all derived from the viewer's scope: people come from
     * ReportScope (none for own-scope users); cities / states only from leads the viewer can see.
     */
    public function options(ReportQueries $q): array
    {
        $distinct = fn (string $column) => $q->scope->leads(Lead::query(), false)
            ->whereNotNull("leads.{$column}")->where("leads.{$column}", '!=', '')
            ->distinct()->orderBy("leads.{$column}")->limit(200)->pluck("leads.{$column}");

        return [
            'presets' => collect(ReportFilters::PRESETS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'users' => $q->scope->userOptions(),
            ...$this->lookups->referenceOptions(),
            'priorities' => LeadPriority::options(),
            'cities' => $distinct('city'),
            'states' => $distinct('state'),
        ];
    }

    private function recordView(User $user, string $slug, ReportFilters $filters): void
    {
        if (Cache::add("report-viewed:{$user->id}:{$slug}", true, now()->addMinutes(self::VIEW_AUDIT_TTL_MINUTES))) {
            $this->audit->log(AuditAction::ReportViewed, 'reports', null, "Viewed report {$slug}", null, [
                'report' => $slug,
                'filters' => $filters->toQuery(),
            ]);
        }
    }
}
