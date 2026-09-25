<?php

namespace App\Services\Reports;

use App\Enums\LeadPriority;
use App\Support\CrmTime;
use Carbon\CarbonImmutable;

/**
 * Validated, scope-safe report filters. Period boundaries are CRM-local
 * calendar days converted to UTC instants (`from` inclusive, `to` inclusive
 * end-of-day). Ids that are outside the viewer's report scope are dropped so
 * a hand-edited URL can neither widen the scope nor reveal names.
 */
final class ReportFilters
{
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'last_quarter' => 'Last quarter',
        'this_year' => 'This year',
        'custom' => 'Custom',
    ];

    public const DEFAULT_PRESET = 'last_30_days';

    /** Custom ranges longer than this are rejected (keeps every query bounded). */
    public const MAX_RANGE_DAYS = 731;

    public function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $userId = null,
        public readonly ?int $sourceId = null,
        public readonly ?int $campaignId = null,
        public readonly ?int $statusId = null,
        public readonly ?string $priority = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly bool $includeArchived = true,
        public readonly bool $compare = true,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input, ReportScope $scope): self
    {
        $preset = array_key_exists((string) ($input['preset'] ?? ''), self::PRESETS) ? (string) $input['preset'] : self::DEFAULT_PRESET;
        [$preset, $fromDay, $toDay] = self::resolveDays($preset, $input['from'] ?? null, $input['to'] ?? null);

        $int = fn (string $key) => ctype_digit((string) ($input[$key] ?? '')) ? (int) $input[$key] : null;
        $text = fn (string $key) => ($v = trim((string) ($input[$key] ?? ''))) === '' ? null : mb_substr($v, 0, 100);

        $userId = $int('user');

        return new self(
            preset: $preset,
            from: CrmTime::startOfDate($fromDay),
            to: CrmTime::endOfDate($toDay),
            userId: $userId !== null && $scope->allowsUser($userId) ? $userId : null,
            sourceId: $int('source'),
            campaignId: $int('campaign'),
            statusId: $int('status'),
            priority: LeadPriority::tryFrom((string) ($input['priority'] ?? ''))?->value,
            city: $text('city'),
            state: $text('state'),
            includeArchived: ($input['archived'] ?? 'include') !== 'exclude',
            compare: ($input['compare'] ?? '1') !== '0',
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string} [preset, fromDay, toDay] as CRM-local Y-m-d
     */
    public static function resolveDays(string $preset, mixed $from = null, mixed $to = null): array
    {
        $today = CarbonImmutable::now(CrmTime::tz())->startOfDay();
        $d = fn (CarbonImmutable $c) => $c->format('Y-m-d');

        return match ($preset) {
            'today' => [$preset, $d($today), $d($today)],
            'yesterday' => [$preset, $d($today->subDay()), $d($today->subDay())],
            'last_7_days' => [$preset, $d($today->subDays(6)), $d($today)],
            'this_month' => [$preset, $d($today->startOfMonth()), $d($today)],
            'last_month' => [$preset, $d($today->subMonthNoOverflow()->startOfMonth()), $d($today->subMonthNoOverflow()->endOfMonth())],
            'this_quarter' => [$preset, $d($today->startOfQuarter()), $d($today)],
            'last_quarter' => [$preset, $d($today->subQuarterNoOverflow()->startOfQuarter()), $d($today->subQuarterNoOverflow()->endOfQuarter())],
            'this_year' => [$preset, $d($today->startOfYear()), $d($today)],
            'custom' => self::custom($from, $to, $today),
            default => ['last_30_days', $d($today->subDays(29)), $d($today)],
        };
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function custom(mixed $from, mixed $to, CarbonImmutable $today): array
    {
        $parse = function (mixed $value): ?CarbonImmutable {
            $value = (string) $value;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, CrmTime::tz());

            return $date && $date->format('Y-m-d') === $value ? $date : null;
        };

        $start = $parse($from);
        $end = $parse($to);

        if (! $start || ! $end || $end->lt($start) || $start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            return self::resolveDays(self::DEFAULT_PRESET);
        }

        return ['custom', $start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /**
     * The comparison window: the previous calendar month / quarter / year for
     * those presets, otherwise the equally long window immediately before.
     */
    public function previous(): self
    {
        $from = CrmTime::local($this->from)->startOfDay();
        $to = CrmTime::local($this->to)->startOfDay();
        $span = $this->days() - 1;

        [$pFrom, $pTo] = match ($this->preset) {
            'this_month' => [$m = $from->subMonthNoOverflow()->startOfMonth(), $m->addDays($span)->min($m->endOfMonth())],
            'last_month' => [$from->subMonthNoOverflow()->startOfMonth(), $from->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$q = $from->subQuarterNoOverflow()->startOfQuarter(), $q->addDays($span)->min($q->endOfQuarter())],
            'last_quarter' => [$from->subQuarterNoOverflow()->startOfQuarter(), $from->subQuarterNoOverflow()->endOfQuarter()],
            'this_year' => [$from->subYearNoOverflow()->startOfYear(), $to->subYearNoOverflow()],
            default => [$from->subDays($span + 1), $from->subDay()],
        };

        return $this->withPeriod(
            CrmTime::startOfDate($pFrom->format('Y-m-d')),
            CrmTime::endOfDate($pTo->format('Y-m-d')),
        );
    }

    public function withPeriod(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($this->preset, $from, $to, $this->userId, $this->sourceId, $this->campaignId,
            $this->statusId, $this->priority, $this->city, $this->state, $this->includeArchived, $this->compare);
    }

    public function withArchived(bool $include): self
    {
        return new self($this->preset, $this->from, $this->to, $this->userId, $this->sourceId, $this->campaignId,
            $this->statusId, $this->priority, $this->city, $this->state, $include, $this->compare);
    }

    /** Local calendar day strings for display and drill-down links. */
    public function fromDay(): string
    {
        return CrmTime::local($this->from)->format('Y-m-d');
    }

    public function toDay(): string
    {
        return CrmTime::local($this->to)->format('Y-m-d');
    }

    public function days(): int
    {
        return (int) round(CrmTime::local($this->from)->startOfDay()->diffInDays(CrmTime::local($this->to)->startOfDay())) + 1;
    }

    /** Trend granularity so a chart never has more than ~60 points. */
    public function granularity(): string
    {
        return match (true) {
            $this->days() <= 62 => 'day',
            $this->days() <= 200 => 'week',
            default => 'month',
        };
    }

    public function hasLeadAttributeFilters(): bool
    {
        return $this->sourceId || $this->campaignId || $this->statusId || $this->priority || $this->city || $this->state;
    }

    /** Query-string form (used for links, exports and the filter bar). */
    public function toQuery(): array
    {
        return array_filter([
            'preset' => $this->preset,
            'from' => $this->preset === 'custom' ? $this->fromDay() : null,
            'to' => $this->preset === 'custom' ? $this->toDay() : null,
            'user' => $this->userId,
            'source' => $this->sourceId,
            'campaign' => $this->campaignId,
            'status' => $this->statusId,
            'priority' => $this->priority,
            'city' => $this->city,
            'state' => $this->state,
            'archived' => $this->includeArchived ? null : 'exclude',
            'compare' => $this->compare ? null : '0',
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Everything that describes the filter state, for the UI. */
    public function toArray(): array
    {
        return [
            ...$this->toQuery(),
            'preset' => $this->preset,
            'from' => $this->fromDay(),
            'to' => $this->toDay(),
            'archived' => $this->includeArchived ? 'include' : 'exclude',
            'compare' => $this->compare,
            'granularity' => $this->granularity(),
            'timezone' => CrmTime::tz(),
        ];
    }
}
