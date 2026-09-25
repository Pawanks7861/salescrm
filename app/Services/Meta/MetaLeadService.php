<?php

namespace App\Services\Meta;

use App\Enums\MetaErrorCategory;
use App\Models\FacebookPage;

/**
 * Retrieves a single lead by leadgen_id with the Page token. Only the fields
 * the CRM needs are requested; the webhook itself never carries answers.
 */
class MetaLeadService
{
    public const FIELDS = 'id,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,field_data,is_organic,platform';

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly MetaPageService $pages,
    ) {}

    /** @throws MetaApiException */
    public function fetch(FacebookPage $page, string $leadgenId): array
    {
        try {
            $lead = $this->graph->get($leadgenId, ['fields' => self::FIELDS], $this->pages->pageToken($page));
        } catch (MetaApiException $e) {
            // An auth failure with a Page token means the Page grant was lost, not the whole account.
            if ($e->category === MetaErrorCategory::Authentication) {
                throw new MetaApiException(MetaErrorCategory::PageUnavailable, MetaErrorCategory::PageUnavailable->message(), $e->code(), $e->httpStatus);
            }

            throw $e;
        }

        return $this->validate($lead, $leadgenId);
    }

    /** @throws MetaApiException */
    public function validate(array $lead, string $leadgenId): array
    {
        if ((string) ($lead['id'] ?? '') !== $leadgenId || ! is_array($lead['field_data'] ?? null)) {
            throw MetaApiException::of(MetaErrorCategory::Malformed, 'Meta returned an incomplete lead.');
        }

        return $lead;
    }
}
