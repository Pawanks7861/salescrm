<?php

use App\Services\SettingService;
use App\Support\SettingDefinitions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('branding');
    $this->org = salesOrg();
    app(SettingService::class)->put('general.company_name', 'ABC Realty');
});

test('the login page is client branded with a fixed platform credit', function () {
    $response = $this->get('/login')->assertOk();

    $response->assertSee('<title inertia>ABC Realty | CRM</title>', false);
    $props = $response->viewData('page')['props'];
    expect($props['app']['company'])->toBe('ABC Realty')
        ->and($props['platform'])->toBe(['name' => 'Buildify360', 'url' => 'https://buildify360.com']);
});

test('signed-in pages share the same platform credit', function () {
    $props = $this->actingAs($this->org->rahul)->get('/dashboard')->viewData('page')['props'];

    expect($props['platform']['name'])->toBe('Buildify360');
});

test('the platform credit is not a client setting and cannot be changed through settings', function () {
    $keys = array_keys(SettingDefinitions::all());
    expect(collect($keys)->filter(fn ($k) => str_contains($k, 'platform') || str_contains($k, 'powered') || str_contains($k, 'buildify')))->toBeEmpty();

    $this->actingAs($this->org->super)->put('/admin/settings/general', ['settings' => ['general' => [
        'crm_name' => 'Sales CRM',
        'company_name' => 'ABC Realty',
        'timezone' => 'Asia/Kolkata',
        'date_format' => 'd M Y',
        'time_format' => 'h:i A',
        'currency' => 'INR',
        'platform_name' => 'Hidden',
        'powered_by' => '',
    ]]])->assertSessionHasNoErrors();

    $props = $this->actingAs($this->org->super)->get('/dashboard')->viewData('page')['props'];
    expect($props['platform']['name'])->toBe('Buildify360');
});

test('system emails use client branding with only a footer credit', function () {
    $this->actingAs($this->org->admin)->post('/admin/branding/logo', ['file' => UploadedFile::fake()->image('logo.png')]);

    $html = (string) (new ResetPassword('test-token'))->toMail($this->org->rahul)->render();

    expect($html)->toContain('ABC Realty')
        ->toContain('/branding/logo?v=')
        ->toContain('Powered by')
        ->toContain('https://buildify360.com')
        ->not->toContain('laravel.com/img')
        ->not->toContain('Regards,<br>'."\n".'Laravel');

    $header = substr($html, 0, strpos($html, 'Powered by'));
    expect(substr_count($html, 'Buildify360'))->toBe(1)
        ->and($header)->not->toContain('Buildify360');
});
