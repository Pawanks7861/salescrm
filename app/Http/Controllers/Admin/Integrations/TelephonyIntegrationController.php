<?php

namespace App\Http\Controllers\Admin\Integrations;

use App\Enums\CallEventStatus;
use App\Enums\TelephonyCallingMode;
use App\Http\Controllers\Controller;
use App\Models\CallDisposition;
use App\Models\CallEvent;
use App\Models\Role;
use App\Models\TelephonyNumber;
use App\Models\TelephonyUser;
use App\Models\User;
use App\Services\SettingService;
use App\Services\Telephony\TelephonyAdminService;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\TelephonyManager;
use App\Support\LeadColors;
use App\Support\Permissions;
use App\Support\SettingDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Integrations → Telephony (route-gated by call.configure).
 * Page load reads local state only — the provider is contacted only on the
 * explicit "Test connection" / "Sync numbers" actions. Credentials are never
 * accepted or displayed: only whether each one is configured.
 */
class TelephonyIntegrationController extends Controller
{
    public function __construct(
        private readonly TelephonyManager $telephony,
        private readonly TelephonyAdminService $admin,
        private readonly SettingService $settings,
    ) {}

    public function index(): Response
    {
        $integration = $this->telephony->integration();
        $driver = $this->telephony->driver();

        $numbers = $integration
            ? TelephonyNumber::query()->where('integration_id', $integration->id)->orderByDesc('is_default')->orderBy('display_name')->get()
            : collect();
        $agents = $integration
            ? TelephonyUser::query()->where('integration_id', $integration->id)->with('user:id,name,email,is_active')->get()->sortBy(fn ($a) => $a->user?->name)->values()
            : collect();

        return Inertia::render('Admin/Integrations/Telephony/Index', [
            'provider' => [
                'driver' => $driver,
                'is_fake' => $this->telephony->isFake(),
                'credentials' => $this->credentialFlags($driver),
                'status_callback_url' => route('webhooks.telephony.status', ['provider' => $driver]),
                'passthru_url' => route('webhooks.telephony.passthru', ['provider' => $driver]),
                'webhook_secret_configured' => filled(config("telephony.{$driver}.webhook_secret")),
                'ip_allowlist' => filled(config('telephony.exotel.webhook_allowed_ips')),
            ],
            'integration' => [
                'exists' => $integration !== null,
                'name' => $integration?->name ?? 'Exotel',
                'is_active' => (bool) $integration?->is_active,
                'browser_calling_enabled' => (bool) $integration?->browser_calling_enabled,
                'pstn_calling_enabled' => $integration ? (bool) $integration->pstn_calling_enabled : true,
                'recording_enabled' => $integration ? (bool) $integration->recording_enabled : true,
                'default_calling_mode' => $integration?->default_calling_mode ?? TelephonyCallingMode::Pstn->value,
            ],
            'health' => [
                'last_health_check_at' => $integration?->last_health_check_at?->toIso8601String(),
                'last_health_status' => $integration?->last_health_status,
                'last_error' => $integration?->last_error,
                'last_error_at' => $integration?->last_error_at?->toIso8601String(),
                'last_callback_at' => $integration?->last_callback_at?->toIso8601String(),
                'default_number' => $numbers->firstWhere('is_default', true)?->display_name,
                'agents_enabled' => $agents->where('is_enabled', true)->count(),
                'failed_events_24h' => CallEvent::query()->where('processing_status', CallEventStatus::Failed->value)->where('created_at', '>=', now()->subDay())->count(),
                'recent_errors' => CallEvent::query()->where('processing_status', CallEventStatus::Failed->value)->latest('id')->limit(5)
                    ->get(['id', 'event_type', 'error_message', 'created_at'])
                    ->map(fn (CallEvent $e) => ['id' => $e->id, 'type' => $e->event_type, 'error' => $e->error_message, 'at' => $e->created_at?->toIso8601String()]),
                'queue' => config('telephony.queue'),
                'queued_jobs' => $this->queuedJobs(),
            ],
            'numbers' => $numbers->map(fn (TelephonyNumber $n) => [
                'id' => $n->id,
                'phone_number' => $n->phone_number,
                'display_name' => $n->display_name,
                'number_type' => $n->number_type,
                'provider_number_id' => $n->provider_number_id,
                'supports_inbound' => $n->supports_inbound,
                'supports_outbound' => $n->supports_outbound,
                'supports_webrtc' => $n->supports_webrtc,
                'is_active' => $n->is_active,
                'is_default' => $n->is_default,
            ]),
            'agents' => $agents->map(fn (TelephonyUser $a) => [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'name' => $a->user?->name,
                'email' => $a->user?->email,
                'user_active' => (bool) $a->user?->is_active,
                'provider_user_id' => $a->provider_user_id,
                'provider_agent_id' => $a->provider_agent_id,
                'provider_sip_username' => $a->provider_sip_username,
                'registered_phone' => $a->registered_phone,
                'calling_mode' => $a->calling_mode?->value,
                'is_enabled' => $a->is_enabled,
                'last_registered_at' => $a->last_registered_at?->toIso8601String(),
            ]),
            'dispositions' => CallDisposition::query()->ordered()->withCount('calls')->get()
                ->map(fn (CallDisposition $d) => $d->only(['id', 'name', 'slug', 'color', 'is_contact', 'requires_note', 'requires_next_action', 'is_active', 'is_system', 'calls_count'])),
            'fields' => collect(SettingDefinitions::forGroup('telephony'))
                ->only(TelephonyAdminService::SETTING_KEYS)
                ->map(fn ($definition, $key) => [
                    'key' => $key,
                    'name' => substr($key, strlen('telephony.')),
                    'label' => $definition['label'],
                    'type' => $definition['type'],
                    'options' => $definition['options'] ?? null,
                    'value' => $this->settings->get($key),
                ])->values(),
            'permissions' => $this->permissionMatrix(),
            'options' => [
                'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
                'modes' => TelephonyCallingMode::options(),
                'colors' => LeadColors::ALL,
            ],
        ]);
    }

