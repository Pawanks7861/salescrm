<?php

namespace App\Services\Meta;

use App\Enums\AuditAction;
use App\Enums\MetaErrorCategory;
use App\Jobs\SyncMetaFormLeads;
use App\Models\FacebookForm;
use App\Models\FacebookPage;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Instant Form metadata (upsert by page + form_id; names are display only),
 * enable/disable, and the queued "Sync recent leads" backfill.
 */
class MetaFormService
{
    private const MAX_PAGES_OF_RESULTS = 10;

    private const FORM_FIELDS = 'id,name,status,locale,questions{key,label,type}';

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly MetaPageService $pages,
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{total: int, new: int}
     *
     * @throws MetaApiException
     */
    public function sync(FacebookPage $page, ?User $actor): array
    {
        $token = $this->pages->pageToken($page);
        $rows = [];
        $after = null;

        for ($i = 0; $i < self::MAX_PAGES_OF_RESULTS; $i++) {
            $response = $this->graph->get("{$page->page_id}/leadgen_forms", array_filter(['fields' => self::FORM_FIELDS, 'limit' => 100, 'after' => $after]), $token);

            foreach ($response['data'] ?? [] as $row) {
                if (is_array($row) && preg_match('/^\d{1,64}$/', (string) ($row['id'] ?? '')) === 1) {
                    $rows[] = $row;
                }
            }

            $after = $response['paging']['cursors']['after'] ?? null;
            if (empty($response['paging']['next']) || ! $after) {
                break;
            }
        }

        return DB::transaction(function () use ($page, $rows, $actor) {
            $new = 0;
            foreach ($rows as $row) {
                [, $created] = $this->upsert($page, $row);
                $new += $created ? 1 : 0;
            }

            $this->audit->log(AuditAction::FacebookFormSynced, 'integrations', $page, "Forms synced for Page \"{$page->page_name}\"", null, ['page_id' => $page->page_id, 'forms' => count($rows), 'new' => $new], $actor?->id);

            return ['total' => count($rows), 'new' => $new];
        });
    }

    /**
     * Fetches and registers a single form (used when a lead arrives for a form
     * the CRM has not synced yet).
     *
     * @throws MetaApiException
     */
    public function syncOne(FacebookPage $page, string $formId): FacebookForm
    {
        if (preg_match('/^\d{1,64}$/', $formId) !== 1) {
            throw MetaApiException::of(MetaErrorCategory::Malformed, 'Invalid form id.');
        }

        $row = $this->graph->get($formId, ['fields' => self::FORM_FIELDS], $this->pages->pageToken($page));

        if ((string) ($row['id'] ?? '') !== $formId) {
            throw MetaApiException::of(MetaErrorCategory::Malformed, 'Meta returned a different form.');
        }

        [$form, $created] = $this->upsert($page, $row);

        if ($created) {
            $this->audit->log(AuditAction::FacebookFormSynced, 'integrations', $form, "New form \"{$form->form_name}\" discovered from an incoming lead", null, ['form_id' => $formId, 'enabled' => $form->is_enabled], null);
        }

        return $form;
    }

    /** @return array{0: FacebookForm, 1: bool} */
    public function upsert(FacebookPage $page, array $row): array
    {
        $form = FacebookForm::query()->where('facebook_page_id', $page->id)->where('form_id', (string) $row['id'])->first()
            ?? (new FacebookForm)->forceFill(['facebook_page_id' => $page->id, 'form_id' => (string) $row['id']]);
        $created = ! $form->exists;

        $form->forceFill([
            'form_name' => mb_substr((string) ($row['name'] ?? 'Form '.$row['id']), 0, 191),
            'status' => isset($row['status']) ? mb_substr((string) $row['status'], 0, 30) : null,
            'locale' => isset($row['locale']) ? mb_substr((string) $row['locale'], 0, 20) : null,
            'questions_json' => $this->questions($row['questions'] ?? []),
            'last_synced_at' => now(),
        ]);

        if ($created) {
            $form->is_enabled = (bool) $this->settings->get('facebook.auto_enable_new_forms', true);
        }

        $form->save();

        return [$form, $created];
    }

