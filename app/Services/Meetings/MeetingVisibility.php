<?php

namespace App\Services\Meetings;

use App\Enums\MeetingParticipantType;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\Leads\LeadVisibility;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for "which meetings may this user see / manage".
 *
 * A meeting is visible only when BOTH hold:
 *   1. its own tier allows it
 *        meeting.view_all → any meeting
 *        meeting.view     → hosted by them, they are an internal participant,
 *                           or linked to a lead they own
 *   2. when linked to a lead, that lead is visible through LeadVisibility (and
 *      not archived). Lead visibility is authoritative: being added to a
 *      meeting never grants access to someone else's lead.
 *
 * Meetings without a lead are governed by rule 1 alone. There is no team tier.
 * `apply()` (SQL) and `canView()` (in-memory) must stay in sync.
 */
class MeetingVisibility
{
    public const ALL = 'all';

    public const OWN = 'own';

    public const NONE = 'none';

    public function __construct(private readonly LeadVisibility $leads) {}

    public function tier(User $user): string
    {
        return match (true) {
            $user->hasPermission(Permissions::MEETING_VIEW_ALL) => self::ALL,
            $user->hasPermission(Permissions::MEETING_VIEW) => self::OWN,
            default => self::NONE,
        };
    }

    /** `$includeArchivedLeads` is for historical reports only; operational screens never pass it. */
    public function apply(Builder $query, User $user, bool $includeArchivedLeads = false): Builder
    {
        $table = $query->getModel()->getTable();

        match ($this->tier($user)) {
            self::ALL => null,
            self::OWN => $query->where(fn (Builder $q) => $q
                ->where("{$table}.host_user_id", $user->id)
                ->orWhereHas('participants', fn (Builder $p) => $p
                    ->where('participant_type', MeetingParticipantType::User->value)
                    ->where('user_id', $user->id))
                ->orWhereHas('lead', fn (Builder $lead) => $lead->withTrashed()->where('leads.assigned_to', $user->id))),
            default => $query->whereRaw('1 = 0'),
        };

        return $query->where(fn (Builder $q) => $q
            ->whereNull("{$table}.lead_id")
            ->orWhereHas('lead', fn (Builder $lead) => $this->leads->apply($includeArchivedLeads ? $lead->withTrashed() : $lead, $user)));
    }

    public function canView(User $user, Meeting $meeting): bool
    {
        $allowed = match ($this->tier($user)) {
            self::ALL => true,
            self::OWN => (int) $meeting->host_user_id === $user->id
                || $this->participates($user, $meeting)
                || ($meeting->lead_id !== null && $this->leads->owns($user, $meeting->lead)),
            default => false,
        };

        return $allowed && $this->leadVisible($user, $meeting);
    }

    /**
     * Whether the user may change the meeting (edit, reschedule, cancel,
     * complete…). Hosts manage their own meetings; view_all users manage any.
     * Participants who are not the host can view and RSVP only.
     */
    public function canManage(User $user, Meeting $meeting): bool
    {
        $allowed = match ($this->tier($user)) {
            self::ALL => true,
            self::OWN => (int) $meeting->host_user_id === $user->id,
            default => false,
        };

        return $allowed && $this->leadVisible($user, $meeting);
    }

    /** Whether the user could see meetings on this lead at all (host / participant eligibility). */
    public function canSeeLead(User $user, Lead $lead): bool
    {
        return $this->tier($user) !== self::NONE && ! $lead->trashed() && $this->leads->canView($user, $lead);
    }

    /**
     * Users this user may make the host. `null` means any active user.
     * Without meeting.assign a user may only host their own meetings. A host
     * of a lead meeting must still be able to see the lead (checked by
     * MeetingParticipantService), so this never widens lead access.
     *
     * @return array<int>|null
     */
    public function hostableUserIds(User $user): ?array
    {
        return $user->hasPermission(Permissions::MEETING_ASSIGN) && $this->tier($user) !== self::NONE ? null : [$user->id];
    }

    /**
     * Internal users this user may invite. `null` means any active user.
     * Invitees on a lead meeting must additionally be able to see the lead
     * (checked by MeetingParticipantService), so this never widens lead access.
     *
     * @return array<int>|null
     */
    public function invitableUserIds(User $user): ?array
    {
        return $this->tier($user) === self::NONE ? [] : null;
    }

    private function participates(User $user, Meeting $meeting): bool
    {
        if ($meeting->relationLoaded('participants')) {
            return $meeting->participants->contains(fn (MeetingParticipant $p) => $p->participant_type === MeetingParticipantType::User && (int) $p->user_id === $user->id);
        }

        return $meeting->participants()
            ->where('participant_type', MeetingParticipantType::User->value)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function leadVisible(User $user, Meeting $meeting): bool
    {
        if ($meeting->lead_id === null) {
            return true;
        }

        $lead = $meeting->lead;

        return $lead !== null && ! $lead->trashed() && $this->leads->canView($user, $lead);
    }
}
