<?php

namespace App\Services\Meetings;

use App\Enums\AttendanceStatus;
use App\Enums\AuditAction;
use App\Enums\MeetingParticipantType;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Centralised host and participant validation. Browser-supplied user ids are
 * never trusted: every host / internal participant must be within the actor's
 * allowed set, active, and able to see the meeting's lead.
 *
 * Lead and external participants store a snapshot of name / email / phone so
 * historical invitation records remain accurate if the lead is later edited.
 * External contacts never become CRM users or leads.
 */
class MeetingParticipantService
{
    public const AUTOCOMPLETE_LIMIT = 20;

    public function __construct(
        private readonly MeetingVisibility $visibility,
        private readonly AuditService $audit,
    ) {}

    /** @throws ValidationException */
    public function resolveHost(?Lead $lead, ?int $hostId, User $actor, string $field = 'host_user_id'): User
    {
        if (! $hostId || $hostId === $actor->id) {
            return $actor;
        }

        $allowed = $this->visibility->hostableUserIds($actor);
        if ($allowed !== null && ! in_array($hostId, $allowed, true)) {
            throw ValidationException::withMessages([$field => 'You cannot schedule meetings for this user.']);
        }

        $host = User::query()->active()->find($hostId);
        if (! $host) {
            throw ValidationException::withMessages([$field => 'The selected host is not available.']);
        }

        if ($lead && ! $this->visibility->canSeeLead($host, $lead)) {
            throw ValidationException::withMessages([$field => "{$host->name} cannot access this lead."]);
        }

        return $host;
    }

    /**
     * Default host for a new meeting on `$lead`: the lead owner when the actor
     * may schedule for them, else the actor.
     */
    public function defaultHost(?Lead $lead, User $actor): User
    {
        $owner = $lead?->assigned_to ? User::query()->active()->find($lead->assigned_to) : null;

        if ($owner && $owner->id !== $actor->id) {
            $allowed = $this->visibility->hostableUserIds($actor);
            if (($allowed === null || in_array($owner->id, $allowed, true)) && $this->visibility->canSeeLead($owner, $lead)) {
                return $owner;
            }
        }

        return $actor;
    }

    /**
     * Validates internal participant ids.
     *
     * @param  array<int>  $userIds
     * @return Collection<int, User>
     *
     * @throws ValidationException
     */
    public function resolveUsers(array $userIds, ?Lead $lead, User $actor, string $field = 'participant_user_ids'): Collection
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return collect();
        }

        $allowed = $this->visibility->invitableUserIds($actor);
        $users = User::query()->active()->whereIn('id', $userIds)->get()->keyBy('id');

        foreach ($userIds as $i => $id) {
            // Array fields ("participant_user_ids") get per-item keys; single fields ("user_id") do not.
            $key = str_ends_with($field, '_ids') ? "{$field}.{$i}" : $field;
            $user = $users->get($id);
            if (! $user || ($allowed !== null && ! in_array($id, $allowed, true))) {
                throw ValidationException::withMessages([$key => 'One of the selected users is not available.']);
            }
            if ($lead && ! $this->visibility->canSeeLead($user, $lead)) {
                throw ValidationException::withMessages([$key => "{$user->name} cannot access this lead."]);
            }
        }

        return $users->values();
    }

    /**
     * Autocomplete: active users the actor may invite (and who can see the
     * lead, when given). Never disabled users.
     *
     * @return Collection<int, array{id: int, name: string, email: string}>
     */
    public function searchInvitable(User $actor, ?string $term, ?Lead $lead): Collection
    {
        $allowed = $this->visibility->invitableUserIds($actor);

        $users = User::query()->active()
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('id', $allowed))
            ->when(filled($term), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.addcslashes((string) $term, '%_\\').'%')
                ->orWhere('email', 'like', '%'.addcslashes((string) $term, '%_\\').'%')))
            ->orderBy('name')
            ->limit(self::AUTOCOMPLETE_LIMIT * 2)
            ->get(['id', 'name', 'email', 'role_id']);

        if ($lead) {
            $users = $users->filter(fn (User $u) => $this->visibility->canSeeLead($u, $lead));
        }

        return $users->take(self::AUTOCOMPLETE_LIMIT)
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();
    }

    /**
     * Creates participant rows. `$externals` items: name, email, phone.
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, array<string, mixed>>  $externals
     */
    public function attach(Meeting $meeting, Collection $users, bool $includeLead, array $externals): void
    {
        foreach ($users as $user) {
            if ((int) $user->id === (int) $meeting->host_user_id) {
                continue;
            }
            $meeting->participants()->firstOrCreate(
                ['user_id' => $user->id],
                ['participant_type' => MeetingParticipantType::User, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone ?? null, 'attendance_status' => AttendanceStatus::Pending, 'invitation_status' => MeetingParticipant::INVITE_NOTIFIED],
            );
        }

        if ($includeLead && $meeting->lead) {
            $lead = $meeting->lead;
            $meeting->participants()->firstOrCreate(
                ['lead_id' => $lead->id],
                ['participant_type' => MeetingParticipantType::Lead, 'name' => $lead->full_name, 'email' => $lead->email, 'phone' => $lead->phone, 'attendance_status' => AttendanceStatus::Pending, 'invitation_status' => MeetingParticipant::INVITE_NOT_SENT],
            );
        }

        foreach ($externals as $external) {
            $name = trim((string) ($external['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $meeting->participants()->create([
                'participant_type' => MeetingParticipantType::External,
                'name' => $name,
                'email' => ($external['email'] ?? null) ?: null,
                'phone' => ($external['phone'] ?? null) ?: null,
                'attendance_status' => AttendanceStatus::Pending,
                'invitation_status' => MeetingParticipant::INVITE_NOT_SENT,
            ]);
        }

        $meeting->unsetRelation('participants');
    }

    /** Copies participants to a rescheduled meeting (attendance reset to pending, declines kept). */
    public function copy(Meeting $from, Meeting $to): void
    {
        foreach ($from->participants()->get() as $p) {
            $to->participants()->create([
                'participant_type' => $p->participant_type,
                'user_id' => $p->user_id,
                'lead_id' => $p->lead_id,
                'name' => $p->name,
                'email' => $p->email,
                'phone' => $p->phone,
                'attendance_status' => $p->attendance_status === AttendanceStatus::Declined ? AttendanceStatus::Declined : AttendanceStatus::Pending,
                'invitation_status' => $p->invitation_status,
            ]);
        }

        $to->unsetRelation('participants');
    }

    public function auditAdded(Meeting $meeting, MeetingParticipant $participant): void
    {
        $this->audit->log(AuditAction::MeetingParticipantAdded, 'meetings', $meeting,
            "{$participant->participant_type->label()} participant added to {$meeting->meeting_number}", null,
            array_filter(['participant_id' => $participant->id, 'type' => $participant->participant_type->value, 'user_id' => $participant->user_id, 'lead_id' => $participant->lead_id]));
    }

    public function auditRemoved(Meeting $meeting, MeetingParticipant $participant): void
    {
        $this->audit->log(AuditAction::MeetingParticipantRemoved, 'meetings', $meeting,
            "{$participant->participant_type->label()} participant removed from {$meeting->meeting_number}",
            array_filter(['participant_id' => $participant->id, 'type' => $participant->participant_type->value, 'user_id' => $participant->user_id, 'lead_id' => $participant->lead_id]));
    }
}
