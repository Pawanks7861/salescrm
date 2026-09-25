<?php

namespace App\Services\Reports\Definitions;

use App\Models\Call;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Meeting;
use App\Services\Reports\ReportLinks;
use App\Services\Reports\ReportLookups;
use App\Services\Reports\ReportQueries;
use App\Services\Reports\ReportScope;
use Carbon\CarbonImmutable;

/**
 * One report page. `sections()` returns a declarative payload the generic
 * Vue renderer draws: KPI tiles, a chart and tables, with every number and
 * rate already computed here. Tables are also the export source, so the CSV
 * always matches the screen.
 */
abstract class ReportDefinition
{
    public const SLUG = '';

    public const TITLE = '';

    public const CATEGORY = '';

    public const DESCRIPTION = '';

    /** Filters that apply to this report (the filter bar hides the rest). */
    public const FILTERS = ['date', 'user', 'source', 'campaign', 'status', 'priority', 'city', 'state', 'archived', 'compare'];

    public function __construct(protected readonly ReportLookups $lookups) {}

    /** @return array<int, array> */
    abstract public function sections(ReportQueries $q, ReportLinks $links): array;

    public function availableTo(ReportScope $scope): bool
    {
        return true;
    }

    /**
     * Full, unpaginated rows for an export of one table. Defaults to the table
     * as rendered; detail lists override this to stream every matching row.
     *
     * @return array{columns: array, rows: iterable, count: int}|null
     */
    public function exportTable(ReportQueries $q, ReportLinks $links, string $section): ?array
    {
        foreach ($this->sections($q, $links) as $s) {
            if (($s['type'] ?? null) === 'table' && $s['key'] === $section && ($s['exportable'] ?? true)) {
                $rows = $s['rows'];
                if (! empty($s['totals'])) {
                    $rows[] = $s['totals'];
                }

                return ['columns' => $s['columns'], 'rows' => $rows, 'count' => count($rows)];
            }
        }

        return null;
    }

    /** Row count used to decide between a synchronous and a queued export. */
    public function exportCount(ReportQueries $q, ReportLinks $links, string $section): ?int
    {
        return $this->exportTable($q, $links, $section)['count'] ?? null;
    }

    // ---- section builders -------------------------------------------------

    protected function kpis(string $key, string $title, array $items, bool $compare = false, ?string $note = null): array
    {
        return ['type' => 'kpis', 'key' => $key, 'title' => $title, 'items' => array_values(array_filter($items)), 'compare' => $compare, 'note' => $note];
    }

    /**
     * @param  string  $chart  line | bar | stacked | donut
     * @param  array<int, array{label: string, data: array}>  $datasets
     */
    protected function chart(string $key, string $title, string $chart, array $labels, array $datasets, string $format = 'number', ?string $note = null): array
    {
        $empty = collect($datasets)->every(fn ($d) => collect($d['data'])->every(fn ($v) => ! $v));

        return ['type' => 'chart', 'key' => $key, 'title' => $title, 'chart' => $chart, 'labels' => array_values($labels),
            'datasets' => array_map(fn ($d) => [...$d, 'data' => array_values($d['data'])], $datasets), 'format' => $format,
            'empty' => $empty, 'note' => $note];
    }

    /**
     * @param  array<int, array{key: string, label: string, format?: string}>  $columns
     * @param  array<int, array>  $rows  cell values keyed by column; optional `_links` => [column => url]
     */
    protected function table(string $key, string $title, array $columns, array $rows, ?array $totals = null, string $empty = 'No data for the selected filters.', ?string $note = null, bool $exportable = true, ?string $more = null): array
    {
        return ['type' => 'table', 'key' => $key, 'title' => $title, 'columns' => $columns, 'rows' => array_values($rows),
            'totals' => $totals, 'empty' => $empty, 'note' => $note, 'exportable' => $exportable, 'more' => $more];
    }

    protected function note(string $key, string $title, string $text): array
    {
        return ['type' => 'note', 'key' => $key, 'title' => $title, 'text' => $text];
    }

    protected function col(string $key, string $label, string $format = 'number'): array
    {
        return ['key' => $key, 'label' => $label, 'format' => $format];
    }

    /** Sum numeric columns of rows into a totals row. */
    protected function totals(array $rows, array $sumKeys, string $labelKey = 'name', array $extra = []): ?array
    {
        if ($rows === []) {
            return null;
        }
        $totals = [$labelKey => 'Total'];
        foreach ($sumKeys as $k) {
            $totals[$k] = array_sum(array_map(fn ($r) => (float) ($r[$k] ?? 0), $rows));
            if (floor($totals[$k]) == $totals[$k] && ! str_contains($k, 'value')) {
                $totals[$k] = (int) $totals[$k];
            }
        }

        return [...$totals, ...$extra];
    }

    protected function canSee(ReportQueries $q, string $module): bool
    {
        $model = ['lead' => Lead::class, 'call' => Call::class, 'followup' => Followup::class, 'meeting' => Meeting::class][$module];

        return $q->scope->user->can('viewAny', $model);
    }

    /** Previous-period queries when comparison is on. */
    protected function prev(ReportQueries $q): ?ReportQueries
    {
        return $q->filters->compare ? $q->previous() : null;
    }

    /** Human "label" for trend bucket keys. */
    protected function bucketLabels(array $keys, string $granularity): array
    {
        return array_map(function ($k) use ($granularity) {
            $d = CarbonImmutable::createFromFormat('!Y-m-d', $k);

            return match ($granularity) {
                'month' => $d->format('M Y'),
                'week' => 'Wk of '.$d->format('M j'),
                default => $d->format('M j'),
            };
        }, $keys);
    }

    public static function meta(): array
    {
        return ['slug' => static::SLUG, 'title' => static::TITLE, 'category' => static::CATEGORY, 'description' => static::DESCRIPTION, 'filters' => static::FILTERS];
    }
}
