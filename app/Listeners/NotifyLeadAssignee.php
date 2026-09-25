<?php

namespace App\Listeners;

use App\Events\LeadAssigned;
use App\Models\User;
use App\Notifications\Leads\LeadAssignedNotification;
use App\Services\SettingService;
use Illuminate\Support\Facades\Log;

/**
 * In-app (and, if opted in, browser) notice to the new owner of a lead.
 * LeadAssigned only fires when ownership actually changes, so repeated or
 * idempotent assignment calls do not notify twice. Self-assignment is
 * silent, and Meta inbound leads are notified by MetaLeadIngestionService
 * (with channel and form context) instead. Failures never break assignment.
 */
class NotifyLeadAssignee
{
    public function __construct(private readonly SettingService $settings) {}

    public function handle(LeadAssigned $event): void
    {
        if ($event->toUserId === null || $event->toUserId === $event->assignedBy) {
            return;
        }
        if ($event->assignedBy === null && $event->lead->facebook_lead_id !== null) {
            return;
        }
        if (! $this->settings->get('notifications.notify_on_assignment', true)) {
            return;
        }

        try {
            $user = User::query()->active()->find($event->toUserId);
            $user?->notify(new LeadAssignedNotification($event->lead, $event->fromUserId !== null));
        } catch (\Throwable $e) {
            Log::warning('Lead assignment notification failed', ['lead_id' => $event->lead->id, 'user_id' => $event->toUserId, 'error' => class_basename($e)]);
        }
    }
}
