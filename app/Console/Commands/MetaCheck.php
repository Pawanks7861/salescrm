<?php

namespace App\Console\Commands;

use App\Services\Meta\MetaIntegrationService;
use App\Services\Meta\MetaPageService;
use Illuminate\Console\Command;

class MetaCheck extends Command
{
    protected $signature = 'meta:check';

    protected $description = 'Verify the Meta token, permissions and Page webhook subscriptions';

    public function handle(MetaIntegrationService $integrations, MetaPageService $pages): int
    {
        if (! $integrations->current()?->isConnected()) {
            $this->warn('Meta is not connected.');

            return self::SUCCESS;
        }

        $result = $integrations->check(null, $pages);
        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
