<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\CallDisposition;
use App\Models\Lead;
use App\Models\TelephonyNumber;
use App\Models\TelephonyUser;
use App\Services\SettingService;
use App\Services\Telephony\TelephonyAdminService;
use App\Support\SettingDefinitions;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->org = salesOrg();
    $this->integration = telephonySetup([$this->org->rahul]);
});

test('only users with call.configure can open the telephony admin page', function () {
    $this->actingAs($this->org->rahul)->get(route('admin.integrations.telephony.index'))->assertForbidden();
    $this->actingAs($this->org->manager)->get(route('admin.integrations.telephony.index'))->assertForbidden();
    $this->actingAs($this->org->admin)->get(route('admin.integrations.telephony.index'))->assertForbidden();
    $this->actingAs($this->org->super)->get(route('admin.integrations.telephony.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Integrations/Telephony/Index'));
});

test('the admin page never calls the provider and never exposes credentials', function () {
    Http::fake();
    config([
        'telephony.exotel.api_key' => 'secret-key-value',
        'telephony.exotel.api_token' => 'secret-token-value',
        'telephony.exotel.webhook_secret' => 'secret-hook-value',
    ]);

    $response = $this->actingAs($this->org->super)->get(route('admin.integrations.telephony.index'))->assertOk();

    Http::assertNothingSent();
    expect($response->getContent())
        ->not->toContain('secret-key-value')
        ->not->toContain('secret-token-value')
        ->not->toContain('secret-hook-value')
        ->not->toContain(TELEPHONY_TEST_TOKEN);
});

test('sales users cannot change telephony configuration', function () {
    $this->actingAs($this->org->rahul)->put(route('admin.integrations.telephony.integration'), ['name' => 'x', 'default_calling_mode' => 'pstn'])->assertForbidden();
    $this->actingAs($this->org->rahul)->post(route('admin.integrations.telephony.agents.store'), ['user_id' => $this->org->rahul->id, 'calling_mode' => 'pstn'])->assertForbidden();
    $this->actingAs($this->org->manager)->post(route('admin.integrations.telephony.health'))->assertForbidden();
});

test('integration changes are audited', function () {
    $this->actingAs($this->org->super)->put(route('admin.integrations.telephony.integration'), [
        'name' => 'Sales telephony', 'is_active' => true, 'browser_calling_enabled' => false,
        'pstn_calling_enabled' => true, 'recording_enabled' => true, 'default_calling_mode' => 'pstn',
    ])->assertSessionHasNoErrors();

    expect($this->integration->fresh()->browser_calling_enabled)->toBeFalse();
    $audit = AuditLog::where('action', AuditAction::TelephonyConfigurationChanged->value)->latest('id')->first();
    expect($audit->new_values_json)->toHaveKey('browser_calling_enabled');
});

test('numbers are validated, de-duplicated and audited', function () {
    $payload = ['phone_number' => '+91 80000 00002', 'display_name' => 'Support', 'number_type' => 'virtual', 'is_active' => true];

    $this->actingAs($this->org->super)->post(route('admin.integrations.telephony.numbers.store'), $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->org->super)->post(route('admin.integrations.telephony.numbers.store'), $payload)->assertSessionHasErrors('phone_number');
    $this->actingAs($this->org->super)->post(route('admin.integrations.telephony.numbers.store'), [...$payload, 'phone_number' => 'abc'])->assertSessionHasErrors('phone_number');

    expect(TelephonyNumber::where('normalized_number', '918000000002')->count())->toBe(1)
        ->and(AuditLog::where('action', AuditAction::TelephonyConfigurationChanged->value)->exists())->toBeTrue();
});

