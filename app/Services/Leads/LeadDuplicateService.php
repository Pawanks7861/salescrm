<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds existing leads matching a phone/email/external id.
 * Match priority: normalised phone (primary or alternate) → email → external ids.
 *
 * `findVisibleMatches()` is the only method whose results may be shown to a
 * user; it is always constrained by LeadVisibility so hidden leads never leak
 * (not even as a count). `findAnyMatch()` is internal and used only to flag a
 * newly created lead.
 */
class LeadDuplicateService
{
    public const MODE_MERGE = 'merge';

    public const MODE_FLAG = 'flag';

    public const MODE_ALLOW = 'allow';

    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly SettingService $settings,
    ) {}

    public function mode(): string
    {
        $mode = (string) $this->settings->get('lead.duplicate_handling', self::MODE_FLAG);

        return in_array($mode, [self::MODE_MERGE, self::MODE_FLAG, self::MODE_ALLOW], true) ? $mode : self::MODE_FLAG;
    }

    /**
     * @param  array{phone?: ?string, alternate_phone?: ?string, email?: ?string, facebook_lead_id?: ?string}  $criteria
     * @return Collection<int, array{lead: Lead, matched_on: string}>
     */
    public function findVisibleMatches(array $criteria, User $user, ?int $excludeId = null, int $limit = 5): Collection
    {
        return $this->match($criteria, $excludeId, $limit, fn (Builder $q) => $q->visibleTo($user));
    }

    public function findAnyMatch(array $criteria, ?int $excludeId = null): ?Lead
    {
        return $this->match($criteria, $excludeId, 1)->first()['lead'] ?? null;
    }

    /** @return Collection<int, array{lead: Lead, matched_on: string}> */
    private function match(array $criteria, ?int $excludeId, int $limit, ?callable $scope = null): Collection
    {
        $results = collect();
        $seen = [];

        foreach ($this->strategies($criteria) as $matchedOn => $constraint) {
            if ($results->count() >= $limit) {
                break;
            }

            $query = Lead::query()
                ->where($constraint)
                ->when($excludeId, fn (Builder $q) => $q->whereKeyNot($excludeId))
                ->when($seen !== [], fn (Builder $q) => $q->whereKeyNot($seen))
                ->with(['status:id,name,color', 'assignee:id,name'])
                ->orderBy('id')
                ->limit($limit - $results->count());

            if ($scope) {
                $scope($query);
            }

            foreach ($query->get() as $lead) {
                $seen[] = $lead->id;
                $results->push(['lead' => $lead, 'matched_on' => $matchedOn]);
            }
        }

        return $results;
    }

    /** @return array<string, callable> */
    private function strategies(array $criteria): array
    {
        $strategies = [];

        $phones = array_values(array_unique(array_filter([
            $this->phones->normalize($criteria['phone'] ?? null),
            $this->phones->normalize($criteria['alternate_phone'] ?? null),
        ])));

        if ($phones !== []) {
            $strategies['phone'] = fn (Builder $q) => $q->whereIn('normalized_phone', $phones)
                ->orWhereIn('normalized_alternate_phone', $phones);
        }

        $email = strtolower(trim((string) ($criteria['email'] ?? '')));
        if ($email !== '') {
            $strategies['email'] = fn (Builder $q) => $q->where('email', $email);
        }

        $externalId = trim((string) ($criteria['facebook_lead_id'] ?? ''));
        if ($externalId !== '') {
            $strategies['external_id'] = fn (Builder $q) => $q->where('facebook_lead_id', $externalId);
        }

        return $strategies;
    }
}
