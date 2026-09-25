<?php

namespace App\Console\Commands;

use App\Enums\FacebookIntegrationStatus;
use App\Models\FacebookForm;
use App\Models\FacebookIntegration;
use App\Models\FacebookPage;
use App\Models\FacebookWebhookEvent;
use App\Services\Meta\MetaLeadIngestionService;
use App\Services\Meta\MetaWebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * LOCAL/TESTING ONLY. Feeds a synthetic Meta lead through the real event
 * ledger + ingestion pipeline without calling Meta. There is deliberately no
 * HTTP equivalent: every HTTP delivery must pass signature validation.
 */
class MetaTestLead extends Command
{
    protected $signature = 'meta:test-lead
        {--setup : Create a local test integration, Page and form if missing}
        {--page=100000000000001 : Page id}
        {--form=200000000000001 : Form id}
        {--leadgen= : leadgen_id (random when omitted; reuse one to test idempotency)}
        {--name=Amit Desai : Full name}
        {--email= : Email}
        {--phone=+919812345678 : Phone}
        {--city=Pune : City}
        {--platform=fb : fb or ig}
        {--campaign=120210000000099 : Campaign id}';

    protected $description = 'Ingest a synthetic Meta lead locally (disabled outside local/testing)';

    public function handle(MetaWebhookService $webhooks, MetaLeadIngestionService $ingestion): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('meta:test-lead is only available when APP_ENV is local or testing.');

            return self::FAILURE;
        }

        $pageId = (string) $this->option('page');
        $formId = (string) $this->option('form');

        if ($this->option('setup')) {
            $this->setup($pageId, $formId);
        }

        $leadgenId = (string) ($this->option('leadgen') ?: '9'.random_int(10_000_000_000, 99_999_999_999));
        [$event, $outcome] = $webhooks->register([
            'leadgen_id' => $leadgenId,
            'page_id' => $pageId,
            'form_id' => $formId,
            'created_time' => now()->timestamp,
        ], FacebookWebhookEvent::ORIGIN_TEST, dispatch: false);

        if (! $event) {
            $this->error('Invalid page/leadgen id.');

            return self::FAILURE;
        }
        if ($outcome !== 'accepted') {
            $this->warn("Event {$leadgenId}: {$outcome} (status {$event->fresh()->processing_status->value}, reason ".($event->fresh()->error_code ?? '-').').');

            return self::SUCCESS;
        }

        $email = $this->option('email') ?: Str::slug((string) $this->option('name'), '.').'.'.Str::lower(Str::random(4)).'@example.test';

        $result = $ingestion->process($event, [
            'id' => $leadgenId,
            'created_time' => now()->toIso8601String(),
            'form_id' => $formId,
            'campaign_id' => (string) $this->option('campaign'),
            'campaign_name' => 'Local Test Campaign',
            'adset_id' => '120210000000199',
            'adset_name' => 'Local Test Ad Set',
            'ad_id' => '120210000000299',
            'ad_name' => 'Local Test Ad',
            'platform' => $this->option('platform') === 'ig' ? 'ig' : 'fb',
            'is_organic' => false,
            'field_data' => [
                ['name' => 'full_name', 'values' => [(string) $this->option('name')]],
                ['name' => 'email', 'values' => [$email]],
                ['name' => 'phone_number', 'values' => [(string) $this->option('phone')]],
                ['name' => 'city', 'values' => [(string) $this->option('city')]],
                ['name' => 'what_is_your_budget?', 'values' => ['25-50 lakh']],
            ],
        ]);

        $this->info("Event {$leadgenId}: {$result['status']}".($result['lead_id'] ? " → lead #{$result['lead_id']}" : ''));

        return self::SUCCESS;
    }

    private function setup(string $pageId, string $formId): void
    {
        $integration = FacebookIntegration::query()->latest('id')->first() ?? new FacebookIntegration;
        if (! $integration->exists) {
            $integration->forceFill([
                'name' => 'Local test integration',
                'token_type' => 'test',
                'status' => FacebookIntegrationStatus::Connected,
                'facebook_user_name' => 'Local Test',
                'graph_version' => config('meta.graph_version'),
                'last_connected_at' => now(),
            ])->save();
        }

        $page = FacebookPage::query()->where('facebook_integration_id', $integration->id)->where('page_id', $pageId)->first()
            ?? (new FacebookPage)->forceFill(['facebook_integration_id' => $integration->id, 'page_id' => $pageId]);
        $page->forceFill(['page_name' => $page->page_name ?: 'Local Test Page', 'is_selected' => true, 'is_subscribed' => true, 'is_active' => true])->save();

        $form = FacebookForm::query()->where('facebook_page_id', $page->id)->where('form_id', $formId)->first()
            ?? (new FacebookForm)->forceFill(['facebook_page_id' => $page->id, 'form_id' => $formId]);
        $form->forceFill([
            'form_name' => $form->form_name ?: 'Local Test Form',
            'status' => 'ACTIVE',
            'is_enabled' => $form->exists ? $form->is_enabled : true,
            'questions_json' => [
                ['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'EMAIL'],
                ['key' => 'phone_number', 'label' => 'Phone number', 'type' => 'PHONE'],
                ['key' => 'city', 'label' => 'City', 'type' => 'CITY'],
                ['key' => 'what_is_your_budget?', 'label' => 'What is your budget?', 'type' => 'CUSTOM'],
            ],
        ])->save();

        $this->info("Test setup ready: Page {$pageId}, form {$formId}.");
    }
}
