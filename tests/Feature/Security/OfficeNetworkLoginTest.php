<?php

use App\Models\User;
use App\Services\Security\OfficeNetworkGuard;
use App\Services\SettingService;

beforeEach(function () {
    $this->user = User::factory()->salesExecutive()->create();
});

function restrictTo(string $ips): void
{
    app(SettingService::class)->updateGroup('security', [
        'security.office_wifi_only' => true,
        'security.office_wifi_ips' => $ips,
    ]);
}

test('sign-in is refused outside the medawk wifi allow list', function () {
    restrictTo('203.0.113.10');

    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->get('email')[0])->toBe(OfficeNetworkGuard::MESSAGE);
});

test('sign-in works from an allowed medawk wifi address', function () {
    restrictTo('127.0.0.1');

    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
});

test('a cidr range allows the office network', function () {
    restrictTo('127.0.0.0/8');

    expect(app(OfficeNetworkGuard::class)->allows('127.0.0.1'))->toBeTrue()
        ->and(app(OfficeNetworkGuard::class)->allows('203.0.113.10'))->toBeFalse();
});

test('an existing session is signed out when the request is off the office wifi', function () {
    restrictTo('203.0.113.10');

    $this->actingAs($this->user)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

test('the bypass switch turns the wifi restriction off', function () {
    restrictTo('203.0.113.10');
    config(['crm.office_wifi_bypass' => true]);

    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
});
