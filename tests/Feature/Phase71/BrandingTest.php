<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('branding');
    $this->org = salesOrg();
});

/** An upload whose MIME type is sniffed from its content, as with a real browser upload. */
function realUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'brand');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function loginProps(TestCase $test): array
{
    return $test->get('/login')->assertOk()->viewData('page')['props'];
}

test('admin uploads a logo; it is stored on the branding disk under a random name', function () {
    $this->actingAs($this->org->admin)
        ->post('/admin/branding/logo', ['file' => UploadedFile::fake()->image('Our Logo.png', 400, 120)])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Company logo updated.');

    $path = app(SettingService::class)->get('branding.logo_path');
    expect($path)->toMatch('#^logo/[A-Za-z0-9]{40}\.png$#')
        ->and($path)->not->toContain('Our Logo');
    Storage::disk('branding')->assertExists($path);
    expect(Storage::disk('local')->path(''))->not->toBe(Storage::disk('branding')->path(''));
});

test('jpg and webp logos are accepted', function (string $name) {
    $this->actingAs($this->org->admin)
        ->post('/admin/branding/logo', ['file' => UploadedFile::fake()->image($name)])
        ->assertSessionHasNoErrors();
})->with(['logo.jpg', 'logo.webp']);

test('invalid logo files are rejected by sniffed content, not extension', function (UploadedFile $file) {
    $this->actingAs($this->org->admin)
        ->post('/admin/branding/logo', ['file' => $file])
        ->assertSessionHasErrors('file');

    expect(app(SettingService::class)->get('branding.logo_path'))->toBe('')
        ->and(Storage::disk('branding')->allFiles())->toBe([]);
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'php renamed to png' => fn () => realUpload('logo.png', '<?php echo "x"; ?>'),
    'html renamed to jpg' => fn () => realUpload('logo.jpg', '<html><body onload="alert(1)"></body></html>'),
    'pdf' => fn () => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
    'too large' => fn () => UploadedFile::fake()->image('big.png')->size(2049),
]);

test('replacing the logo deletes the old file only after the new one is stored', function () {
    $this->actingAs($this->org->admin)->post('/admin/branding/logo', ['file' => UploadedFile::fake()->image('a.png')]);
    $old = app(SettingService::class)->get('branding.logo_path');

    // A rejected replacement keeps the current logo.
    $this->actingAs($this->org->admin)->post('/admin/branding/logo', ['file' => UploadedFile::fake()->create('b.pdf', 5, 'application/pdf')]);
    Storage::disk('branding')->assertExists($old);
    expect(app(SettingService::class)->get('branding.logo_path'))->toBe($old);

    $this->actingAs($this->org->admin)->post('/admin/branding/logo', ['file' => UploadedFile::fake()->image('c.png')]);
    $new = app(SettingService::class)->get('branding.logo_path');
    expect($new)->not->toBe($old);
    Storage::disk('branding')->assertExists($new);
    Storage::disk('branding')->assertMissing($old);
});

test('the login page shows the uploaded logo and company name, and falls back after removal', function () {
    app(SettingService::class)->put('general.company_name', 'Acme Homes Pvt Ltd');

    expect(loginProps($this)['app']['logo_url'])->toBeNull()
        ->and(loginProps($this)['app']['company'])->toBe('Acme Homes Pvt Ltd');

    $this->actingAs($this->org->admin)->post('/admin/branding/logo', ['file' => UploadedFile::fake()->image('logo.png')]);
    auth()->logout();

    $url = loginProps($this)['app']['logo_url'];
    expect($url)->toStartWith('/branding/logo?v=')
        ->and($url)->not->toContain('storage')->not->toContain('logo/');

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($this->org->admin)->delete('/admin/branding/logo')->assertSessionHas('success', 'Company logo removed.');
    auth()->logout();

    expect(loginProps($this)['app']['logo_url'])->toBeNull();
    $this->get('/branding/logo')->assertNotFound();
    expect(Storage::disk('branding')->allFiles())->toBe([]);
});

test('favicon upload changes the versioned favicon URL in the page head', function () {
    $this->get('/login')->assertSee('id="crm-favicon" href="/branding/favicon"', false);
    $this->get('/branding/favicon')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

    $this->actingAs($this->org->admin)
        ->post('/admin/branding/favicon', ['file' => UploadedFile::fake()->image('favicon.png', 64, 64)])
        ->assertSessionHasNoErrors();
    auth()->logout();

    $url = loginProps($this)['app']['favicon_url'];
    expect($url)->toStartWith('/branding/favicon?v=');
    $this->get('/login')->assertSee('href="'.e($url).'"', false);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('favicons must be PNG, ICO or WEBP', function () {
    $this->actingAs($this->org->admin)
        ->post('/admin/branding/favicon', ['file' => UploadedFile::fake()->image('favicon.jpg')])
        ->assertSessionHasErrors(['file' => 'The favicon must be a PNG, ICO or WEBP image.']);
});

test('sales executives and managers cannot change branding', function (string $who) {
    $user = $this->org->{$who};
    $this->actingAs($user)->post('/admin/branding/logo', ['file' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
    $this->actingAs($user)->delete('/admin/branding/logo')->assertForbidden();

    expect(Storage::disk('branding')->allFiles())->toBe([]);
})->with(['rahul', 'manager']);

test('branding changes are audited without file contents', function () {
    $this->actingAs($this->org->admin)->post('/admin/branding/logo', ['file' => UploadedFile::fake()->image('logo.png')]);
    $this->actingAs($this->org->admin)->post('/admin/branding/favicon', ['file' => UploadedFile::fake()->image('favicon.png')]);
    $this->actingAs($this->org->admin)->delete('/admin/branding/logo');
    $this->actingAs($this->org->admin)->delete('/admin/branding/favicon');

    foreach ([AuditAction::BrandingLogoUpdated, AuditAction::BrandingFaviconUpdated, AuditAction::BrandingLogoRemoved, AuditAction::BrandingFaviconRemoved] as $action) {
        $log = AuditLog::where('action', $action->value)->sole();
        expect($log->user_id)->toBe($this->org->admin->id)
            ->and(strlen(json_encode([$log->old_values_json, $log->new_values_json])))->toBeLessThan(300);
    }

    $updated = AuditLog::where('action', AuditAction::BrandingLogoUpdated->value)->sole()->new_values_json;
    expect(array_keys($updated))->toEqualCanonicalizing(['file', 'mime', 'size'])
        ->and($updated['mime'])->toBe('image/png');
});

test('changing the company name writes COMPANY_NAME_CHANGED', function () {
    $this->actingAs($this->org->admin)->put('/admin/settings/general', ['settings' => ['general' => [
        'crm_name' => 'Sales CRM',
        'company_name' => 'Acme Homes',
        'timezone' => 'Asia/Kolkata',
        'date_format' => 'd M Y',
        'time_format' => 'h:i A',
        'currency' => 'INR',
    ]]])->assertSessionHasNoErrors();

    $log = AuditLog::where('action', AuditAction::CompanyNameChanged->value)->sole();
    expect($log->new_values_json)->toMatchArray(['general.company_name' => 'Acme Homes']);
});
