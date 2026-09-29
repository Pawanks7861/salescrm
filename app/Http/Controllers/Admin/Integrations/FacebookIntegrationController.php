<?php

namespace App\Http\Controllers\Admin\Integrations;

use App\Enums\FacebookEventStatus;
use App\Http\Controllers\Controller;
use App\Models\FacebookForm;
use App\Models\FacebookPage;
use App\Models\FacebookWebhookEvent;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaFieldMappingService;
use App\Services\Meta\MetaFormService;
use App\Services\Meta\MetaGraphClient;
use App\Services\Meta\MetaIntegrationService;
use App\Services\Meta\MetaOAuthService;
use App\Services\Meta\MetaPageService;
use App\Services\SettingService;
use App\Support\SettingDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Admin → Integrations → Facebook (route-gated by facebook.manage).
 * Page load reads local state only; Meta is called only on explicit actions.
 * No token, secret or OAuth code is ever passed to the browser.
 */
class FacebookIntegrationController extends Controller
{
    public function __construct(
        private readonly MetaIntegrationService $integrations,
        private readonly MetaPageService $pages,
        private readonly MetaFormService $forms,
        private readonly MetaOAuthService $oauth,
        private readonly MetaGraphClient $graph,
        private readonly MetaFieldMappingService $mapping,
        private readonly SettingService $settings,
    ) {}

    public function index(Request $request): Response
    {
        $pages = FacebookPage::query()->whereHas('integration')->withCount('forms')->orderByDesc('is_selected')->orderBy('page_name')->get();
        $forms = FacebookForm::query()->with(['page:id,page_id,page_name,is_selected,is_active', 'mappings'])->whereHas('page')->orderBy('form_name')->get();

        $leadCounts = Lead::query()
            ->whereIn('facebook_form_id', $forms->pluck('form_id')->unique()->values())
            ->selectRaw('facebook_form_id, COUNT(*) as aggregate')
            ->groupBy('facebook_form_id')
            ->pluck('aggregate', 'facebook_form_id');

        return Inertia::render('Admin/Integrations/Facebook/Index', [
            'health' => $this->integrations->health(),
            'setup' => [
                'webhook_url' => route('webhooks.meta.receive'),
                'redirect_uri' => $this->oauth->redirectUri(),
                'app_configured' => $this->graph->isConfigured(),
                'verify_token_configured' => filled(config('meta.webhook_verify_token')),
                'graph_version' => $this->graph->version(),
                'queue' => config('meta.queue'),
                'manual_token_allowed' => $this->manualTokenAllowed($request),
            ],
            'pages' => $pages->map(fn (FacebookPage $page) => [
                'id' => $page->id,
                'page_id' => $page->page_id,
                'page_name' => $page->page_name,
                'category' => $page->category,
                'picture_url' => $page->picture_url,
                'is_selected' => $page->is_selected,
                'is_subscribed' => $page->is_subscribed,
                'is_active' => $page->is_active,
                'subscription_error' => $page->subscription_error,
                'forms_count' => $page->forms_count,
                'last_synced_at' => $page->last_synced_at?->toIso8601String(),
            ])->values(),
            'forms' => $forms->map(fn (FacebookForm $form) => [
                'id' => $form->id,
                'form_id' => $form->form_id,
                'form_name' => $form->form_name,
                'status' => $form->status,
                'page_name' => $form->page?->page_name,
                'page_receiving' => (bool) $form->page?->receivesLeads(),
                'is_enabled' => $form->is_enabled,
                'lead_source_id' => $form->lead_source_id,
                'leads_count' => (int) ($leadCounts[$form->form_id] ?? 0),
                'last_lead_at' => $form->last_lead_at?->toIso8601String(),
                'last_synced_at' => $form->last_synced_at?->toIso8601String(),
                'mapping' => $this->mapping->summary($form),
            ])->values(),
            'sources' => LeadSource::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'assignees' => User::query()->active()->orderBy('name')->get(['id', 'name']),
            'settings' => collect(SettingDefinitions::forGroup('facebook'))
                ->map(fn ($definition, $key) => [
                    'key' => $key,
                    'name' => substr($key, strlen('facebook.')),
                    'label' => $definition['label'],
                    'type' => $definition['type'],
                    'value' => $this->settings->get($key),
                ])->values(),
            'recentFailures' => FacebookWebhookEvent::query()
                ->where('processing_status', FacebookEventStatus::Failed)
                ->latest('failed_at')->limit(5)
                ->get(['id', 'leadgen_id', 'form_id', 'error_category', 'error_message', 'failed_at'])
                ->map(fn (FacebookWebhookEvent $e) => [
                    'id' => $e->id,
                    'leadgen_id' => $e->leadgen_id,
                    'form_id' => $e->form_id,
                    'category' => $e->error_category?->value,
                    'message' => $e->error_message,
                    'failed_at' => $e->failed_at?->toIso8601String(),
                ]),
        ]);
    }

