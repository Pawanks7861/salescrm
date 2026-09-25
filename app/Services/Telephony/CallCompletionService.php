<?php

namespace App\Services\Telephony;

use App\Enums\AuditAction;
use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\CallDisposition;
use App\Models\Followup;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Services\Followups\FollowupService;
use App\Services\Leads\LeadService;
use App\Services\Leads\LeadVisibility;
use App\Services\Meetings\MeetingService;
use App\Services\SettingService;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Post-call workflow: disposition + notes, then an optional next action and
 * lead status change, all through the existing services:
 *   follow-up   → Phase 3 FollowupService (next_followup_at, reminders)
 *   meeting     → Phase 4 MeetingService (permissions, conflicts, reminders)
 *   lead status → Phase 2 LeadService (Lost still needs a lost reason)
 *
 * Everything runs in one transaction, so a failing step (e.g. Lost without a
 * reason, a meeting conflict) leaves the call untouched. Only disposition,
 * notes and the linked next action are ever written on the call.
 */
class CallCompletionService
{
    public const NEXT_ACTIONS = ['none', 'followup', 'meeting'];

    public function __construct(
        private readonly CallVisibility $visibility,
        private readonly LeadVisibility $leadVisibility,
        private readonly FollowupService $followups,
        private readonly MeetingService $meetings,
        private readonly LeadService $leads,
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    /**
     * `$data`: disposition_id, notes, next_action (none|followup|meeting),
     * followup{...FollowupService fields, confirm_duplicate}, meeting{...MeetingService fields, override_conflict},
     * status_id, lost_reason_id, lost_reason_notes.
     *
     * @return array{call: Call, followup: ?Followup, meeting: ?Meeting}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function complete(Call $call, array $data, User $actor): array
    {
        if (! $actor->hasPermission(Permissions::CALL_ADD_DISPOSITION) || ! $this->visibility->canManage($actor, $call)) {
            throw new AuthorizationException('You are not allowed to record the outcome of this call.');
        }

        if (! $call->status->isTerminal() && $call->status !== CallStatus::Answered) {
            throw ValidationException::withMessages(['disposition_id' => 'The call is still in progress. Add the outcome once it has ended.']);
        }

        $disposition = CallDisposition::query()->active()->whereKey((int) ($data['disposition_id'] ?? 0))->first();
        if (! $disposition) {
            throw ValidationException::withMessages(['disposition_id' => 'Select the call outcome.']);
        }

        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        if (! $notes && $disposition->requires_note) {
            throw ValidationException::withMessages(['notes' => "Add a note for the outcome \"{$disposition->name}\"."]);
        }
        if ($notes !== $call->notes && ! $this->canEditNotes($actor, $call)) {
            throw ValidationException::withMessages(['notes' => 'You can no longer edit the notes of this call.']);
        }

        $lead = $call->lead_id ? $call->lead : null;
        if ($lead && ($lead->trashed() || ! $this->leadVisibility->canView($actor, $lead))) {
            $lead = null;
        }

        $next = in_array($data['next_action'] ?? 'none', self::NEXT_ACTIONS, true) ? ($data['next_action'] ?? 'none') : 'none';
        if ($next !== 'none' && ! $lead) {
            throw ValidationException::withMessages(['next_action' => 'A next action can only be scheduled for calls linked to a lead.']);
        }
        if ($next === 'none' && $disposition->requires_next_action && $lead && $call->next_action === null) {
            throw ValidationException::withMessages(['next_action' => "Schedule a follow-up or meeting for the outcome \"{$disposition->name}\"."]);
        }
        if ($next === 'followup' && ! $actor->can('create', [Followup::class, $lead])) {
            throw ValidationException::withMessages(['next_action' => 'You are not allowed to schedule a follow-up for this lead.']);
        }
        if ($next === 'meeting' && ! $actor->can('create', [Meeting::class, $lead])) {
            throw ValidationException::withMessages(['next_action' => 'You are not allowed to schedule a meeting for this lead.']);
        }

        $statusId = ! empty($data['status_id']) ? (int) $data['status_id'] : null;
        if ($statusId && (! $lead || ! $actor->hasPermission(Permissions::LEAD_CHANGE_STATUS) || ! $actor->can('changeStatus', $lead))) {
            throw ValidationException::withMessages(['status_id' => 'You are not allowed to change the lead status.']);
        }

        // Meeting conflicts are checked by MeetingService before its own transaction;
        // it nests safely inside this one.
        return DB::transaction(function () use ($call, $data, $actor, $disposition, $notes, $lead, $next, $statusId) {
            $call = Call::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();
            $previous = $call->disposition_id ? CallDisposition::query()->find($call->disposition_id) : null;
            $oldNotes = $call->notes;

            $followup = null;
            $meeting = null;

            if ($next === 'followup') {
                $input = (array) ($data['followup'] ?? []);
                try {
                    $followup = $this->followups->create($lead, $input, $actor, (bool) ($input['confirm_duplicate'] ?? false));
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["followup.{$k}" => $m])->all());
                }
            } elseif ($next === 'meeting') {
                $input = (array) ($data['meeting'] ?? []);
                try {
                    $meeting = $this->meetings->create($lead, $input, $actor, (bool) ($input['override_conflict'] ?? false));
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["meeting.{$k}" => $m])->all());
                }
            }

            if ($statusId) {
                $this->leads->changeStatus($lead, $statusId, $actor, isset($data['lost_reason_id']) ? (int) $data['lost_reason_id'] : null, $data['lost_reason_notes'] ?? null);
            }

            $call->forceFill(array_filter([
                'disposition_id' => $disposition->id,
                'disposition_at' => now(),
                'disposition_by' => $actor->id,
                'notes' => $notes,
                'notes_updated_at' => $notes !== $oldNotes ? now() : $call->notes_updated_at,
                'notes_updated_by' => $notes !== $oldNotes ? $actor->id : $call->notes_updated_by,
                'next_action' => $next !== 'none' ? $next : $call->next_action,
                'followup_id' => $followup?->id ?? $call->followup_id,
                'meeting_id' => $meeting?->id ?? $call->meeting_id,
                'updated_by' => $actor->id,
            ], fn ($v, $k) => $v !== null || in_array($k, ['notes', 'notes_updated_at', 'notes_updated_by'], true), ARRAY_FILTER_USE_BOTH))->save();

            if ($previous?->id !== $disposition->id) {
                if ($lead) {
                    $this->activities->record($lead, ActivityService::CALL_OUTCOME, "Call outcome: {$disposition->name}.", ['call_id' => $call->id, 'disposition' => $disposition->slug], $actor->id);
                }
                $this->audit->log(
                    $previous ? AuditAction::CallDispositionChanged : AuditAction::CallDispositionAdded,
                    'calls', $call,
                    "Call {$call->call_number} outcome ".($previous ? "changed to {$disposition->name}" : "recorded: {$disposition->name}"),
                    $previous ? ['disposition' => $previous->name] : null,
                    array_filter(['disposition' => $disposition->name, 'next_action' => $next !== 'none' ? $next : null]),
                    $actor->id,
                );
            }

            if ($notes !== $oldNotes) {
                $this->auditNotes($call, $oldNotes, $notes, $actor);
            }

            return ['call' => $call, 'followup' => $followup, 'meeting' => $meeting];
        });
    }

    /** @throws AuthorizationException|ValidationException */
    public function updateNotes(Call $call, ?string $notes, User $actor): Call
    {
        if (! $actor->hasPermission(Permissions::CALL_EDIT_NOTES) || ! $this->visibility->canManage($actor, $call)) {
            throw new AuthorizationException('You are not allowed to edit the notes of this call.');
        }
        if (! $this->canEditNotes($actor, $call)) {
            throw ValidationException::withMessages(['notes' => 'You can no longer edit the notes of this call.']);
        }

        $notes = trim((string) $notes) ?: null;
        if ($notes === $call->notes) {
            return $call;
        }

        return DB::transaction(function () use ($call, $notes, $actor) {
            $old = $call->notes;
            $call->forceFill(['notes' => $notes, 'notes_updated_at' => now(), 'notes_updated_by' => $actor->id, 'updated_by' => $actor->id])->save();
            $this->auditNotes($call, $old, $notes, $actor);

            return $call;
        });
    }

    public function canEditNotes(User $actor, Call $call): bool
    {
        $hours = (int) $this->settings->get('telephony.notes_edit_window_hours', 0);
        if ($hours <= 0 || $call->notes === null || $actor->hasPermission(Permissions::CALL_CONFIGURE)) {
            return true;
        }

        return ($call->ended_at ?? $call->started_at)->gt(now()->subHours($hours));
    }

    private function auditNotes(Call $call, ?string $old, ?string $new, User $actor): void
    {
        $this->audit->log(AuditAction::CallNotesUpdated, 'calls', $call, "Call {$call->call_number} notes updated", ['notes' => $old], ['notes' => $new], $actor->id);
    }
}
