<?php

use App\Enums\AuditAction;
use App\Jobs\SendMeetingReminder;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\MeetingReminder;
use App\Notifications\Meetings\MeetingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00', 'Asia/Kolkata'));
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
});

function meetingReminderCount($user): int
{
    return $user->notifications()->where('type', MeetingReminderNotification::class)->count();
}

test('creating a meeting plans one reminder row per offset and attendee', function () {
    $m = scheduleMeeting(null, $this->org->manager, ['scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00', 'reminders' => [30, 1440], 'participant_user_ids' => [$this->org->rahul->id]]);

    $rows = $m->reminders()->orderBy('user_id')->orderBy('remind_at')->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('user_id')->unique()->sort()->values()->all())->toBe(collect([$this->org->manager->id, $this->org->rahul->id])->sort()->values()->all())
        ->and($rows->where('minutes_before', 30)->every(fn ($r) => $r->remind_at->equalTo(ist('2026-09-25 09:30'))))->toBeTrue()
        ->and($rows->where('minutes_before', 1440)->every(fn ($r) => $r->remind_at->equalTo(ist('2026-09-24 10:00'))))->toBeTrue();
});

test('RELEASE-BLOCKING: running the scheduler repeatedly in the same window sends exactly one reminder', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '10:00', 'end_time' => '11:00', 'reminders' => [30]]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:30', 'Asia/Kolkata'));

    Artisan::call('meetings:dispatch-reminders');
    Artisan::call('meetings:dispatch-reminders');
    $this->travel(40)->seconds();
    Artisan::call('meetings:dispatch-reminders');

    expect(meetingReminderCount($this->org->rahul))->toBe(1)
        ->and(MeetingReminder::sole()->status)->toBe(MeetingReminder::SENT)
        ->and(AuditLog::where('action', AuditAction::MeetingReminderSent->value)->count())->toBe(1)
        ->and($this->org->rahul->notifications()->first()->data['message'])->toBe('Product Demo with Amit Desai starts in 30 minutes.');
});

test('a claimed row is delivered once even if its job runs twice', function () {
    Queue::fake();
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '10:00', 'end_time' => '11:00', 'reminders' => [30]]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:31', 'Asia/Kolkata'));

    Artisan::call('meetings:dispatch-reminders');
    Artisan::call('meetings:dispatch-reminders');
    Queue::assertPushed(SendMeetingReminder::class, 1);

    $id = MeetingReminder::value('id');
    app()->call([new SendMeetingReminder($id), 'handle']);
    app()->call([new SendMeetingReminder($id), 'handle']);

    expect(meetingReminderCount($this->org->rahul))->toBe(1);
});

test('the scheduler is silent when idle and sends nothing early', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '12:00', 'end_time' => '13:00', 'reminders' => [60]]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:59', 'Asia/Kolkata'));

    expect(Artisan::call('meetings:dispatch-reminders'))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('')
        ->and(meetingReminderCount($this->org->rahul))->toBe(0);
});

test('RELEASE-BLOCKING: no reminder fires after cancel, reschedule or complete', function () {
    $cancelled = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '12:00', 'end_time' => '12:30']);
    $rescheduled = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '13:00', 'end_time' => '13:30']);
    $completed = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '14:00', 'end_time' => '14:30']);

    $this->actingAs($this->org->rahul)->post("/meetings/{$cancelled->id}/cancel", ['reason' => 'Client travelling'])->assertSessionHasNoErrors();
    $this->actingAs($this->org->rahul)->post("/meetings/{$rescheduled->id}/reschedule", ['scheduled_date' => '2026-09-30', 'start_time' => '13:00', 'end_time' => '13:30'])->assertSessionHasNoErrors();
    $this->actingAs($this->org->rahul)->post("/meetings/{$completed->id}/complete", ['outcome' => 'interested', 'notes' => 'Went well'])->assertSessionHasNoErrors();

    foreach ([$cancelled, $rescheduled, $completed] as $m) {
        expect($m->reminders()->where('status', MeetingReminder::PENDING)->count())->toBe(0);
    }

    $this->travelTo(CarbonImmutable::parse('2026-09-24 20:00', 'Asia/Kolkata'));
    Artisan::call('meetings:dispatch-reminders');
    expect(meetingReminderCount($this->org->rahul))->toBe(0);
});

