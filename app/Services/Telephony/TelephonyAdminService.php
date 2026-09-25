<?php

namespace App\Services\Telephony;

use App\Enums\AuditAction;
use App\Models\CallDisposition;
use App\Models\TelephonyIntegration;
use App\Models\TelephonyNumber;
use App\Models\TelephonyUser;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Leads\PhoneNormalizer;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Telephony configuration (Admin → Integrations → Telephony). Every change is
 * audited; credentials are never accepted here (they live in the environment).
 */
class TelephonyAdminService
{
    public const SETTING_KEYS = [
        'telephony.require_disposition', 'telephony.require_disposition_unconnected', 'telephony.notify_missed_calls',
        'telephony.notes_edit_window_hours', 'telephony.recording_storage', 'telephony.recording_retention_days',
        'telephony.recording_notice_enabled', 'telephony.recording_notice_text', 'telephony.number_prefix',
        'telephony.event_retention_days',
    ];

    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
        private readonly PhoneNormalizer $phones,
    ) {}

    /** `$data`: is_active, browser_calling_enabled, pstn_calling_enabled, recording_enabled, default_calling_mode, name */
    public function updateIntegration(array $data, User $actor): TelephonyIntegration
    {
        return DB::transaction(function () use ($data, $actor) {
            $integration = $this->telephony->integrationOrNew();
            $isNew = ! $integration->exists;

            $integration->forceFill(array_intersect_key($data, array_flip([
                'name', 'is_active', 'browser_calling_enabled', 'pstn_calling_enabled', 'recording_enabled', 'default_calling_mode',
            ])));
            $integration->forceFill([$isNew ? 'created_by' : 'updated_by' => $actor->id, 'updated_by' => $actor->id]);

            [$old, $new] = $this->audit->dirtyDiff($integration, ['updated_at', 'created_at', 'updated_by', 'created_by']);
            $integration->save();

            if ($isNew || $new !== []) {
                $this->audit->log(AuditAction::TelephonyConfigurationChanged, 'telephony', $integration, 'Telephony configuration updated', $isNew ? null : ($old ?: null), $new ?: null, $actor->id);
            }

            return $integration;
        });
    }

    /** @param array<string, mixed> $values keys from SETTING_KEYS */
    public function updateSettings(array $values, User $actor): void
    {
        $values = array_intersect_key($values, array_flip(self::SETTING_KEYS));
        if ($values === []) {
            return;
        }

        $old = collect(array_keys($values))->mapWithKeys(fn ($k) => [$k => $this->settings->get($k)])->all();
        $this->settings->updateGroup('telephony', $values);

        $new = collect(array_keys($values))->mapWithKeys(fn ($k) => [$k => $this->settings->get($k)])->all();
        $changedOld = array_filter($old, fn ($v, $k) => $v !== $new[$k], ARRAY_FILTER_USE_BOTH);
        if ($changedOld !== []) {
            $this->audit->log(AuditAction::TelephonyConfigurationChanged, 'telephony', null, 'Telephony call & recording settings updated', $changedOld, array_intersect_key($new, $changedOld), $actor->id);
        }
    }

    public function saveNumber(?TelephonyNumber $number, array $data, User $actor): TelephonyNumber
    {
        $integration = $this->requireIntegration($actor);
        $normalized = $this->phones->normalize($data['phone_number'] ?? null);
        if (! $normalized) {
            throw ValidationException::withMessages(['phone_number' => 'Enter a valid phone number.']);
        }

        $duplicate = TelephonyNumber::query()->where('integration_id', $integration->id)->where('normalized_number', $normalized)
            ->when($number, fn ($q) => $q->whereKeyNot($number->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['phone_number' => 'This number is already configured.']);
        }

        return DB::transaction(function () use ($number, $data, $actor, $integration, $normalized) {
            $number ??= new TelephonyNumber;
            $number->forceFill([
                'integration_id' => $integration->id,
                'provider_number_id' => $data['provider_number_id'] ?? $number->provider_number_id,
                'phone_number' => trim((string) $data['phone_number']),
                'normalized_number' => $normalized,
                'display_name' => $data['display_name'],
                'number_type' => $data['number_type'] ?? 'virtual',
                'supports_inbound' => (bool) ($data['supports_inbound'] ?? true),
                'supports_outbound' => (bool) ($data['supports_outbound'] ?? true),
                'supports_webrtc' => (bool) ($data['supports_webrtc'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_default' => (bool) ($data['is_default'] ?? false),
            ]);
            $isNew = ! $number->exists;
            [$old, $new] = $this->audit->dirtyDiff($number, ['updated_at', 'created_at']);
            $number->save();

            if ($number->is_default) {
                TelephonyNumber::query()->where('integration_id', $integration->id)->whereKeyNot($number->id)->update(['is_default' => false]);
            }

            if ($isNew || $new !== []) {
                $this->audit->log(AuditAction::TelephonyConfigurationChanged, 'telephony', $number,
                    ($isNew ? 'Telephony number added: ' : 'Telephony number updated: ').$number->display_name, $isNew ? null : ($old ?: null), $new ?: null, $actor->id);
            }

            return $number;
        });
    }

    public function saveAgent(?TelephonyUser $agent, array $data, User $actor): TelephonyUser
    {
        $integration = $this->requireIntegration($actor);
        $user = User::query()->findOrFail((int) $data['user_id']);

        $exists = TelephonyUser::query()->where('integration_id', $integration->id)->where('user_id', $user->id)
            ->when($agent, fn ($q) => $q->whereKeyNot($agent->id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['user_id' => "{$user->name} already has a calling account."]);
        }

        $phone = trim((string) ($data['registered_phone'] ?? '')) ?: null;
        $normalized = $phone ? $this->phones->normalize($phone) : null;
        if ($phone && ! $normalized) {
            throw ValidationException::withMessages(['registered_phone' => 'Enter a valid phone number.']);
        }

        return DB::transaction(function () use ($agent, $data, $actor, $integration, $user, $phone, $normalized) {
            $agent ??= new TelephonyUser;
            $isNew = ! $agent->exists;
            $agent->forceFill([
                'integration_id' => $integration->id,
                'user_id' => $user->id,
                'provider_user_id' => $data['provider_user_id'] ?? null,
                'provider_agent_id' => $data['provider_agent_id'] ?? null,
                'provider_sip_username' => $data['provider_sip_username'] ?? null,
                'registered_phone' => $phone,
                'registered_phone_normalized' => $normalized,
                'calling_mode' => $data['calling_mode'] ?? 'pstn',
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                $isNew ? 'created_by' : 'updated_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            [$old, $new] = $this->audit->dirtyDiff($agent, ['updated_at', 'created_at', 'updated_by', 'created_by']);
            $agent->save();

            if ($isNew || $new !== []) {
                $this->audit->log(AuditAction::TelephonyUserChanged, 'telephony', $agent,
                    ($isNew ? 'Calling account created for ' : 'Calling account updated for ').$user->name, $isNew ? null : ($old ?: null), $new ?: null, $actor->id);
            }

            return $agent;
        });
    }

    public function recordHealth(User $actor): array
    {
        $integration = $this->requireIntegration($actor);
        $health = $this->telephony->provider()->healthCheck();

        $integration->forceFill([
            'last_health_check_at' => now(),
            'last_health_status' => $health->status,
        ]);
        if (! $health->healthy) {
            $integration->forceFill(['last_error' => mb_substr((string) $health->message, 0, 500), 'last_error_at' => now()]);
        }
        $integration->save();

        return ['healthy' => $health->healthy, 'status' => $health->status, 'message' => $health->message];
    }

    /** @return int numbers added */
    public function syncNumbers(User $actor): int
    {
        $integration = $this->requireIntegration($actor);
        $added = 0;

        foreach ($this->telephony->provider()->getNumbers() as $remote) {
            $normalized = $this->phones->normalize($remote->phoneNumber);
            if (! $normalized || TelephonyNumber::query()->where('integration_id', $integration->id)->where('normalized_number', $normalized)->exists()) {
                continue;
            }

            $this->saveNumber(null, [
                'phone_number' => $remote->phoneNumber,
                'display_name' => $remote->displayName ?: $remote->phoneNumber,
                'provider_number_id' => $remote->providerNumberId,
                'number_type' => $remote->numberType,
                'is_active' => false,
            ], $actor);
            $added++;
        }

        return $added;
    }

    /** Dispositions are never deleted (calls reference them); deactivate instead. */
    public function saveDisposition(?CallDisposition $disposition, array $data, User $actor): CallDisposition
    {
        return DB::transaction(function () use ($disposition, $data, $actor) {
            $isNew = $disposition === null;
            $disposition ??= new CallDisposition;

            $fill = [
                'name' => trim((string) $data['name']),
                'color' => $data['color'] ?? 'slate',
                'is_contact' => (bool) ($data['is_contact'] ?? true),
                'requires_note' => (bool) ($data['requires_note'] ?? false),
                'requires_next_action' => (bool) ($data['requires_next_action'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ];
            if ($isNew) {
                $base = Str::slug($fill['name'], '_') ?: 'disposition';
                $slug = $base;
                for ($i = 2; CallDisposition::query()->where('slug', $slug)->exists(); $i++) {
                    $slug = "{$base}_{$i}";
                }
                $fill += ['slug' => $slug, 'is_system' => false, 'sort_order' => (int) CallDisposition::query()->max('sort_order') + 1];
            }
            $disposition->forceFill($fill);

            [$old, $new] = $this->audit->dirtyDiff($disposition, ['updated_at', 'created_at']);
            $disposition->save();

            if ($isNew || $new !== []) {
                $this->audit->log(AuditAction::TelephonyConfigurationChanged, 'telephony', $disposition,
                    ($isNew ? 'Call disposition added: ' : 'Call disposition updated: ').$disposition->name, $isNew ? null : ($old ?: null), $new ?: null, $actor->id);
            }

            return $disposition;
        });
    }

    private function requireIntegration(User $actor): TelephonyIntegration
    {
        $integration = $this->telephony->integration();

        return $integration ?? $this->updateIntegration([], $actor);
    }
}
