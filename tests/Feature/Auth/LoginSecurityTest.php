<?php

use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;

test('public registration is disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Intruder', 'email' => 'x@example.com',
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertNotFound();

    expect(User::where('email', 'x@example.com')->exists())->toBeFalse();
});

test('successful login records login history, audit entry and last login', function () {
    $user = User::factory()->salesExecutive()->create();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36')
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');

    $history = LoginHistory::where('user_id', $user->id)->where('event', 'login')->firstOrFail();
    expect($history->successful)->toBeTrue()
        ->and($history->browser)->toBe('Chrome')
        ->and($history->platform)->toBe('Windows')
        ->and($history->device)->toBe('desktop');

    $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN', 'user_id' => $user->id]);
    expect($user->refresh()->last_login_at)->not->toBeNull();
});

test('failed login is recorded without storing the password', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-secret-value']);

    $this->assertGuest();
    $this->assertDatabaseHas('login_histories', ['email' => $user->email, 'event' => 'failed', 'successful' => false]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN_FAILED']);
    $this->assertDatabaseMissing('audit_logs', ['new_values_json' => json_encode(['password' => 'wrong-secret-value'])]);
    expect(AuditLog::query()->get()->toJson())->not->toContain('wrong-secret-value');
});

test('inactive users cannot log in', function () {
    $user = User::factory()->salesExecutive()->inactive()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Your account has been deactivated. Contact your administrator.']);

    $this->assertGuest();
});

test('an active session is terminated once the user is deactivated', function () {
    $user = User::factory()->salesExecutive()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

test('logout stamps the logout time on the login record', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post('/logout')->assertRedirect('/login');

    $history = LoginHistory::where('user_id', $user->id)->where('event', 'login')->firstOrFail();
    expect($history->logged_out_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'LOGOUT', 'user_id' => $user->id]);
});

test('login is locked out after too many failed attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseHas('login_histories', ['event' => 'lockout', 'email' => $user->email]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN_LOCKOUT']);
});