    /** @param array{is_enabled?: bool, lead_source_id?: ?int} $data */
    public function update(FacebookForm $form, array $data, User $actor): void
    {
        $form->forceFill(collect($data)->only(['is_enabled', 'lead_source_id'])->all());
        [$old, $new] = $this->audit->dirtyDiff($form);

        if ($new === []) {
            return;
        }

        $form->save();
        $this->audit->log(AuditAction::FacebookFormUpdated, 'integrations', $form, "Form \"{$form->form_name}\" updated", $old, $new, $actor->id);
    }

    public function requestBackfill(FacebookForm $form, int $days, User $actor): void
    {
        $days = max(1, min($days, (int) config('meta.backfill_max_days', 90)));

        SyncMetaFormLeads::dispatch($form->id, $days)->onQueue(config('meta.queue'));

        $this->audit->log(AuditAction::FacebookLeadsSyncRequested, 'integrations', $form, "Sync of the last {$days} day(s) of leads requested for form \"{$form->form_name}\"", null, ['form_id' => $form->form_id, 'days' => $days], $actor->id);
    }

    /**
     * Pages through the form's leads created in the last `$days` days.
     *
     * @return \Generator<int, array> raw Meta lead objects
     *
     * @throws MetaApiException
     */
    public function recentLeads(FacebookForm $form, int $days, int $maxPages = 20): \Generator
    {
        $token = $this->pages->pageToken($form->page);
        $after = null;

        for ($i = 0; $i < $maxPages; $i++) {
            $response = $this->graph->get("{$form->form_id}/leads", array_filter([
                'fields' => MetaLeadService::FIELDS,
                'limit' => 100,
                'after' => $after,
                'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => now()->subDays($days)->getTimestamp()]]),
            ]), $token);

            foreach ($response['data'] ?? [] as $lead) {
                if (is_array($lead)) {
                    yield $lead;
                }
            }

            $after = $response['paging']['cursors']['after'] ?? null;
            if (empty($response['paging']['next']) || ! $after) {
                return;
            }
        }
    }

    /**
     * Lead source for a form: explicit form override, else Instagram for
     * Instagram submissions (setting), else Facebook. Uses seeded sources only.
     */
    public function sourceFor(?FacebookForm $form, ?string $platform): ?int
    {
        if ($form?->lead_source_id && LeadSource::query()->whereKey($form->lead_source_id)->where('is_active', true)->exists()) {
            return $form->lead_source_id;
        }

        if (strtolower((string) $platform) === 'ig' && $this->settings->get('facebook.use_instagram_source', true)) {
            $instagram = LeadSource::query()->where('slug', 'instagram')->where('is_active', true)->value('id');
            if ($instagram) {
                return (int) $instagram;
            }
        }

        $facebook = LeadSource::query()->where('slug', 'facebook')->value('id');

        return $facebook ? (int) $facebook : null;
    }

    /** @return array<int, array{key: string, label: string, type: ?string}> */
    private function questions(mixed $questions): array
    {
        $rows = is_array($questions['data'] ?? null) ? $questions['data'] : (is_array($questions) ? $questions : []);

        return collect($rows)
            ->filter(fn ($q) => is_array($q) && is_string($q['key'] ?? null) && $q['key'] !== '')
            ->take((int) config('meta.max_fields', 100))
            ->map(fn ($q) => [
                'key' => mb_substr($q['key'], 0, 100),
                'label' => mb_substr((string) ($q['label'] ?? $q['key']), 0, 191),
                'type' => isset($q['type']) ? mb_substr((string) $q['type'], 0, 40) : null,
            ])
            ->values()
            ->all();
    }
}
