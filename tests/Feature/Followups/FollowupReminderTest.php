<?php

use App\Enums\AuditAction;
use App\Jobs\SendFollowupReminder;
use App\Models\AuditLog;
use App\Models\FollowupReminder;
use App\Models\Lead;
use App\Notifications\Followups\FollowupReminderNotification;
use App\Services\Followups\FollowupReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->org = salesOrg();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
});

function reminderCount($user): int
{
    return $user->notifications()->where('type', FollowupReminderNotification::class)->count();
}

test('creating a follow-up plans a reminder row and an overdue-alert row', function () {
    $f = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_time' => '11:00', 'scheduled_date' => '2026-09-24', 'reminder_minutes' => 30]);

    $rows = $f->reminders()->orderBy('remind_at')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->kind)->toBe(FollowupReminder::KIND_REMINDER)
        ->and($rows[0]->remind_at->equalTo($f->scheduled_at->copy()->subMinutes(30)))->toBeTrue()
        ->and($rows[1]->kind)->toBe(FollowupReminder::KIND_OVERDUE)
        ->and($rows[1]->remind_at->equalTo($f->scheduled_at->copy()->addMinutes(60)))->toBeTrue();
});

test('RELEASE-BLOCKING: running the scheduler twice in the same window sends exactly one reminder', function () {
    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30', 'reminder_minutes' => 15]);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:16', 'Asia/Kolkata'));

    Artisan::call('followups:dispatch-reminders');
    Artisan::call('followups:dispatch-reminders');
    $this->travel(30)->seconds();
    Artisan::call('followups:dispatch-reminders');

    expect(reminderCount($this->org->rahul))->toBe(1)
        ->and(FollowupReminder::where('kind', 'reminder')->sole()->status)->toBe(FollowupReminder::SENT)
        ->and(AuditLog::where('action', AuditAction::FollowupReminderSent->value)->count())->toBe(1);

    $message = $this->org->rahul->notifications()->first()->data['message'];
    expect($message)->toBe('Follow-up with Amit Desai is due in 14 minutes.');
});

test('a claimed row is dispatched once even if the job is queued twice', function () {
    Queue::fake();
    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30', 'reminder_minutes' => 15]);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:20', 'Asia/Kolkata'));

    Artisan::call('followups:dispatch-reminders');
    Artisan::call('followups:dispatch-reminders');

    Queue::assertPushed(SendFollowupReminder::class, 1);

    $id = FollowupReminder::where('kind', 'reminder')->value('id');
    app()->call([new SendFollowupReminder($id), 'handle']);
    app()->call([new SendFollowupReminder($id), 'handle']);

    expect(reminderCount($this->org->rahul))->toBe(1);
});

test('nothing is sent before the reminder time and the command is silent when idle', function () {
    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '12:00', 'reminder_minutes' => 60]);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:59', 'Asia/Kolkata'));

    expect(Artisan::call('followups:dispatch-reminders'))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('')
        ->and(reminderCount($this->org->rahul))->toBe(0);
});

test('completing or cancelling a follow-up cancels its pending reminders', function () {
    $a = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '12:00']);
    $b = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '13:00']);

    $this->actingAs($this->org->rahul)->post("/follow-ups/{$a->id}/complete", ['outcome' => 'connected']);
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$b->id}/cancel", ['reason' => 'Not needed']);

    expect(FollowupReminder::where('status', FollowupReminder::PENDING)->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders');
    expect(reminderCount($this->org->rahul))->toBe(0);
});

test('the job re-checks state: a follow-up closed after claiming is not notified', function () {
    Queue::fake();
    $f = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30']);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:20', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders');

    $f->forceFill(['status' => 'completed'])->save();
    $id = FollowupReminder::where('kind', 'reminder')->value('id');
    app()->call([new SendFollowupReminder($id), 'handle']);

    expect(reminderCount($this->org->rahul))->toBe(0)
        ->and(FollowupReminder::find($id)->status)->toBe(FollowupReminder::CANCELLED);
});

test('the job does not notify an assignee who lost access to the lead', function () {
    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30']);
    $this->lead->forceFill(['assigned_to' => $this->org->priya->id])->save();

    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:20', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders');

    expect(reminderCount($this->org->rahul))->toBe(0)
        ->and(reminderCount($this->org->priya))->toBe(0);
});

test('overdue alert fires once after the configured delay', function () {
    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30', 'reminder_minutes' => null]);

    $this->travelTo(CarbonImmutable::parse('2026-09-23 11:29', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders');
    expect(reminderCount($this->org->rahul))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-09-23 11:31', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders');
    Artisan::call('followups:dispatch-reminders');

    $notes = $this->org->rahul->notifications()->get();
    expect($notes)->toHaveCount(1)
        ->and($notes[0]->data['event'])->toBe('followup_overdue')
        ->and($notes[0]->data['message'])->toBe('Follow-up with Amit Desai is overdue.');
});

test('delivery failure releases the row for retry, then gives up after max attempts', function () {
    $f = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30']);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:20', 'Asia/Kolkata'));

    Notification::shouldReceive('send')->andThrow(new RuntimeException('channel down'));

    Artisan::call('followups:dispatch-reminders');
    $row = FollowupReminder::where('kind', 'reminder')->sole();
    expect($row->status)->toBe(FollowupReminder::PENDING)
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toContain('channel down');

    Artisan::call('followups:dispatch-reminders');
    Artisan::call('followups:dispatch-reminders');
    expect($row->fresh()->status)->toBe(FollowupReminder::FAILED)
        ->and($row->fresh()->attempts)->toBe(FollowupReminder::MAX_ATTEMPTS);
});

test('rows stuck in processing are reclaimed and sent once', function () {
    Queue::fake();
    scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30']);
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:20', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders'); // claimed; worker "crashed"

    Queue::assertPushed(SendFollowupReminder::class, 1);
    $this->travel(FollowupReminderService::STALE_PROCESSING_MINUTES + 1)->minutes();
    Artisan::call('followups:dispatch-reminders');
    Queue::assertPushed(SendFollowupReminder::class, 2);
});

test('rescheduling moves reminders to the new time', function () {
    $f = scheduleFollowup($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '12:00', 'reminder_minutes' => 15]);
    $this->actingAs($this->org->rahul)->post("/follow-ups/{$f->id}/reschedule", ['scheduled_date' => '2026-09-24', 'scheduled_time' => '12:00'])->assertSessionHasNoErrors();

    expect($f->reminders()->where('status', FollowupReminder::PENDING)->count())->toBe(0);
    $new = $f->rescheduledTo;
    expect($new->reminders()->where('status', FollowupReminder::PENDING)->where('kind', 'reminder')->sole()->remind_at->equalTo(ist('2026-09-24 11:45')))->toBeTrue();
});
