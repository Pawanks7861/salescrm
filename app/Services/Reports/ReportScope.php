<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Followups\FollowupVisibility;
use App\Services\Leads\LeadVisibility;
use App\Services\Meetings\MeetingVisibility;
use App\Services\Telephony\CallVisibility;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single gate every report query goes through.
 *
 * A report row is visible only when BOTH hold:
 *  1. the operational module visibility (LeadVisibility, CallVisibility,
 *     FollowupVisibility, MeetingVisibility) allows the underlying record, and
 *  2. the report tier caps it: report.view_all → no cap (company),
 *     report.view → the viewer's own records only.
 *
 * Applying (1) re-uses the module classes verbatim, so reports can never show
 * more than the operational screens; (2) keeps a user with lead.view_all but
 * only report.view to their own figures. There is no team tier.
 */
final class ReportScope
{
    public const ALL = 'all';

    public const OWN = 'own';

    private function __construct(
        public readonly User $user,
        public readonly string $tier,
        private readonly LeadVisibility $leadVisibility,
        private readonly CallVisibility $callVisibility,
        private readonly FollowupVisibility $followupVisibility,
        private readonly MeetingVisibility $meetingVisibility,
    ) {}

    public static function tierFor(User $user): ?string
    {
        return match (true) {
            $user->hasPermission(Permissions::REPORT_VIEW_ALL) => self::ALL,
            $user->hasPermission(Permissions::REPORT_VIEW) => self::OWN,
            default => null,
        };
    }

    /** @throws AuthorizationException when the user has no report permission at all */
    public static function for(User $user): self
    {
        $tier = self::tierFor($user) ?? throw new AuthorizationException('You do not have permission to view reports.');

        return new self($user, $tier, app(LeadVisibility::class), app(CallVisibility::class),
            app(FollowupVisibility::class), app(MeetingVisibility::class));
    }

    public function label(): string
    {
        return $this->tier === self::ALL ? 'Company' : 'My';
    }

    public function canExport(): bool
    {
        return $this->user->hasPermission(Permissions::REPORT_EXPORT);
    }

    /** Cache keys must include this so restricted users never share results. */
    public function context(): string
    {
        return $this->tier.':'.$this->user->id;
    }

    public function leads(Builder $query, bool $withArchived = true): Builder
    {
        if ($withArchived) {
            $query->withTrashed();
        }

        $this->leadVisibility->apply($query, $this->user);

        return $this->cap($query, 'leads.assigned_to');
    }

    public function calls(Builder $query, bool $withArchivedLeads = true): Builder
    {
        $this->callVisibility->apply($query, $this->user, $withArchivedLeads);

        return $this->cap($query, 'calls.agent_user_id');
    }

    public function followups(Builder $query, bool $withArchivedLeads = true): Builder
    {
        $this->followupVisibility->apply($query, $this->user, $withArchivedLeads);

        return $this->cap($query, 'followups.assigned_to');
    }

    public function meetings(Builder $query, bool $withArchivedLeads = true): Builder
    {
        $this->meetingVisibility->apply($query, $this->user, $withArchivedLeads);

        return $this->cap($query, 'meetings.host_user_id');
    }

    /** People the viewer may report on (rows, filter options). Includes inactive users. */
    public function users(): Builder
    {
        $query = User::query()->orderBy('name');

        return $this->tier === self::ALL ? $query : $query->whereKey($this->user->id);
    }

    public function allowsUser(int $id): bool
    {
        return $this->tier === self::ALL
            ? User::withTrashed()->whereKey($id)->exists()
            : $id === $this->user->id;
    }

    /**
     * Salesperson filter options. Own-scope users get none: their reports are
     * scoped to themselves automatically and must not list colleagues.
     *
     * @return Collection<int, array{value: int, label: string}>
     */
    public function userOptions(): Collection
    {
        if ($this->tier !== self::ALL) {
            return collect();
        }

        return $this->users()->get(['id', 'name', 'is_active'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name.($u->is_active ? '' : ' (inactive)')]);
    }

    private function cap(Builder $query, string $ownerColumn): Builder
    {
        return $this->tier === self::ALL ? $query : $query->where($ownerColumn, $this->user->id);
    }
}