    public function updateIntegration(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'browser_calling_enabled' => ['boolean'],
            'pstn_calling_enabled' => ['boolean'],
            'recording_enabled' => ['boolean'],
            'default_calling_mode' => ['required', Rule::enum(TelephonyCallingMode::class)],
        ]);

        $this->admin->updateIntegration($data, $request->user());

        return back()->with('success', 'Telephony settings saved.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $definitions = collect(SettingDefinitions::forGroup('telephony'))->only(TelephonyAdminService::SETTING_KEYS);
        $rules = [];
        foreach ($definitions as $key => $definition) {
            $rules['settings.'.substr($key, strlen('telephony.'))] = $definition['rules'];
        }

        $validated = $request->validate($rules)['settings'] ?? [];
        $values = collect($validated)->mapWithKeys(fn ($v, $name) => ['telephony.'.$name => $v])->all();

        $this->admin->updateSettings($values, $request->user());

        return back()->with('success', 'Call and recording settings saved.');
    }

    public function storeNumber(Request $request): RedirectResponse
    {
        $number = $this->admin->saveNumber(null, $this->numberData($request), $request->user());

        return back()->with('success', "Number \"{$number->display_name}\" added.");
    }

    public function updateNumber(Request $request, TelephonyNumber $telephonyNumber): RedirectResponse
    {
        $this->admin->saveNumber($telephonyNumber, $this->numberData($request), $request->user());

        return back()->with('success', 'Number updated.');
    }

    public function storeAgent(Request $request): RedirectResponse
    {
        $agent = $this->admin->saveAgent(null, $this->agentData($request), $request->user());

        return back()->with('success', 'Calling account created for '.$agent->user->name.'.');
    }

    public function updateAgent(Request $request, TelephonyUser $telephonyUser): RedirectResponse
    {
        $this->admin->saveAgent($telephonyUser, $this->agentData($request), $request->user());

        return back()->with('success', 'Calling account updated.');
    }

    public function storeDisposition(Request $request): RedirectResponse
    {
        $disposition = $this->admin->saveDisposition(null, $this->dispositionData($request, null), $request->user());

        return back()->with('success', "Disposition \"{$disposition->name}\" added.");
    }

    public function updateDisposition(Request $request, CallDisposition $callDisposition): RedirectResponse
    {
        $this->admin->saveDisposition($callDisposition, $this->dispositionData($request, $callDisposition), $request->user());

        return back()->with('success', 'Disposition updated.');
    }

    public function health(Request $request): RedirectResponse
    {
        $result = $this->admin->recordHealth($request->user());

        return back()->with($result['healthy'] ? 'success' : 'error', $result['healthy'] ? 'Connection OK.' : 'Connection failed: '.($result['message'] ?: $result['status']));
    }

    public function syncNumbers(Request $request): RedirectResponse
    {
        try {
            $added = $this->admin->syncNumbers($request->user());
        } catch (TelephonyException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $added === 0 ? 'No new numbers found.' : "{$added} number(s) imported as inactive — review and activate them.");
    }

    private function numberData(Request $request): array
    {
        return $request->validate([
            'phone_number' => ['required', 'string', 'max:30'],
            'display_name' => ['required', 'string', 'max:100'],
            'number_type' => ['required', Rule::in(['virtual', 'toll_free', 'mobile', 'landline'])],
            'provider_number_id' => ['nullable', 'string', 'max:100'],
            'supports_inbound' => ['boolean'],
            'supports_outbound' => ['boolean'],
            'supports_webrtc' => ['boolean'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ]);
    }

    private function agentData(Request $request): array
    {
        return $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'provider_user_id' => ['nullable', 'string', 'max:100'],
            'provider_agent_id' => ['nullable', 'string', 'max:100'],
            'provider_sip_username' => ['nullable', 'string', 'max:150'],
            'registered_phone' => ['nullable', 'string', 'max:30'],
            'calling_mode' => ['required', Rule::enum(TelephonyCallingMode::class)],
            'is_enabled' => ['boolean'],
        ]);
    }

    private function dispositionData(Request $request, ?CallDisposition $disposition): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('call_dispositions', 'name')->ignore($disposition?->id)],
            'color' => ['required', Rule::in(LeadColors::ALL)],
            'is_contact' => ['boolean'],
            'requires_note' => ['boolean'],
            'requires_next_action' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    /** @return list<array{label: string, configured: bool}> */
    private function credentialFlags(string $driver): array
    {
        if ($driver !== 'exotel') {
            return [['label' => 'Local fake provider (no credentials)', 'configured' => true]];
        }

        return collect([
            'Account SID' => 'account_sid',
            'API key' => 'api_key',
            'API token' => 'api_token',
            'Subdomain' => 'subdomain',
            'Callback secret' => 'webhook_secret',
            'Default caller ID' => 'default_caller_id',
            'Browser calling access token' => 'webrtc_access_token',
        ])->map(fn ($key, $label) => ['label' => $label, 'configured' => filled(config("telephony.exotel.{$key}"))])->values()->all();
    }

    private function permissionMatrix(): array
    {
        $keys = [
            Permissions::CALL_VIEW, Permissions::CALL_VIEW_ALL, Permissions::CALL_MAKE,
            Permissions::CALL_RECEIVE, Permissions::CALL_MANUAL_DIAL, Permissions::CALL_RECORDING_LISTEN,
            Permissions::CALL_RECORDING_DOWNLOAD, Permissions::CALL_CONFIGURE,
        ];

        return [
            'keys' => $keys,
            'roles' => Role::query()->with('permissions:id,name')->orderBy('id')->get()->map(fn (Role $role) => [
                'name' => $role->name,
                'bypass' => $role->isSuperAdmin(),
                'granted' => $role->permissions->pluck('name')->intersect($keys)->values(),
            ]),
        ];
    }

    private function queuedJobs(): ?int
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        return DB::table(config('queue.connections.database.table', 'jobs'))->where('queue', config('telephony.queue'))->count();
    }
}
