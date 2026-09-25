<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\Setting;
use App\Support\SettingDefinitions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SettingService
{
    private const CACHE_KEY = 'settings.all';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function __construct(private readonly AuditService $audit) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return $default ?? (SettingDefinitions::all()[$key]['default'] ?? null);
    }

    /** @return array<string, mixed> */
    public function group(string $group): array
    {
        $values = [];

        foreach (SettingDefinitions::forGroup($group) as $key => $definition) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Persists a group of settings and audits every changed value.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateGroup(string $group, array $values): void
    {
        $definitions = SettingDefinitions::forGroup($group);

        DB::transaction(function () use ($definitions, $values) {
            foreach ($definitions as $key => $definition) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $old = $this->get($key);
                $new = $this->cast($values[$key], $definition['type']);

                if ($old === $new) {
                    continue;
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    [
                        'group' => SettingDefinitions::group($key),
                        'type' => $definition['type'],
                        'value' => $this->serialize($new, $definition['type']),
                    ],
                );

                $this->audit->log(
                    $key === 'general.company_name' ? AuditAction::CompanyNameChanged : AuditAction::SettingChanged,
                    'settings',
                    null,
                    "Setting \"{$definition['label']}\" changed",
                    [$key => $definition['type'] === 'encrypted' ? AuditService::REDACTED : $old],
                    [$key => $definition['type'] === 'encrypted' ? AuditService::REDACTED : $new],
                );
            }
        });

        $this->flush();
    }

    /**
     * Writes one defined setting without auditing; for callers (e.g.
     * BrandingService) that record their own, more specific audit entry.
     */
    public function put(string $key, mixed $value): void
    {
        $definition = SettingDefinitions::all()[$key] ?? throw new \InvalidArgumentException("Unknown setting {$key}");

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'group' => SettingDefinitions::group($key),
                'type' => $definition['type'],
                'value' => $this->serialize($this->cast($value, $definition['type']), $definition['type']),
            ],
        );

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        if (! Schema::hasTable('settings')) {
            return $this->loaded = [];
        }

        return $this->loaded = Cache::rememberForever(self::CACHE_KEY, function () {
            return Setting::query()->get()
                ->mapWithKeys(fn (Setting $s) => [$s->key => $this->deserialize($s->value, $s->type)])
                ->all();
        });
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => is_array($value) ? $value : (array) json_decode((string) $value, true),
            default => $value === null ? '' : (string) $value,
        };
    }

    private function serialize(mixed $value, string $type): ?string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            'encrypted' => $value === '' ? null : Crypt::encryptString($value),
            default => (string) $value,
        };
    }

    private function deserialize(?string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => $value === '1',
            'json' => $value ? json_decode($value, true) : [],
            'encrypted' => $value ? Crypt::decryptString($value) : '',
            default => (string) $value,
        };
    }
}
