<?php

namespace App\Console\Commands;

use App\Services\SiteIcons\SiteIcons;
use Illuminate\Console\Command;

class RefreshSiteIcons extends Command
{
    protected $signature = 'vaultigen:refresh-site-icons
                            {--failed : Retry every failed host now instead of waiting for its back-off}
                            {--all : Re-fetch every host now, including those with a current icon}';

    protected $description = 'Queue missing, due, and stalled site icons for fetching and delete icons no entry uses';

    public function handle(SiteIcons $icons): int
    {
        if (! $icons->enabled()) {
            $this->warn('Site icons are turned off in Settings; nothing was queued.');
        }

        $result = $icons->maintain(
            requeueFailed: (bool) $this->option('failed'),
            requeueAll: (bool) $this->option('all'),
        );

        $this->info(sprintf(
            'Site icons: queued %d for refresh, recovered %d stalled fetches, backfilled %d hosts, deleted %d unused icons.',
            $result['queued'],
            $result['recovered'],
            $result['backfilled'],
            $result['deleted'],
        ));

        return self::SUCCESS;
    }
}
