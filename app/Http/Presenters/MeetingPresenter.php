<?php

namespace App\Http\Presenters;

use App\Enums\MeetingParticipantType;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Support\CrmTime;
use App\Support\MeetingReminderOptions;

/**
 * Shapes meeting data for Inertia / JSON. List rows and calendar events carry
 * only scheduling data — never outcome notes, agenda or lead notes.
 */
class MeetingPresenter
{
    /** Relations required by row(); lead columns include what visibility checks need. */
    public const ROW_WITH = [
        'lead:id,lead_number,full_name,phone,company_name,assigned_to,deleted_at',
        'type:id,name,color,icon,location_mode',
        'host:id,name',
        'participants:id,meeting_id,participant_type,user_id,lead_id,name,attendance_status',
    ];

    public function row(Meeting $meeting, User $viewer): array
    {
        return [
            'id' => $meeting->id,
            'meeting_number' => $meeting->meeting_number,
            'title' => $meeting->title,
            'type' => $meeting->type?->only('id', 'name', 'color', 'icon'),
            'start_at' => $meeting->start_at?->toIso8601String(),
            'end_at' => $meeting->end_at?->toIso8601String(),
            'duration_minutes' => $meeting->durationMinutes(),
            'status' => $meeting->status->value,
            'status_label' => $meeting->status->label(),
            'state' => $this->state($meeting),
            'priority' => $meeting->priority?->value,
            'location_type' => $meeting->location_type?->value,
            'location_type_label' => $meeting->location_type?->label(),
            'location' => $meeting->location,
            'has_link' => filled($meeting->meeting_url),
            'outcome' => $meeting->outcome?->label(),
            'lead' => $meeting->lead ? [
                'id' => $meeting->lead->id,
                'lead_number' => $meeting->lead->lead_number,
                'full_name' => $meeting->lead->full_name,
                'company_name' => $meeting->lead->company_name,
            ] : null,
            'host' => $meeting->host?->only('id', 'name'),
            'participants' => $meeting->participants->map(fn (MeetingParticipant $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->participant_type->value])->values()->all(),
            'can' => $this->abilities($meeting, $viewer),
        ];
    }

    /** Full record for the meeting page (viewer already authorised). */
    public function detail(Meeting $meeting, User $viewer): array
    {
        $mine = $meeting->participants->first(fn (MeetingParticipant $p) => $p->participant_type === MeetingParticipantType::User && (int) $p->user_id === $viewer->id);

        return array_merge($this->row($meeting, $viewer), [
            'description' => $meeting->description,
            'agenda' => $meeting->agenda,
            'address' => $meeting->address,
            'meeting_url' => $meeting->meeting_url,
            'timezone' => $meeting->timezone,
            'type_id' => $meeting->meeting_type_id,
            'host_user_id' => $meeting->host_user_id,
            'reminders' => $meeting->reminder_offsets ?? [],
            'reminder_labels' => collect($meeting->reminder_offsets ?? [])->map(fn ($m) => MeetingReminderOptions::label((int) $m))->all(),
            'participants' => $meeting->participants->map(fn (MeetingParticipant $p) => [
                'id' => $p->id,
                'type' => $p->participant_type->value,
                'type_label' => $p->participant_type->label(),
                'user_id' => $p->user_id,
                'lead_id' => $p->lead_id,
                'name' => $p->name,
                'email' => $p->email,
                'phone' => $p->phone,
                'attendance_status' => $p->attendance_status->value,
                'attendance_label' => $p->attendance_status->label(),
                'invitation_status' => $p->invitation_status,
            ])->values()->all(),
            'my_participant_id' => $mine?->id,
            'my_attendance' => $mine?->attendance_status->value,
            'outcome_value' => $meeting->outcome?->value,
            'outcome_notes' => $meeting->outcome_notes,
            'confirmed_at' => $meeting->confirmed_at?->toIso8601String(),
            'started_at' => $meeting->started_at?->toIso8601String(),
            'completed_at' => $meeting->completed_at?->toIso8601String(),
            'completer' => $meeting->completer?->only('id', 'name'),
            'cancelled_at' => $meeting->cancelled_at?->toIso8601String(),
            'canceller' => $meeting->canceller?->only('id', 'name'),
            'cancellation_reason' => $meeting->cancellation_reason,
            'reschedule_reason' => $meeting->reschedule_reason,
            'creator' => $meeting->creator?->only('id', 'name'),
            'created_at' => $meeting->created_at?->toIso8601String(),
        ]);
    }

    /**
     * FullCalendar event object. start / end are CRM-timezone wall-clock times
     * without an offset; the calendar runs in UTC-coercion mode so they render
     * in the CRM timezone regardless of the browser's zone.
     */
    public function event(Meeting $meeting, User $viewer): array
    {
        return [
            'id' => (string) $meeting->id,
            'title' => $meeting->title,
            'start' => CrmTime::format($meeting->start_at, 'Y-m-d\TH:i:s'),
            'end' => CrmTime::format($meeting->end_at, 'Y-m-d\TH:i:s'),
            'editable' => $viewer->can('reschedule', $meeting),
            'extendedProps' => [
                'meeting_number' => $meeting->meeting_number,
                'start_at' => $meeting->start_at->toIso8601String(),
                'end_at' => $meeting->end_at->toIso8601String(),
                'duration_minutes' => $meeting->durationMinutes(),
                'color' => $meeting->type?->color ?? 'slate',
                'type' => $meeting->type?->name,
                'status' => $meeting->status->value,
                'status_label' => $meeting->status->label(),
                'lead' => $meeting->lead?->full_name,
                'lead_number' => $meeting->lead?->lead_number,
                'host' => $meeting->host?->name,
                'location_type' => $meeting->location_type?->label(),
                'url' => route('meetings.show', $meeting->id, false),
            ],
        ];
    }

    /** Derived display state: live / upcoming / past for open meetings, else the status. */
    public function state(Meeting $meeting): string
    {
        if (! $meeting->isOpen()) {
            return $meeting->status->value;
        }

        $now = now();

        return match (true) {
            $meeting->status->value === 'in_progress', $meeting->start_at->lte($now) && $meeting->end_at->gt($now) => 'live',
            $meeting->end_at->lte($now) => 'past_due',
            default => 'upcoming',
        };
    }

    /** @return array<string, bool> */
    public function abilities(Meeting $meeting, User $viewer): array
    {
        return [
            'update' => $viewer->can('update', $meeting),
            'reschedule' => $viewer->can('reschedule', $meeting),
            'confirm' => $viewer->can('confirm', $meeting),
            'start' => $viewer->can('start', $meeting),
            'complete' => $viewer->can('complete', $meeting),
            'no_show' => $viewer->can('noShow', $meeting),
            'cancel' => $viewer->can('cancel', $meeting),
            'participants' => $viewer->can('manageParticipants', $meeting),
            'delete' => $viewer->can('delete', $meeting),
        ];
    }
}
