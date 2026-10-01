<?php

namespace App\Http\Presenters;

use App\Enums\FollowupStatus;
use App\Models\Followup;
use App\Models\User;
use App\Services\SettingService;
use App\Support\FollowupReminderOptions;

/**
 * Shapes follow-up data for Inertia. List rows carry only what sales work
 * needs — never completion notes, lead notes or custom fields.
 */
class FollowupPresenter
{
    /** Relations required by row(); lead columns include what visibility checks need. */
    public const ROW_WITH = [
        'lead:id,lead_number,full_name,phone,company_name,assigned_to,deleted_at',
        'type:id,name,color,icon',
        'assignee:id,name',
    ];

    private ?int $dueSoonMinutes = null;

    public function __construct(private readonly SettingService $settings) {}

    public function row(Followup $followup, User $viewer): array
    {
        return [
            'id' => $followup->id,
            'title' => $followup->displayTitle(),
            'type' => $followup->type?->only('id', 'name', 'color', 'icon'),
            'scheduled_at' => $followup->scheduled_at?->toIso8601String(),
            'status' => $followup->status?->value ?? 'pending',
            'state' => $this->state($followup),
            'priority' => $followup->priority?->value,
            'outcome' => $followup->outcome?->label(),
            'lead' => $followup->lead ? [
                'id' => $followup->lead->id,
                'lead_number' => $followup->lead->lead_number,
                'full_name' => $followup->lead->full_name,
                'company_name' => $followup->lead->company_name,
                'phone' => $followup->lead->phone,
            ] : null,
            'assignee' => $followup->assignee?->only('id', 'name'),
            'updated_at' => $followup->updated_at?->toIso8601String(),
            'can' => $this->abilities($followup, $viewer),
        ];
    }

    /** Full record for the follow-up page (viewer already authorised). */
    public function detail(Followup $followup, User $viewer): array
    {
        return array_merge($this->row($followup, $viewer), [
            'raw_title' => $followup->title,
            'description' => $followup->description,
            'type_id' => $followup->followup_type_id,
            'assigned_to' => $followup->assigned_to,
            'reminder_minutes' => $followup->reminder_minutes_before,
            'reminder_label' => FollowupReminderOptions::label($followup->reminder_minutes_before),
            'timezone' => $followup->timezone,
            'outcome_value' => $followup->outcome?->value,
            'notes' => $followup->notes,
            'next_action' => $followup->next_action,
            'completed_at' => $followup->completed_at?->toIso8601String(),
            'completer' => $followup->completer?->only('id', 'name'),
            'cancelled_at' => $followup->cancelled_at?->toIso8601String(),
            'canceller' => $followup->canceller?->only('id', 'name'),
            'cancellation_reason' => $followup->cancellation_reason,
            'reschedule_reason' => $followup->reschedule_reason,
            'creator' => $followup->creator?->only('id', 'name'),
            'created_at' => $followup->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Derived display state. Overdue / due soon are computed, never stored.
     */
    public function state(Followup $followup): string
    {
        if ($followup->status !== FollowupStatus::Pending) {
            return $followup->status?->value ?? 'pending';
        }

        if (! $followup->scheduled_at) {
            return 'pending';
        }

        if ($followup->scheduled_at->lt(now())) {
            return 'overdue';
        }

        $this->dueSoonMinutes ??= (int) $this->settings->get('followup.due_soon_minutes', 60);

        return $followup->scheduled_at->lte(now()->addMinutes($this->dueSoonMinutes)) ? 'due_soon' : 'pending';
    }

    /** @return array<string, bool> */
    public function abilities(Followup $followup, User $viewer): array
    {
        return [
            'update' => $viewer->can('update', $followup),
            'complete' => $viewer->can('complete', $followup),
            'reschedule' => $viewer->can('reschedule', $followup),
            'cancel' => $viewer->can('cancel', $followup),
            'delete' => $viewer->can('delete', $followup),
        ];
    }
}
