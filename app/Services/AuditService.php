<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Support\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditService
{
    public const REDACTED = '[REDACTED]';

    /** Any key containing one of these fragments is redacted before storage. */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'token', 'secret', 'api_key', 'apikey', 'authorization',
        'remember', 'private_key', 'cookie', 'credential',
    ];

    public function log(
        AuditAction $action,
        string $module,
        ?Model $subject = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
    ): AuditLog {
        /** @var Request $request */
        $request = app('request');
        $route = $request->route();
        $fromHttp = $route !== null || ! app()->runningInConsole();

        return AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action->value,
            'module' => $module,
            'entity_type' => $subject ? class_basename($subject) : null,
            'entity_id' => $subject?->getKey(),
            'description' => $description,
            'old_values_json' => $oldValues === null ? null : $this->sanitize($oldValues),
            'new_values_json' => $newValues === null ? null : $this->sanitize($newValues),
            'ip_address' => $fromHttp ? $request->ip() : null,
            'user_agent' => $fromHttp ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'route' => $route ? ($route->getName() ?? $route->uri()) : ($fromHttp ? null : 'console'),
            'request_method' => $fromHttp ? $request->method() : null,
        ]);
    }

    /**
     * Returns [old, new] for the model's dirty attributes. Call BEFORE save().
     *
     * @param  array<string>  $ignore
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function dirtyDiff(Model $model, array $ignore = ['updated_at']): array
    {
        $new = collect($model->getDirty())->except($ignore)->all();
        $old = collect($new)->mapWithKeys(fn ($v, $key) => [$key => $model->getOriginal($key)])->all();

        return [$this->normalize($old), $this->normalize($new)];
    }

    public function sanitize(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $clean[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        if (SecretRedactor::isSensitiveKey($key)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(array $values): array
    {
        return array_map(function ($value) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            if ($value instanceof \BackedEnum) {
                return $value->value;
            }

            return $value;
        }, $values);
    }
}