test('RELEASE-BLOCKING §74: Sep 25 10:00 with a 30-minute reminder rescheduled to Sep 26 15:00 reminds at 14:30 only', function () {
    $m = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00', 'reminders' => [30]]);
    $old = $m->reminders()->sole();
    expect($old->remind_at->equalTo(ist('2026-09-25 09:30')))->toBeTrue();

    $this->actingAs($this->org->rahul)->post("/meetings/{$m->id}/reschedule", ['scheduled_date' => '2026-09-26', 'start_time' => '15:00', 'end_time' => '16:00'])->assertSessionHasNoErrors();

    $new = $m->fresh()->rescheduledTo;
    expect($old->fresh()->status)->toBe(MeetingReminder::CANCELLED)
        ->and($new->reminders()->where('status', MeetingReminder::PENDING)->sole()->remind_at->equalTo(ist('2026-09-26 14:30')))->toBeTrue();

    // Old reminder time passes: nothing is sent.
    $this->travelTo(CarbonImmutable::parse('2026-09-25 09:31', 'Asia/Kolkata'));
    Artisan::call('meetings:dispatch-reminders');
    expect(meetingReminderCount($this->org->rahul))->toBe(0);

    // New reminder time: exactly one.
    $this->travelTo(CarbonImmutable::parse('2026-09-26 14:30', 'Asia/Kolkata'));
    Artisan::call('meetings:dispatch-reminders');
    Artisan::call('meetings:dispatch-reminders');
    expect(meetingReminderCount($this->org->rahul))->toBe(1);
});

test('the job re-checks state: a meeting cancelled after claiming is not notified', function () {
    Queue::fake();
    $m = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '10:00', 'end_time' => '11:00', 'reminders' => [30]]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:31', 'Asia/Kolkata'));
    Artisan::call('meetings:dispatch-reminders');

    $m->forceFill(['status' => 'cancelled'])->save();
    $id = MeetingReminder::value('id');
    app()->call([new SendMeetingReminder($id), 'handle']);

    expect(meetingReminderCount($this->org->rahul))->toBe(0)
        ->and(MeetingReminder::find($id)->status)->toBe(MeetingReminder::CANCELLED);
});

test('reminders are not sent to a host who lost access to the lead, or to a participant who declined', function () {
    $m = scheduleMeeting(null, $this->org->manager, ['scheduled_date' => '2026-09-24', 'start_time' => '11:00', 'end_time' => '11:30', 'reminders' => [30], 'participant_user_ids' => [$this->org->rahul->id]]);
    $this->actingAs($this->org->rahul)->post("/meetings/{$m->id}/respond", ['attendance_status' => 'declined'])->assertSessionHasNoErrors();

    $leadMeeting = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '11:00', 'end_time' => '11:30', 'reminders' => [30]]);
    $this->lead->forceFill(['assigned_to' => $this->org->priya->id])->save();

    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:35', 'Asia/Kolkata'));
    Artisan::call('meetings:dispatch-reminders');

    expect(meetingReminderCount($this->org->rahul))->toBe(0)
        ->and(meetingReminderCount($this->org->manager))->toBe(1);
});

test('a reminder window that has already passed at scheduling time reminds once, immediately', function () {
    $m = scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '09:20', 'end_time' => '10:00', 'reminders' => [30, 60]]);

    $rows = $m->reminders()->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->remind_at->lte(now()))->toBeTrue();

    Artisan::call('meetings:dispatch-reminders');
    Artisan::call('meetings:dispatch-reminders');
    expect(meetingReminderCount($this->org->rahul))->toBe(1);
});

test('delivery failures are retried, then marked failed after max attempts', function () {
    scheduleMeeting($this->lead, $this->org->rahul, ['scheduled_date' => '2026-09-24', 'start_time' => '10:00', 'end_time' => '11:00', 'reminders' => [30]]);
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:31', 'Asia/Kolkata'));
    Notification::shouldReceive('send')->andThrow(new RuntimeException('channel down'));

    Artisan::call('meetings:dispatch-reminders');
    $row = MeetingReminder::sole();
    expect($row->status)->toBe(MeetingReminder::PENDING)->and($row->attempts)->toBe(1);

    Artisan::call('meetings:dispatch-reminders');
    Artisan::call('meetings:dispatch-reminders');
    expect($row->fresh()->status)->toBe(MeetingReminder::FAILED)
        ->and($row->fresh()->failed_at)->not->toBeNull();
});
