<?php

namespace App\Services\Telephony;

use App\Enums\AuditAction;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Call;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Services\Followups\LeadFollowupSyncService;
use App\Services\SettingService;
use App\Services\Telephony\Data\ProviderCallEvent;
use App\Support\CrmTime;

/**
 * Applies provider events to a call under a row lock (caller holds the
 * transaction). Status only moves forward (CallStatus::canTransitionTo), so
 * duplicated or out-of-order callbacks can never downgrade a call; missing
 * facts (timestamps, durations, recording) are filled in but never
 * overwritten. Side effects — activity, audit, last-contacted, missed-call
 * notification, recording lookup — run once, on the transition itself.
 */
class CallLifecycleService
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
        private readonly LeadFollowupSyncService $leadSync,
        private readonly SettingService $settings,
        private readonly CallRecordingService $recordings,
        private readonly CallNotificationService $notifications,
    ) {}

    /** @return bool whether the status changed */
    public function apply(Call $call, ProviderCallEvent $event): bool
    {
        $from = $call->status;
        $to = $event->status;
        $changed = $to !== null && $from->canTransitionTo($to);

        if ($changed) {
            $call->status = $to;
            $call->provider_status = $event->providerStatus ?? $call->provider_status;
        }

        $current = $call->status;
        $this->fillFacts($call, $event, $current);
        $call->last_event_at = now();

        if ($changed && $current->isTerminal()) {
            $call->requires_disposition = $this->requiresDisposition($call);
            if (! $current->isConnected()) {
                $call->failure_code ??= $event->failureCode ?? $current->value;
                $call->talk_duration_seconds ??= 0;
            }
        }

        $call->save();

        if ($changed) {
            $this->afterTransition($call, $from, $current);
        }

        if ($current->isConnected() && $current->isTerminal()) {
            $this->recordings->register($call, $event->recordingReference);
        }

        return $changed;
    }

    private function fillFacts(Call $call, ProviderCallEvent $event, CallStatus $current): void
    {
        $now = now();

        if ($event->status === CallStatus::Ringing) {
            $call->ringing_at ??= $event->occurredAt ?? $now;
        }

        if ($current->isConnected()) {
            $call->answered_at ??= $event->answeredAt ?? ($event->status === CallStatus::Answered ? ($event->occurredAt ?? $now) : null);
        }

        if ($current->isTerminal()) {
            $call->ended_at ??= $event->endedAt ?? $event->occurredAt ?? $now;
            if ($call->answered_at === null && $current === CallStatus::Completed && $event->talkSeconds) {
                $call->answered_at = $call->ended_at->copy()->subSeconds($event->talkSeconds);
            }
            if ($event->talkSeconds !== null && $call->talk_duration_seconds === null) {
                $call->talk_duration_seconds = $current->isConnected() ? $event->talkSeconds : 0;
            }
            if ($call->talk_duration_seconds === null && $current->isConnected() && $call->answered_at) {
                $call->talk_duration_seconds = max(0, (int) $call->answered_at->diffInSeconds($call->ended_at));
            }
            $call->total_duration_seconds ??= $event->totalSeconds ?? max(0, (int) $call->started_at->diffInSeconds($call->ended_at));
            $call->ring_duration_seconds ??= $event->ringSeconds
                ?? ($call->ringing_at ? max(0, (int) $call->ringing_at->diffInSeconds($call->answered_at ?? $call->ended_at)) : null);
        }
    }

    private function requiresDisposition(Call $call): bool
    {
        if ($call->status->isConnected()) {
            return (bool) $this->settings->get('telephony.require_disposition', true);
        }

        return $call->direction === CallDirection::Outbound
            && in_array($call->status, [CallStatus::Busy, CallStatus::NoAnswer, CallStatus::Failed], true)
            && (bool) $this->settings->get('telephony.require_disposition_unconnected', false);
    }

    private function afterTransition(Call $call, CallStatus $from, CallStatus $to): void
    {
        $lead = $call->lead_id ? $call->lead : null;
        $agentId = $call->agent_user_id;
        $safe = ['call_number' => $call->call_number, 'status' => $to->value];

        if ($to === CallStatus::Answered) {
            $this->audit->log(AuditAction::CallAnswered, 'calls', $call, "Call {$call->call_number} answered", ['status' => $from->value], $safe, $agentId);

            return;
        }

        if (! $to->isTerminal()) {
            return;
        }

        $inbound = $call->direction === CallDirection::Inbound;

        if ($to->isConnected()) {
            $duration = $call->durationLabel();
            if ($lead) {
                $this->leadSync->markContacted($lead, $call->answered_at ?? $call->ended_at ?? now());
                $this->activities->record($lead, ActivityService::CALL_COMPLETED,
                    ($inbound ? 'Incoming' : 'Outbound').' call completed'.($duration ? " — {$duration}" : '').'.',
                    ['call_id' => $call->id, 'talk_seconds' => $call->talk_duration_seconds], $agentId);
            }
            $this->audit->log(AuditAction::CallCompleted, 'calls', $call, "Call {$call->call_number} completed", ['status' => $from->value],
                $safe + ['talk_seconds' => $call->talk_duration_seconds], $agentId);

            return;
        }

        if ($to === CallStatus::Missed) {
            if ($lead) {
                $this->activities->record($lead, ActivityService::CALL_MISSED, 'Incoming call missed.', ['call_id' => $call->id], null);
            }
            $this->audit->log(AuditAction::CallMissed, 'calls', $call, "Incoming call {$call->call_number} missed at ".CrmTime::format($call->started_at), ['status' => $from->value], $safe);
            $this->notifications->missed($call);

            return;
        }

        if ($lead) {
            $this->activities->record($lead, ActivityService::CALL_UNANSWERED,
                ($inbound ? 'Incoming' : 'Outbound').' call not connected — '.strtolower($to->label()).'.',
                ['call_id' => $call->id, 'status' => $to->value], $agentId);
        }
        $this->audit->log(AuditAction::CallFailed, 'calls', $call, "Call {$call->call_number} ended: {$to->label()}", ['status' => $from->value],
            $safe + ['failure_code' => $call->failure_code], $agentId);
    }
}
