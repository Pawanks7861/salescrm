<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company logo and favicon. Files live on the dedicated `branding` disk (never
 * the private attachments/recordings disk) under random names; the settings
 * table stores only the relative path. Assets are public brand material and
 * are served by BrandingController with a content-hash version for caching.
 */
class BrandingService
{
    public const DISK = 'branding';

    /** type => [setting key, allowed MIME => stored extension, max KB]. */
    public const TYPES = [
        'logo' => [
            'setting' => 'branding.logo_path',
            'mimes' => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'],
            'max_kb' => 2048,
        ],
        'favicon' => [
            'setting' => 'branding.favicon_path',
            'mimes' => ['image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/webp' => 'webp'],
            'max_kb' => 512,
        ],
    ];

    private const CONTENT_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];

    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    /** Validation rules for an upload of the given type (MIME is sniffed server-side). */
    public static function rules(string $type): array
    {
        $config = self::TYPES[$type];

        return ['required', 'file', 'mimetypes:'.implode(',', array_keys($config['mimes'])), 'max:'.$config['max_kb']];
    }

    /**
     * Stores the new file first, then points the setting at it and only then
     * deletes the previous file, so a failed upload never loses the current asset.
     */
    public function store(string $type, UploadedFile $file, User $actor): void
    {
        $config = self::TYPES[$type];
        $extension = $config['mimes'][$file->getMimeType()] ?? throw new \InvalidArgumentException('Unsupported file type.');
        $old = $this->path($type);
        $new = $type.'/'.Str::random(40).'.'.$extension;

        if (! Storage::disk(self::DISK)->putFileAs(dirname($new), $file, basename($new))) {
            throw new \RuntimeException('The file could not be stored.');
        }

        try {
            $this->settings->put($config['setting'], $new);
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($new);
            throw $e;
        }

        if ($old && $old !== $new) {
            Storage::disk(self::DISK)->delete($old);
        }

        $this->audit->log(
            $type === 'logo' ? AuditAction::BrandingLogoUpdated : AuditAction::BrandingFaviconUpdated,
            'settings', null, ($type === 'logo' ? 'Company logo' : 'Favicon').' updated',
            ['file' => $old ? basename($old) : null],
            ['file' => basename($new), 'mime' => $file->getMimeType(), 'size' => $file->getSize()],
            $actor->id,
        );
    }

    public function remove(string $type, User $actor): bool
    {
        $old = $this->path($type);
        if (! $old) {
            return false;
        }

        $this->settings->put(self::TYPES[$type]['setting'], '');
        Storage::disk(self::DISK)->delete($old);

        $this->audit->log(
            $type === 'logo' ? AuditAction::BrandingLogoRemoved : AuditAction::BrandingFaviconRemoved,
            'settings', null, ($type === 'logo' ? 'Company logo' : 'Favicon').' removed',
            ['file' => basename($old)], null, $actor->id,
        );

        return true;
    }

    /** Relative path of the current file, or null when none is configured / the file is missing. */
    public function path(string $type): ?string
    {
        $path = (string) $this->settings->get(self::TYPES[$type]['setting'], '');

        return $path !== '' && Storage::disk(self::DISK)->exists($path) ? $path : null;
    }

    /** Versioned public URL of the uploaded asset, or null (callers fall back to the default brand). */
    public function url(string $type): ?string
    {
        $path = $this->path($type);

        return $path ? route('branding.asset', ['type' => $type, 'v' => substr(md5($path), 0, 10)], false) : null;
    }

    /** The favicon always resolves: uploaded file or the built-in default icon. */
    public function faviconUrl(): string
    {
        return $this->url('favicon') ?? route('branding.asset', ['type' => 'favicon'], false);
    }

    /** Display name for login / sidebar: company name, else the CRM name. */
    public function companyName(): string
    {
        return trim((string) $this->settings->get('general.company_name', '')) ?: (string) $this->settings->get('general.crm_name', 'Sales CRM');
    }

    public function response(string $type, bool $versioned): Response
    {
        $path = $this->path($type);
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
        ];

        if ($path) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            return response(Storage::disk(self::DISK)->get($path), 200, [...$headers, 'Content-Type' => self::CONTENT_TYPES[$extension] ?? 'application/octet-stream']);
        }

        if ($type === 'favicon') {
            return response($this->defaultIcon(), 200, [...$headers, 'Content-Type' => 'image/svg+xml']);
        }

        abort(404);
    }

    /** Built-in favicon: the CRM initials on the brand gradient (no external asset). */
    private function defaultIcon(): string
    {
        $initials = collect(preg_split('/\s+/', trim($this->companyName())) ?: [])
            ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: 'C';
        $initials = htmlspecialchars($initials, ENT_XML1);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7C74FF"/><stop offset="1" stop-color="#5457E6"/></linearGradient></defs><rect width="64" height="64" rx="14" fill="url(#g)"/><text x="32" y="41" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="26" font-weight="700" fill="#fff">{$initials}</text></svg>
SVG;
    }
}
