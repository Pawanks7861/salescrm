<?php

namespace App\Services\Reports;

use App\Services\Reports\Definitions\ReportDefinition;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Whitelist of report pages. Unknown slugs are a 404, never a dynamic class lookup. */
final class ReportRegistry
{
    /** @var array<string, class-string<ReportDefinition>> */
    public const REPORTS = [
        Definitions\OverviewReport::SLUG => Definitions\OverviewReport::class,
        Definitions\PipelineReport::SLUG => Definitions\PipelineReport::class,
        Definitions\FunnelReport::SLUG => Definitions\FunnelReport::class,
        Definitions\LostLeadsReport::SLUG => Definitions\LostLeadsReport::class,
        Definitions\LeadsReport::SLUG => Definitions\LeadsReport::class,
        Definitions\AgeingReport::SLUG => Definitions\AgeingReport::class,
        Definitions\AssignmentsReport::SLUG => Definitions\AssignmentsReport::class,
        Definitions\SalesPerformanceReport::SLUG => Definitions\SalesPerformanceReport::class,
        Definitions\ActivityReport::SLUG => Definitions\ActivityReport::class,
        Definitions\ResponseTimeReport::SLUG => Definitions\ResponseTimeReport::class,
        Definitions\CallsReport::SLUG => Definitions\CallsReport::class,
        Definitions\FollowupsReport::SLUG => Definitions\FollowupsReport::class,
        Definitions\MeetingsReport::SLUG => Definitions\MeetingsReport::class,
        Definitions\CampaignsReport::SLUG => Definitions\CampaignsReport::class,
    ];

    public const CATEGORIES = ['Sales', 'Leads', 'People', 'Activity', 'Marketing'];

    public function resolve(string $slug, ReportScope $scope): ReportDefinition
    {
        $class = self::REPORTS[$slug] ?? throw new NotFoundHttpException;
        $report = app($class);

        if (! $report->availableTo($scope)) {
            throw new NotFoundHttpException;
        }

        return $report;
    }

    /** @return array<int, array> meta of reports available to the scope */
    public function available(ReportScope $scope): array
    {
        return collect(self::REPORTS)
            ->filter(fn ($class) => app($class)->availableTo($scope))
            ->map(fn ($class) => $class::meta())
            ->values()
            ->all();
    }
}
