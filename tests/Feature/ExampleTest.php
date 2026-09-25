<?php

use App\Models\User;

it('redirects guests from the root to the login page', function () {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect('/login');
});

it('shows the dashboard to an authenticated user', function () {
    $user = User::factory()->salesExecutive()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();
});
