<?php

namespace App\Console\Commands;

use App\Models\FacebookPage;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaFormService;
use App\Services\Meta\MetaIntegrationService;
use App\Services\Meta\MetaPageService;
use Illuminate\Console\Command;

class MetaSync extends Command
{
    protected $signature = 'meta:sync {--pages : Only refresh Pages} {--forms : Only refresh forms of receiving Pages}';

    protected $description = 'Refresh Meta Pages and lead forms from the connected account';

    public function handle(MetaIntegrationService $integrations, MetaPageService $pages, MetaFormService $forms): int
    {
        $integration = $integrations->current();
        if (! $integration?->isConnected()) {
            $this->warn('Meta is not connected.');

            return self::SUCCESS;
        }

        try {
            if (! $this->option('forms')) {
                $result = $pages->sync($integration, null);
                $this->info("Pages: {$result['total']} found, {$result['new']} new, {$result['inactive']} no longer available.");
            }

            if (! $this->option('pages')) {
                foreach (FacebookPage::query()->where('facebook_integration_id', $integration->id)->receiving()->get() as $page) {
                    $result = $forms->sync($page, null);
                    $this->info("{$page->page_name}: {$result['total']} form(s), {$result['new']} new.");
                }
            }
        } catch (MetaApiException $e) {
            $integrations->recordFailure($e);
            $this->error("Meta sync failed ({$e->category->value}): {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
