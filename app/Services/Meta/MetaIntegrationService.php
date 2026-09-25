<?php

namespace App\Services\Meta;

use App\Enums\AuditAction;
use App\Enums\FacebookEventStatus;
use App\Enums\FacebookIntegrationStatus;
use App\Enums\MetaErrorCategory;
use App\Models\FacebookForm;
use App\Models\FacebookIntegration;
use App\Models\FacebookPage;
use App\Models\FacebookWebhookEvent;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Owns the connected Meta account: connect / disconnect, token health and the
 * dashboard summary. health() reads only local state — Meta is called solely
 * from check(), which runs on explicit admin action or the daily schedule.
 */
class MetaIntegrationService
{
    /** Without these, leads cannot be delivered or read. */
    public const REQUIRED_SCOPES = ['pages_show_list', 'pages_manage_metadata', 'pages_read_engagement', 'leads_retrieval', 'pages_manage_ads'];

    private const EXPIRY_WARNING_DAYS = 7;

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly AuditService $audit,
    ) {}

    public function current(): ?FacebookIntegration
    {
        return FacebookIntegration::query()->latest('id')->first();
    }

    /** Token used for account-level calls (page list, forms fallback). */
    public function userToken(): ?string
    {
        $integration = $this->current();

        return $integration?->isConnected() ? $integration->access_token_encrypted : null;
    }

    /**
     * Stores a freshly authorised account. `$grant` comes from MetaOAuthService
     * (or the Super Admin system-user token path) — never from a request body.
     *
     * @param  array{access_token: string, token_type: string, expires_at: ?\DateTimeInterface, data_access_expires_at?: ?\DateTimeInterface, user_id: ?string, user_name: ?string, scopes: array<int, string>}  $grant
     */
    public function connect(array $grant, User $actor): FacebookIntegration
    {
        return DB::transaction(function () use ($grant, $actor) {
            $integration = $this->current() ?? new FacebookIntegration;
            $isNew = ! $integration->exists;
            $missing = array_values(array_diff(self::REQUIRED_SCOPES, $grant['scopes']));

            $integration->forceFill([
                'app_id' => (string) config('meta.app_id'),
                'access_token_encrypted' => $grant['access_token'],
                'token_type' => $grant['token_type'],
                'token_expires_at' => $grant['expires_at'],
                'data_access_expires_at' => $grant['data_access_expires_at'] ?? null,
                'facebook_user_id' => $grant['user_id'],
                'facebook_user_name' => $grant['user_name'],
                'graph_version' => $this->graph->version(),
                'granted_scopes_json' => array_values($grant['scopes']),
                'missing_scopes_json' => $missing,
                'status' => $missing === [] ? FacebookIntegrationStatus::Connected : FacebookIntegrationStatus::PermissionMissing,
                'last_connected_at' => now(),
                'last_verified_at' => now(),
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => $missing === [] ? null : 'Required permission is missing: '.implode(', ', $missing),
                'disconnected_at' => null,
                'updated_by' => $actor->id,
            ]);
            if ($isNew) {
                $integration->created_by = $actor->id;
            }
            $integration->save();

            $this->audit->log(AuditAction::FacebookConnected, 'integrations', $integration, 'Meta account connected'.($grant['user_name'] ? " ({$grant['user_name']})" : ''), null, [
                'facebook_user_id' => $grant['user_id'],
                'token_type' => $grant['token_type'],
                'scopes' => array_values($grant['scopes']),
                'missing_scopes' => $missing,
                'graph_version' => $this->graph->version(),
            ], $actor->id);

            return $integration;
        });
    }

    /**
     * Stops processing: removes webhook subscriptions where possible, wipes all
     * stored tokens and marks the integration disconnected. Leads, enquiries,
     * events and audit history are preserved.
     */
    public function disconnect(User $actor, MetaPageService $pages): void
    {
        $integration = $this->current();
        if (! $integration) {
            return;
        }

        $unsubscribed = 0;
        foreach ($integration->pages()->where('is_subscribed', true)->get() as $page) {
            try {
                $pages->unsubscribe($page);
                $unsubscribed++;
            } catch (MetaApiException) {
                // Best effort: local state is cleared below either way.
            }
        }

        DB::transaction(function () use ($integration, $actor, $unsubscribed) {
            $integration->pages()->update([
                'page_access_token_encrypted' => null,
                'is_selected' => false,
                'is_subscribed' => false,
            ]);

            $integration->forceFill([
                'access_token_encrypted' => null,
                'status' => FacebookIntegrationStatus::Disconnected,
                'token_expires_at' => null,
                'disconnected_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->log(AuditAction::FacebookDisconnected, 'integrations', $integration, 'Meta account disconnected', null, ['pages_unsubscribed' => $unsubscribed], $actor->id);
        });
    }

    /**
     * Explicit health check against Meta: token validity/scopes and the
     * leadgen subscription of every receiving Page.
     *
     * @return array{ok: bool, message: string}
     */
    public function check(?User $actor, MetaPageService $pages): array
    {
        $integration = $this->current();
        if (! $integration?->isConnected()) {
            return ['ok' => false, 'message' => 'Meta is not connected.'];
        }

        try {
            $debug = $this->graph->get('debug_token', ['input_token' => $integration->access_token_encrypted], $this->graph->appAccessToken(), proof: false)['data'] ?? [];

            if (! ($debug['is_valid'] ?? false)) {
                throw MetaApiException::of(MetaErrorCategory::Authentication);
            }

            $scopes = array_values(array_map('strval', $debug['scopes'] ?? []));
            $missing = array_values(array_diff(self::REQUIRED_SCOPES, $scopes));

            $problems = [];
            foreach ($integration->pages()->receiving()->get() as $page) {
                if (! $pages->checkSubscription($page)) {
                    $problems[] = $page->page_name;
                }
            }

            $message = match (true) {
                $missing !== [] => 'Required permission is missing: '.implode(', ', $missing),
                $problems !== [] => 'Webhook subscription is inactive for: '.implode(', ', $problems),
                default => null,
            };

            $integration->forceFill([
                'granted_scopes_json' => $scopes,
                'missing_scopes_json' => $missing,
                'token_expires_at' => ! empty($debug['expires_at']) ? now()->setTimestamp((int) $debug['expires_at']) : null,
                'data_access_expires_at' => ! empty($debug['data_access_expires_at']) ? now()->setTimestamp((int) $debug['data_access_expires_at']) : null,
                'status' => $missing !== [] ? FacebookIntegrationStatus::PermissionMissing : ($problems !== [] ? FacebookIntegrationStatus::Error : FacebookIntegrationStatus::Connected),
                'last_verified_at' => now(),
                'last_error_at' => $message ? now() : null,
                'last_error_code' => $message ? ($missing !== [] ? 'permission' : 'subscription') : null,
                'last_error_message' => $message,
            ])->save();

            $this->audit->log(AuditAction::FacebookConnectionChecked, 'integrations', $integration, 'Meta connection checked: '.($message ?? 'healthy'), null, ['healthy' => $message === null], $actor?->id);

            return ['ok' => $message === null, 'message' => $message ?? 'Connection is healthy.'];
        } catch (MetaApiException $e) {
            $this->recordFailure($e);
            $this->audit->log(AuditAction::FacebookConnectionChecked, 'integrations', $integration, 'Meta connection check failed', null, ['category' => $e->category->value, 'error_code' => $e->code()], $actor?->id);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Reflects account-level failures (expired token, lost permission) in the status. */
    public function recordFailure(MetaApiException $e): void
    {
        $integration = $this->current();
        if (! $integration || $integration->status === FacebookIntegrationStatus::Disconnected) {
            return;
        }

        $status = match ($e->category) {
            MetaErrorCategory::Authentication => FacebookIntegrationStatus::NeedsReauthorization,
            MetaErrorCategory::Permission => FacebookIntegrationStatus::PermissionMissing,
            MetaErrorCategory::Configuration, MetaErrorCategory::PageUnavailable => FacebookIntegrationStatus::Error,
            default => null,
        };

        $integration->forceFill(array_filter([
            'status' => $status,
            'last_error_at' => now(),
            'last_error_code' => $e->code(),
            'last_error_message' => $e->getMessage(),
        ], fn ($v) => $v !== null))->save();
    }

    public function touchWebhook(): void
    {
        FacebookIntegration::query()->whereKey($this->current()?->id)->update(['last_webhook_at' => now()]);
    }

    /**
     * Local-only summary for the admin screen and dashboard widget.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $integration = $this->current();
        $status = $integration?->isConnected() ? $integration->status : FacebookIntegrationStatus::Disconnected;
        $expiresAt = $integration?->token_expires_at;
        $expiring = $expiresAt && $expiresAt->isBefore(now()->addDays(self::EXPIRY_WARNING_DAYS));

        $failed = FacebookWebhookEvent::query()->where('processing_status', FacebookEventStatus::Failed)->count();
        $failedRecent = FacebookWebhookEvent::query()->where('processing_status', FacebookEventStatus::Failed)->where('failed_at', '>=', now()->subDay())->count();
        $unsubscribed = FacebookPage::query()->receiving()->where('is_subscribed', false)->count();

        return [
            'configured' => $this->graph->isConfigured(),
            'webhook_configured' => filled(config('meta.webhook_verify_token')) && filled(config('meta.app_secret')),
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'connected' => (bool) $integration?->isConnected(),
            'account_name' => $integration?->facebook_user_name,
            'graph_version' => $integration?->graph_version ?? $this->graph->version(),
            'token_type' => $integration?->token_type,
            'token_valid' => $status === FacebookIntegrationStatus::Connected || $status === FacebookIntegrationStatus::PermissionMissing,
            'token_expires_at' => $expiresAt?->toIso8601String(),
            'token_expiring' => (bool) $expiring,
            'missing_scopes' => $integration?->missing_scopes_json ?? [],
            'pages_connected' => FacebookPage::query()->receiving()->count(),
            'pages_total' => FacebookPage::query()->count(),
            'pages_unsubscribed' => $unsubscribed,
            'forms_enabled' => FacebookForm::query()->where('is_enabled', true)->whereHas('page', fn ($q) => $q->receiving())->count(),
            'forms_total' => FacebookForm::query()->count(),
            'last_webhook_at' => $integration?->last_webhook_at?->toIso8601String(),
            'last_lead_at' => $integration?->last_lead_at?->toIso8601String(),
            'last_verified_at' => $integration?->last_verified_at?->toIso8601String(),
            'failed_events' => $failed,
            'failed_events_recent' => $failedRecent,
            'webhook_healthy' => $failedRecent === 0 && $unsubscribed === 0,
            'last_error' => $integration?->last_error_message,
            'last_error_at' => $integration?->last_error_at?->toIso8601String(),
        ];
    }
}
