<?php

use App\Http\Controllers\NotificationController;
use App\Models\Lead;
use App\Notifications\Followups\FollowupAssignedNotification;
use App\Notifications\Followups\FollowupRescheduledNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->org = salesOrg();
    $this->lead = Lead::factory()->assignedTo($this->org->rahul)->create(['first_name' => 'Amit', 'last_name' => 'Desai']);
});

test('the assignee is notified when an admin schedules a follow-up for them; never the actor', function () {
    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead))->assertSessionHasNoErrors();

    $notes = $this->org->rahul->notifications()->get();
    expect($notes)->toHaveCount(1)
        ->and($notes[0]->type)->toBe(FollowupAssignedNotification::class)
        ->and($notes[0]->data['event'])->toBe('followup_assigned')
        ->and($notes[0]->data['lead_id'])->toBe($this->lead->id)
        ->and($notes[0]->data)->toHaveKeys(['category', 'event', 'message', 'followup_id', 'lead_id', 'url'])
        ->and($this->org->admin->notifications()->count())->toBe(0);
});

test('self-scheduled follow-ups do not notify anyone', function () {
    $this->actingAs($this->org->rahul)->post('/follow-ups', followupPayload($this->lead))->assertSessionHasNoErrors();

    expect(DB::table('notifications')->count())->toBe(0);
});

test('reassigning a follow-up notifies the new assignee', function () {
    $f = scheduleFollowup($this->lead, $this->org->admin, ['assigned_to' => $this->org->admin->id]);
    DB::table('notifications')->delete();

    $this->actingAs($this->org->admin)->put("/follow-ups/{$f->id}", ['assigned_to' => $this->org->rahul->id])->assertSessionHasNoErrors();

    expect($this->org->rahul->notifications()->where('type', FollowupAssignedNotification::class)->count())->toBe(1);
});

test('rescheduling by someone else notifies the assignee', function () {
    $f = scheduleFollowup($this->lead, $this->org->rahul);
    $this->actingAs($this->org->admin)->post("/follow-ups/{$f->id}/reschedule", crmSlot(now()->addDays(4)))->assertSessionHasNoErrors();

    expect($this->org->rahul->notifications()->where('type', FollowupRescheduledNotification::class)->count())->toBe(1);
});

test('notifications are not sent when the transaction rolls back', function () {
    Notification::fake();
    // A viewer who can see the lead but lacks followup.schedule_past.
    $viewer = setPermission($this->org->manager, 'lead.view_all');
    $this->actingAs($viewer)->post('/follow-ups', followupPayload($this->lead, ['scheduled_date' => '2020-01-01']))
        ->assertSessionHasErrors('scheduled_time');

    Notification::assertNothingSent();
});

test('the bell endpoint returns only the user\'s own notifications with an unread count', function () {
    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead));

    $this->actingAs($this->org->rahul)->getJson('/notifications/recent')
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.stale', false);

    $this->actingAs($this->org->priya)->getJson('/notifications/recent')
        ->assertJsonPath('unread', 0)
        ->assertJsonCount(0, 'data');
});

test('users cannot read or open another user\'s notification', function () {
    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead));
    $id = $this->org->rahul->notifications()->value('id');

    $this->actingAs($this->org->priya)->post("/notifications/{$id}/read")->assertNotFound();
    $this->actingAs($this->org->priya)->get("/notifications/{$id}/open")->assertNotFound();
    expect($this->org->rahul->notifications()->first()->read_at)->toBeNull();
});

test('opening a notification marks it read and goes to the follow-up', function () {
    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead));
    $n = $this->org->rahul->notifications()->first();

    $this->actingAs($this->org->rahul)->get("/notifications/{$n->id}/open")
        ->assertRedirect("/follow-ups/{$n->data['followup_id']}");
    expect($n->fresh()->read_at)->not->toBeNull();
});

test('the payload is not authorization: stale notifications are masked and do not link', function () {
    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead));
    $n = $this->org->rahul->notifications()->first();

    // Rahul loses the lead.
    $this->lead->forceFill(['assigned_to' => $this->org->priya->id])->save();

    $response = $this->actingAs($this->org->rahul)->getJson('/notifications/recent');
    $response->assertJsonPath('data.0.stale', true)
        ->assertJsonPath('data.0.message', NotificationController::STALE_MESSAGE)
        ->assertJsonPath('data.0.target', null);
    expect($response->getContent())->not->toContain('Amit Desai');

    $this->actingAs($this->org->rahul)->get("/notifications/{$n->id}/open")
        ->assertRedirect('/notifications')
        ->assertSessionHas('error', NotificationController::STALE_MESSAGE);
});

test('a tampered payload pointing at someone else\'s follow-up is masked', function () {
    $priyaLead = Lead::factory()->assignedTo($this->org->priya)->create();
    $priyaF = scheduleFollowup($priyaLead, $this->org->priya);

    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead));
    $n = $this->org->rahul->notifications()->first();
    $n->forceFill(['data' => array_merge($n->data, ['followup_id' => $priyaF->id, 'url' => "/follow-ups/{$priyaF->id}"])])->save();

    $this->actingAs($this->org->rahul)->getJson('/notifications/recent')->assertJsonPath('data.0.stale', true);
    $this->actingAs($this->org->rahul)->get("/notifications/{$n->id}/open")->assertRedirect('/notifications');
});

test('mark all read and the notification center page work', function () {
    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead));
    $this->actingAs($this->org->admin)->post('/follow-ups', followupPayload($this->lead, ['scheduled_time' => '16:00']));

    $this->actingAs($this->org->rahul)->get('/notifications')->assertOk();
    expect($this->actingAs($this->org->rahul)->get('/notifications')->inertiaProps('unread'))->toBe(2);

    $this->actingAs($this->org->rahul)->postJson('/notifications/read-all')->assertOk();
    expect($this->org->rahul->unreadNotifications()->count())->toBe(0);
});
