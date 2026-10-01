<?php

use App\Models\Followup;
use App\Models\FollowupReminder;
use App\Models\Lead;
use App\Notifications\Followups\FollowupReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
| End-to-end reminder delivery for a follow-up scheduled through the real
| HTTP endpoint: reminder ledger → followups:dispatch-reminders → job →
| database notification → browser push. Follow-up types are plain labels; the
| Call type needs nothing from the removed telephony module.
*/
beforeEach(function () {
    pushConfigure();
    $this->org = salesOrg();
    $this->rahul = pushOptIn($this->org->rahul);
    pushSubscribe($this->rahul, 'rahul-laptop');
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Kolkata'));
    $this->lead = Lead::factory()->assignedTo($this->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
});

function pipelineReminders($user)
{
    return $user->notifications()->where('type', FollowupReminderNotification::class)->get();
}

test('every follow-up type, including Call, gets one in-app reminder and one push for the assignee only', function (string $slug, string $label) {
    $transport = pushFakeTransport();

    $this->actingAs($this->rahul)->post('/follow-ups', followupPayload($this->lead, [
        'followup_type_id' => followupTypeId($slug),
        'scheduled_date' => '2026-09-23',
        'scheduled_time' => '10:30',
        'reminder_minutes' => 15,
    ]))->assertSessionHasNoErrors();

    $followup = Followup::sole();
    $row = $followup->reminders()->where('kind', FollowupReminder::KIND_REMINDER)->sole();
    expect($row->status)->toBe(FollowupReminder::PENDING)
        ->and($row->remind_at->equalTo(ist('2026-09-23 10:15')))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:15', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders');
    Artisan::call('followups:dispatch-reminders');

    $notification = pipelineReminders($this->rahul)->sole();
    expect($notification->data)->toMatchArray([
        'category' => 'followup',
        'event' => 'followup_reminder',
        'followup_id' => $followup->id,
        'lead_id' => $this->lead->id,
        'url' => "/follow-ups/{$followup->id}",
    ])
        ->and($row->fresh()->status)->toBe(FollowupReminder::SENT)
        ->and($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['payload'])->toMatchArray([
            'id' => $notification->id,
            'event' => 'FOLLOWUP_REMINDER',
            'body' => "{$label} with Amit Desai at 10:30 AM.",
        ])
        ->and(pipelineReminders($this->org->priya))->toHaveCount(0)
        ->and(pipelineReminders($this->org->manager))->toHaveCount(0)
        ->and(pipelineReminders($this->org->admin))->toHaveCount(0)
        ->and(Schema::hasTable('calls'))->toBeFalse();
})->with([
    'Call' => ['call', 'Call'],
    'WhatsApp' => ['whatsapp', 'WhatsApp'],
    'Email' => ['email', 'Email'],
    'Demo' => ['demo', 'Demo'],
    'Site Visit' => ['site_visit', 'Site Visit'],
    'Other' => ['other', 'Other'],
]);

test('a follow-up scheduled inside its reminder window is reminded once, right away', function () {
    $f = scheduleFollowup($this->lead, $this->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:10', 'reminder_minutes' => 15]);

    expect($f->reminders()->where('kind', FollowupReminder::KIND_REMINDER)->sole()->remind_at->lessThanOrEqualTo(now()))->toBeTrue();

    Artisan::call('followups:dispatch-reminders');
    $this->travel(1)->minutes();
    Artisan::call('followups:dispatch-reminders');

    expect(pipelineReminders($this->rahul))->toHaveCount(1)
        ->and(pipelineReminders($this->rahul)->sole()->data['message'])->toBe('Follow-up with Amit Desai is due in 10 minutes.');
});

test('"no reminder" plans no reminder row and sends nothing before the overdue alert', function () {
    $f = scheduleFollowup($this->lead, $this->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30', 'reminder_minutes' => null]);

    expect($f->reminders()->where('kind', FollowupReminder::KIND_REMINDER)->count())->toBe(0)
        ->and($f->reminders()->where('kind', FollowupReminder::KIND_OVERDUE)->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:30', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders');

    expect(pipelineReminders($this->rahul))->toHaveCount(0);
});

test('an assignee who lost access gets nothing and the reminder is cancelled, not retried', function () {
    $transport = pushFakeTransport();
    $f = scheduleFollowup($this->lead, $this->rahul, ['scheduled_date' => '2026-09-23', 'scheduled_time' => '10:30', 'reminder_minutes' => 15]);
    $this->lead->forceFill(['assigned_to' => $this->org->priya->id])->save();

    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:16', 'Asia/Kolkata'));
    Artisan::call('followups:dispatch-reminders');
    $this->travel(FollowupReminder::MAX_ATTEMPTS)->minutes();
    Artisan::call('followups:dispatch-reminders');

    expect($f->reminders()->where('kind', FollowupReminder::KIND_REMINDER)->sole()->status)->toBe(FollowupReminder::CANCELLED)
        ->and(pipelineReminders($this->rahul))->toHaveCount(0)
        ->and(pipelineReminders($this->org->priya))->toHaveCount(0)
        ->and($transport->sent)->toBe([]);
});
