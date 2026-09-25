<?php

namespace App\Services\Meta;

use App\Models\Campaign;
use Illuminate\Database\QueryException;

/**
 * Upserts Meta campaigns into the generic `campaigns` table, keyed by the
 * stable external campaign id (platform "facebook" for all Meta placements,
 * including Instagram). Names are refreshed but never used as identity.
 */
class MetaCampaignService
{
    public const PLATFORM = 'facebook';

    public function resolve(array $lead, ?int $sourceId): ?Campaign
    {
        $externalId = (string) ($lead['campaign_id'] ?? '');
        if (preg_match('/^\d{1,64}$/', $externalId) !== 1) {
            return null;
        }

        $name = is_string($lead['campaign_name'] ?? null) && trim($lead['campaign_name']) !== ''
            ? mb_substr(trim($lead['campaign_name']), 0, 191)
            : null;

        $metadata = array_filter([
            'adset_id' => $this->id($lead['adset_id'] ?? null),
            'adset_name' => is_string($lead['adset_name'] ?? null) ? mb_substr($lead['adset_name'], 0, 191) : null,
        ]);

        try {
            return $this->upsert($externalId, $name, $sourceId, $metadata);
        } catch (QueryException) {
            // Concurrent insert of the same campaign: the unique (platform, external_id) row now exists.
            return $this->upsert($externalId, $name, $sourceId, $metadata);
        }
    }

    private function upsert(string $externalId, ?string $name, ?int $sourceId, array $metadata): Campaign
    {
        $campaign = Campaign::query()->firstOrNew(['platform' => self::PLATFORM, 'external_id' => $externalId]);

        $campaign->forceFill([
            'name' => $name ?? ($campaign->name ?: "Meta campaign {$externalId}"),
            'source_id' => $campaign->source_id ?? $sourceId,
            'is_active' => $campaign->exists ? $campaign->is_active : true,
            'metadata_json' => array_merge($campaign->metadata_json ?? [], ['meta' => true], $metadata ? ['last_adset' => $metadata] : []),
        ]);

        if ($campaign->isDirty()) {
            $campaign->save();
        }

        return $campaign;
    }

    private function id(mixed $value): ?string
    {
        return preg_match('/^\d{1,64}$/', (string) $value) === 1 ? (string) $value : null;
    }
}
