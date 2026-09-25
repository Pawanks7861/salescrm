<?php

namespace App\Http\Presenters;

use App\Enums\CallDirection;
use App\Enums\CallRecordingStatus;
use App\Models\Call;
use App\Models\CallEvent;
use App\Models\User;
use App\Support\Permissions;

/**
 * Shapes call data for Inertia / JSON. Never includes provider recording
 * URLs, provider payloads or credentials — recordings are referenced only by
 * the authorised CRM streaming route.
 */
class CallPresenter
{
    /** Relations required by row(); lead columns include what visibility checks need. */
    public const ROW_WITH = [
        'lead:id,lead_number,full_name,assigned_to,deleted_at',
        'agent:id,name',
        'disposition:id,name,color',
        'recording:id,call_id,status',
    ];

    public function row(Call $call, User $viewer): array
    {
        $canListen = $viewer->can('listen', $call);

        return [
            'id' => $call->id,
            'call_number' => $call->call_number,
            'started_at' => $call->started_at?->toIso8601String(),
            'direction' => $call->direction->value,
            'direction_label' => $call->direction->label(),
            'channel' => $call->channel->value,
            'status' => $call->status->value,
            'status_label' => $call->status->label(),
            'status_color' => $call->status->color(),
            'is_open' => $call->status->isOpen(),
            'talk_seconds' => $call->talk_duration_seconds,
            'duration' => $call->durationLabel(),
            'lead' => $call->lead ? [
                'id' => $call->lead->id,
                'lead_number' => $call->lead->lead_number,
                'full_name' => $call->lead->full_name,
            ] : null,
            'customer_number' => $call->lead ? null : $this->displayNumber($call),
            'agent' => $call->agent?->only('id', 'name'),
            'disposition' => $call->disposition?->only('id', 'name', 'color'),
            'requires_disposition' => $call->requires_disposition && $call->disposition_id === null,
            'notes' => $call->notes,
            'recording' => $this->recording($call, $canListen),
            'stream_url' => $canListen && $call->recording?->status === CallRecordingStatus::Available ? route('calls.recording', $call->id, false) : null,
            'can' => [
                'dispose' => $viewer->can('dispose', $call),
                'listen' => $canListen && $call->recording?->status === CallRecordingStatus::Available,
            ],
        ];
    }

    /** Full record for the call page (viewer already authorised). */
    public function detail(Call $call, User $viewer): array
    {
        $canListen = $viewer->can('listen', $call);
        $canDownload = $viewer->can('download', $call);
        $available = $call->recording?->status === CallRecordingStatus::Available;

        return [
            ...$this->row($call, $viewer),
            'from_number' => $call->from_number,
            'to_number' => $call->to_number,
            'virtual_number' => $call->virtual_number,
            'channel_label' => $call->channel->label(),
            'contact_field' => $call->contact_field,
            'ringing_at' => $call->ringing_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'ring_seconds' => $call->ring_duration_seconds,
            'total_seconds' => $call->total_duration_seconds,
            'failure_code' => $call->failure_code,
            'disposition_at' => $call->disposition_at?->toIso8601String(),
            'disposition_by' => $call->dispositionBy?->name,
            'notes_updated_at' => $call->notes_updated_at?->toIso8601String(),
            'next_action' => $call->next_action,
            'followup' => $call->followup && ! $call->followup->trashed() ? ['id' => $call->followup->id, 'title' => $call->followup->title, 'url' => route('followups.show', $call->followup->id, false)] : null,
            'meeting' => $call->meeting && ! $call->meeting->trashed() ? ['id' => $call->meeting->id, 'meeting_number' => $call->meeting->meeting_number, 'url' => route('meetings.show', $call->meeting->id, false)] : null,
            'recording' => ($recording = $this->recording($call, $canListen)) === null ? null : $recording + [
                'stream_url' => $canListen && $available ? route('calls.recording', $call->id, false) : null,
                'download_url' => $canDownload && $available ? route('calls.recording.download', $call->id, false) : null,
            ],
            'can' => [
                'dispose' => $viewer->can('dispose', $call),
                'editNotes' => $viewer->can('editNotes', $call),
                'listen' => $canListen && $available,
                'download' => $canDownload && $available,
                'viewEvents' => $viewer->can('viewEvents', $call),
                'callBack' => $call->lead !== null && ! $call->lead->trashed() && $viewer->hasPermission(Permissions::CALL_MAKE),
            ],
        ];
    }

    /** Operational event timeline (admins/monitors only): no payloads. */
    public function event(CallEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'provider_status' => $event->provider_status,
            'processing_status' => $event->processing_status->value,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'received_at' => $event->received_at?->toIso8601String(),
            'error' => $event->error_message,
        ];
    }

    private function recording(Call $call, bool $canListen): ?array
    {
        $recording = $call->recording;
        if (! $recording) {
            return null;
        }

        return [
            'status' => $recording->status->value,
            'label' => $recording->status->label(),
            'playable' => $canListen && $recording->status === CallRecordingStatus::Available,
        ];
    }

    private function displayNumber(Call $call): ?string
    {
        $number = $call->direction === CallDirection::Inbound ? $call->from_number : $call->to_number;

        return $number ?: ($call->customer_number_normalized ? '+'.$call->customer_number_normalized : null);
    }
}
