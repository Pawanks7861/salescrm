<?php

use App\Jobs\SendWebPushNotification;
use App\Models\FollowupReminder;
use App\Models\Lead;
use App\Notifications\Followups\FollowupReminderNotification;
use App\Services\Notifications\PushTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/*
| Follow-up reminder pushes ride on the existing Phase 3 reminder pipeline
| (followups:dispatch-reminders + reminder rows), so a scheduler re-run
| never produces a second notification or push.
*/
beforeEach(function () {
    pushConfigure();
    $this->org = salesOrg();
    $this->rahul = pushOptIn($this->org->rahul);
    pushSubscribe($this->rahul, 'rahul-laptop');
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->lead = Lead::factory()->assignedTo($this->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
    $this->followup = scheduleFollowup($this->lead, $this->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30', 'reminder_minutes' => 15]);
});

function reminderNotifications($user)
{
    return $user->notifications()->where('type', FollowupReminderNotification::class)->get();
}

test('a due reminder creates one notification and one push, even when the scheduler runs repeatedly', function () {
    $transport = pushFakeTransport();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:16', 'Asia/Kolkata'));

    Artisan::call('followups:dispatch-reminders');
    Artisan::call('followups:dispatch-reminders');
    $this->travel(30)->seconds();
    Artisan::call('followups:dispatch-reminders');

    $notification = reminderNotifications($this->rahul)->sole();
    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['payload'])->toMatchArray([
            'id' => $notification->id,
            'event' => 'FOLLOWUP_REMINDER',
            'title' => 'Follow-up Reminder',
            'body' => 'Call with Amit Desai at 10:30 AM.',
            'url' => "/notifications/{$notification->id}/open",
        ]);
});

test('the reminder push is queued once per reminder', function () {
    Queue::fake([SendWebPushNotification::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:16', 'Asia/Kolkata'));

    Artisan::call('followups:dispatch-reminders');
    Artisan::call('followups:dispatch-reminders');

    Queue::assertPushed(SendWebPushNotification::class, 1);
    expect(reminderNotifications($this->rahul))->toHaveCount(1);
});

test('overdue alerts stay in-app only', function () {
    $transport = pushFakeTransport();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 11:31', 'Asia/Kolkata'));

    Artisan::call('followups:dispatch-reminders');

    $events = reminderNotifications($this->rahul)->pluck('data.event')->all();
    expect($events)->toContain('followup_overdue');
    $pushed = collect($transport->sent)->pluck('payload.event')->all();
    expect($pushed)->not->toContain('followup_overdue');
});

test('clicking the reminder push opens the follow-up after re-checking access', function () {
    pushFakeTransport();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:16', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders');
    $id = reminderNotifications($this->rahul)->sole()->id;

    $this->actingAs($this->rahul)->get("/notifications/{$id}/open")->assertRedirect("/follow-ups/{$this->followup->id}");
    $this->actingAs($this->org->priya)->get("/notifications/{$id}/open")->assertNotFound();
});

test('a push failure never fails the reminder', function () {
    app()->instance(PushTransport::class, new class implements PushTransport
    {
        public function send(iterable $subscriptions, string $payload): array
        {
            throw new RuntimeException('push service down');
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:16', 'Asia/Kolkata'));

    expect(Artisan::call('followups:dispatch-reminders'))->toBe(0)
        ->and(reminderNotifications($this->rahul))->toHaveCount(1)
        ->and($this->followup->reminders()->where('kind', 'reminder')->sole()->status)->toBe(FollowupReminder::SENT);
});

test('no push when the user has not opted in, but the in-app reminder still arrives', function () {
    $transport = pushFakeTransport();
    $this->rahul->forceFill(['browser_notifications_enabled' => false])->save();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:16', 'Asia/Kolkata'));

    Artisan::call('followups:dispatch-reminders');

    expect(reminderNotifications($this->rahul))->toHaveCount(1)
        ->and($transport->sent)->toBe([]);
});