    /** Starts OAuth. Inertia::location performs a full-page redirect to Meta. */
    public function connect(Request $request): SymfonyResponse
    {
        if (! $this->graph->isConfigured()) {
            return back()->with('error', 'Set META_APP_ID and META_APP_SECRET in the server environment first.');
        }

        return Inertia::location($this->oauth->authorizationUrl($request->session(), $request->boolean('rerequest')));
    }

    public function callback(Request $request): RedirectResponse
    {
        $index = redirect()->route('admin.integrations.facebook.index');

        // State is validated first, including on the error path, and is single-use.
        if (! $this->oauth->consumeState($request->session(), $request->query('state'))) {
            Log::warning('Meta OAuth callback rejected', ['reason' => 'invalid_state', 'user_id' => $request->user()->id]);

            return $index->with('error', 'The Meta authorization could not be verified. Please try connecting again.');
        }

        if ($request->filled('error')) {
            return $index->with('error', 'Meta authorization was cancelled or denied.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return $index->with('error', 'Meta did not return an authorization code.');
        }

        try {
            $integration = $this->integrations->connect($this->oauth->exchangeCode($code), $request->user());
        } catch (MetaApiException $e) {
            return $index->with('error', 'Meta connection failed: '.$e->getMessage());
        }

        try {
            $result = $this->pages->sync($integration, $request->user());
            $message = "Meta connected. {$result['total']} Page(s) found — choose which Pages should send leads.";
        } catch (MetaApiException $e) {
            $message = 'Meta connected, but Pages could not be loaded: '.$e->getMessage();
        }

        return $index->with($integration->missing_scopes_json ? 'error' : 'success', $integration->missing_scopes_json
            ? 'Meta connected, but required permissions were not granted: '.implode(', ', $integration->missing_scopes_json).'. Use "Reconnect" to grant them.'
            : $message);
    }

    /** Super Admin + META_ALLOW_MANUAL_TOKEN only: connect with a system-user token. */
    public function manualToken(Request $request): RedirectResponse
    {
        abort_unless($this->manualTokenAllowed($request), 403);

        $validated = $request->validate(['access_token' => ['required', 'string', 'min:20', 'max:1024', 'regex:/^[A-Za-z0-9_\-|.]+$/']]);

        try {
            $integration = $this->integrations->connect($this->oauth->describe($validated['access_token'], 'system_user'), $request->user());
            $this->pages->sync($integration, $request->user());
        } catch (MetaApiException $e) {
            return back()->with('error', 'Token rejected: '.$e->getMessage());
        }

        return back()->with('success', 'Meta connected with a system-user token.');
    }

    public function test(Request $request): RedirectResponse
    {
        $result = $this->integrations->check($request->user(), $this->pages);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->integrations->disconnect($request->user(), $this->pages);

        return back()->with('success', 'Meta disconnected. Existing leads, enquiries and history are kept.');
    }

    public function refreshPages(Request $request): RedirectResponse
    {
        $integration = $this->integrations->current();
        if (! $integration?->isConnected()) {
            return back()->with('error', 'Connect a Meta account first.');
        }

        try {
            $result = $this->pages->sync($integration, $request->user());
        } catch (MetaApiException $e) {
            $this->integrations->recordFailure($e);

            return back()->with('error', 'Could not refresh Pages: '.$e->getMessage());
        }

        return back()->with('success', "Pages refreshed: {$result['total']} found, {$result['new']} new.");
    }

    public function updatePage(Request $request, FacebookPage $facebookPage): RedirectResponse
    {
        $validated = $request->validate(['is_selected' => ['required', 'boolean']]);

        try {
            $this->pages->setReceiving($facebookPage, (bool) $validated['is_selected'], $request->user());
        } catch (MetaApiException $e) {
            return back()->with('error', "Could not update \"{$facebookPage->page_name}\": ".$e->getMessage());
        }

        return back()->with('success', $validated['is_selected']
            ? "\"{$facebookPage->page_name}\" now sends leads to the CRM."
            : "\"{$facebookPage->page_name}\" no longer sends leads.");
    }

    public function refreshForms(Request $request, FacebookPage $facebookPage): RedirectResponse
    {
        try {
            $result = $this->forms->sync($facebookPage, $request->user());
        } catch (MetaApiException $e) {
            return back()->with('error', 'Could not load forms: '.$e->getMessage());
        }

        return back()->with('success', "Forms refreshed for \"{$facebookPage->page_name}\": {$result['total']} found, {$result['new']} new.");
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (SettingDefinitions::forGroup('facebook') as $key => $definition) {
            $rules['settings.'.substr($key, strlen('facebook.'))] = $definition['rules'];
        }

        if ((int) $request->input('settings.auto_assign_user_id', 0) > 0) {
            $rules['settings.auto_assign_user_id'][] = Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at');
        }

        $values = [];
        foreach ($request->validate($rules)['settings'] ?? [] as $name => $value) {
            $values['facebook.'.$name] = $value;
        }

        $this->settings->updateGroup('facebook', $values);

        return back()->with('success', 'Facebook settings saved.');
    }

    private function manualTokenAllowed(Request $request): bool
    {
        return (bool) config('meta.allow_manual_token') && $request->user()->isSuperAdmin();
    }
}