test('calling accounts are one per user and audited', function () {
    $this->actingAs($this->org->super)->post(route('admin.integrations.telephony.agents.store'), [
        'user_id' => $this->org->priya->id, 'registered_phone' => '+91 90000 00077', 'calling_mode' => 'pstn', 'is_enabled' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->org->super)->post(route('admin.integrations.telephony.agents.store'), [
        'user_id' => $this->org->priya->id, 'calling_mode' => 'pstn',
    ])->assertSessionHasErrors('user_id');

    expect(TelephonyUser::where('user_id', $this->org->priya->id)->sole()->registered_phone_normalized)->toBe('919000000077')
        ->and(AuditLog::where('action', AuditAction::TelephonyUserChanged->value)->exists())->toBeTrue();
});

test('dispositions can be added and deactivated but never deleted', function () {
    $this->actingAs($this->org->super)->post(route('admin.integrations.telephony.dispositions.store'), [
        'name' => 'Price objection', 'color' => 'amber', 'is_contact' => true, 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $disposition = CallDisposition::where('name', 'Price objection')->sole();
    expect($disposition->slug)->toBe('price_objection')->and($disposition->is_system)->toBeFalse();

    $this->actingAs($this->org->super)->put(route('admin.integrations.telephony.dispositions.update', $disposition), [
        'name' => 'Price objection', 'color' => 'amber', 'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect($disposition->fresh()->is_active)->toBeFalse();
    $this->actingAs($this->org->super)->delete('/admin/integrations/telephony/dispositions/'.$disposition->id)->assertStatus(405);
});

test('settings updates are validated and audited', function () {
    $form = collect(SettingDefinitions::forGroup('telephony'))
        ->only(TelephonyAdminService::SETTING_KEYS)
        ->mapWithKeys(fn ($definition, $key) => [substr($key, strlen('telephony.')) => $definition['default']])
        ->all();

    $this->actingAs($this->org->super)->put(route('admin.integrations.telephony.settings'), ['settings' => [...$form, 'recording_retention_days' => 7]])
        ->assertSessionHasErrors('settings.recording_retention_days');

    $this->actingAs($this->org->super)->put(route('admin.integrations.telephony.settings'), ['settings' => [...$form, 'recording_retention_days' => 365]])
        ->assertSessionHasNoErrors();

    expect((int) app(SettingService::class)->get('telephony.recording_retention_days'))->toBe(365)
        ->and(AuditLog::where('action', AuditAction::TelephonyConfigurationChanged->value)->where('description', 'like', '%recording settings%')->exists())->toBeTrue();
});

test('the health check records the result', function () {
    $this->actingAs($this->org->super)->post(route('admin.integrations.telephony.health'))->assertSessionHas('success');

    expect($this->integration->fresh()->last_health_status)->toBe('connected');
});

test('role defaults grant the documented call permissions', function () {
    $defaults = RoleSeeder::defaults();
    $exec = $defaults['sales_executive']['permissions'];
    $manager = $defaults['sales_manager']['permissions'];
    $admin = $defaults['admin']['permissions'];

    expect($exec)->toContain('call.view', 'call.make', 'call.receive', 'call.add_disposition', 'call.edit_notes')
        ->not->toContain('call.recording.listen', 'call.recording.download', 'call.manual_dial', 'call.view_team', 'call.configure')
        ->and($manager)->toContain('call.recording.listen')
        ->not->toContain('call.view_team', 'call.recording.download', 'call.configure', 'call.view_all')
        ->and($admin)->toContain('call.view_all', 'call.monitor', 'call.recording.listen')
        ->not->toContain('call.recording.download', 'call.configure', 'facebook.manage');
});

test('navigation shows Calls to call users and Telephony only to configurers', function () {
    $labels = fn ($user) => collect($this->actingAs($user)->get('/dashboard')->inertiaProps('navigation'))
        ->flatMap(fn ($section) => collect($section['items'])->pluck('label'))
        ->all();

    expect($labels($this->org->rahul))->toContain('Calls')->not->toContain('Telephony')
        ->and($labels($this->org->admin))->toContain('Calls')->not->toContain('Telephony')->not->toContain('Facebook')
        ->and($labels($this->org->super))->toContain('Calls', 'Telephony', 'Facebook');
});

test('the lead page lists the lead calls and offers calling by contact field only', function () {
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create(['phone' => '9876543210', 'normalized_phone' => '919876543210']);
    $call = startCall($lead, $this->org->rahul);

    $this->actingAs($this->org->rahul)->get(route('leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calls.0.id', $call->id)
            ->where('calling.enabled', true)
            ->where('calling.contacts.0.field', 'phone')
            ->where('can.call', true));
});

test('the lead page hides calling for users without call.make', function () {
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    setPermission($this->org->rahul, 'call.make', 'deny');

    $this->actingAs($this->org->rahul->fresh())->get(route('leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('calling', null)->where('can.call', false));
});

test('the dashboard shows scoped call counters', function () {
    $lead = Lead::factory()->assignedTo($this->org->rahul)->create();
    finishCall($this, startCall($lead, $this->org->rahul));

    $this->actingAs($this->org->rahul)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('calls.counts')->has('calls.awaiting', 1));

    $this->actingAs($this->org->otherManager)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('calls.awaiting', 0));
});
