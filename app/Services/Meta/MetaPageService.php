<?php

namespace App\Services\Meta;

use App\Enums\AuditAction;
use App\Enums\MetaErrorCategory;
use App\Models\FacebookIntegration;
use App\Models\FacebookPage;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Page list sync (upsert by page_id) and leadgen webhook subscription. Pages
 * no longer returned by Meta are marked inactive, never deleted.
 */
class MetaPageService
{
    private const MAX_PAGES_OF_RESULTS = 10;

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly MetaIntegrationService $integrations,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{total: int, new: int, inactive: int}
     *
     * @throws MetaApiException
     */
    public function sync(FacebookIntegration $integration, ?User $actor): array
    {
        $token = $integration->isConnected() ? $integration->access_token_encrypted : null;
        if (! $token) {
            throw MetaApiException::of(MetaErrorCategory::Configuration, 'Connect a Meta account first.');
        }

        $rows = [];
        $after = null;
        for ($i = 0; $i < self::MAX_PAGES_OF_RESULTS; $i++) {
            $response = $this->graph->get('me/accounts', array_filter([
                'fields' => 'id,name,category,access_token,tasks,picture{url}',
                'limit' => 100,
                'after' => $after,
            ]), $token);

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

        return DB::transaction(function () use ($integration, $rows, $actor) {
            $seen = [];
            $new = 0;

            foreach ($rows as $row) {
                $page = FacebookPage::query()->where('facebook_integration_id', $integration->id)->where('page_id', (string) $row['id'])->first()
                    ?? (new FacebookPage)->forceFill(['facebook_integration_id' => $integration->id, 'page_id' => (string) $row['id']]);
                $new += $page->exists ? 0 : 1;

                $page->forceFill([
                    'page_name' => mb_substr((string) ($row['name'] ?? 'Page '.$row['id']), 0, 191),
                    'category' => isset($row['category']) ? mb_substr((string) $row['category'], 0, 100) : null,
                    'picture_url' => $this->pictureUrl($row),
                    'tasks_json' => array_values(array_filter((array) ($row['tasks'] ?? []), 'is_string')),
                    'page_access_token_encrypted' => is_string($row['access_token'] ?? null) ? $row['access_token'] : $page->page_access_token_encrypted,
                    'is_active' => true,
                    'last_synced_at' => now(),
                ])->save();

                $seen[] = $page->id;
            }

            $inactive = FacebookPage::query()->where('facebook_integration_id', $integration->id)->whereNotIn('id', $seen ?: [0])->where('is_active', true)->update(['is_active' => false]);

            $this->audit->log(AuditAction::FacebookPageSynced, 'integrations', $integration, 'Meta Pages synced', null, [
                'pages' => count($seen), 'new' => $new, 'inactive' => $inactive,
            ], $actor?->id);

            return ['total' => count($seen), 'new' => $new, 'inactive' => $inactive];
        });
    }

    /**
     * Turns lead delivery on/off for a Page. Turning it on subscribes the app
     * to the Page's `leadgen` field; turning it off removes only this app's
     * subscription. Imported leads are never touched.
     *
     * @throws MetaApiException when subscribing fails (local state records the error)
     */
    public function setReceiving(FacebookPage $page, bool $receive, User $actor): void
    {
        if (! $receive) {
            $error = null;
            try {
                if ($page->is_subscribed) {
                    $this->unsubscribe($page);
                }
            } catch (MetaApiException $e) {
                $error = $e->getMessage();
            }

            $page->forceFill(['is_selected' => false, 'is_subscribed' => false, 'subscription_error' => $error])->save();
            $this->audit->log(AuditAction::FacebookPageUnsubscribed, 'integrations', $page, "Stopped receiving leads from Page \"{$page->page_name}\"", null, ['page_id' => $page->page_id, 'unsubscribe_error' => $error !== null], $actor->id);

            return;
        }

        $page->forceFill(['is_selected' => true])->save();

        try {
            $this->subscribe($page);
        } catch (MetaApiException $e) {
            $page->forceFill(['is_subscribed' => false, 'subscription_error' => $e->getMessage(), 'last_subscription_check_at' => now()])->save();
            $this->audit->log(AuditAction::FacebookPageSubscribed, 'integrations', $page, "Subscribing Page \"{$page->page_name}\" to lead webhooks failed", null, ['page_id' => $page->page_id, 'subscribed' => false, 'category' => $e->category->value], $actor->id);
            $this->integrations->recordFailure($e);

            throw $e;
        }

        $this->audit->log(AuditAction::FacebookPageSubscribed, 'integrations', $page, "Receiving leads from Page \"{$page->page_name}\"", null, ['page_id' => $page->page_id, 'subscribed' => true], $actor->id);
    }

    /** @throws MetaApiException */
    public function subscribe(FacebookPage $page): void
    {
        $response = $this->graph->post("{$page->page_id}/subscribed_apps", ['subscribed_fields' => 'leadgen'], $this->pageToken($page));

        if (($response['success'] ?? false) !== true) {
            throw MetaApiException::of(MetaErrorCategory::Malformed, 'Meta did not confirm the Page subscription.');
        }

        $page->forceFill(['is_subscribed' => true, 'subscription_error' => null, 'last_subscription_check_at' => now()])->save();
    }

    /** @throws MetaApiException */
    public function unsubscribe(FacebookPage $page): void
    {
        $this->graph->delete("{$page->page_id}/subscribed_apps", [], $this->pageToken($page));
        $page->forceFill(['is_subscribed' => false, 'last_subscription_check_at' => now()])->save();
    }

    /** Reads whether this app is subscribed to the Page's leadgen field and records it. */
    public function checkSubscription(FacebookPage $page): bool
    {
        try {
            $apps = $this->graph->get("{$page->page_id}/subscribed_apps", [], $this->pageToken($page))['data'] ?? [];
            $subscribed = collect($apps)->contains(fn ($app) => (string) ($app['id'] ?? '') === (string) config('meta.app_id')
                && in_array('leadgen', (array) ($app['subscribed_fields'] ?? []), true));

            $page->forceFill(['is_subscribed' => $subscribed, 'subscription_error' => $subscribed ? null : 'Webhook subscription is inactive.', 'last_subscription_check_at' => now()])->save();

            return $subscribed;
        } catch (MetaApiException $e) {
            $page->forceFill(['is_subscribed' => false, 'subscription_error' => $e->getMessage(), 'last_subscription_check_at' => now()])->save();

            return false;
        }
    }

    /** @throws MetaApiException */
    public function pageToken(FacebookPage $page): string
    {
        $token = $page->page_access_token_encrypted;

        if (! is_string($token) || $token === '') {
            throw MetaApiException::of(MetaErrorCategory::PageUnavailable, 'No Page access token. Refresh Pages or reconnect Meta.');
        }

        return $token;
    }

    private function pictureUrl(array $row): ?string
    {
        $url = $row['picture']['data']['url'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') ? mb_substr($url, 0, 500) : null;
    }
}
