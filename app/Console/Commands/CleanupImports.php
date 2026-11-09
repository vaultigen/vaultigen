<?php

namespace App\Console\Commands;

use App\Services\Imports\ImportRetention;
use App\Services\Operations\MaintenanceWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CleanupImports extends Command
{
    protected $signature = 'vaultigen:cleanup-imports {--limit=200}';

    protected $description = 'Cancel abandoned import uploads, delete runs past retention, and cap failure lists';

    public function handle(ImportRetention $retention, MaintenanceWindow $maintenance): int
    {
        if ($maintenance->active()) {
            $this->warn('A maintenance window is open; skipping import cleanup.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $result = Cache::lock('vaultigen-cleanup-imports', 300)->get(fn (): array => $retention->purgeDue($limit));

        if (! is_array($result)) {
            $this->warn('Another import cleanup run is already in progress.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Import cleanup complete: cancelled %d abandoned uploads, failed %d stalled runs, deleted %d retained runs, trimmed %d failure lists.',
            $result['staged'],
            $result['stalled'],
            $result['deleted'],
            $result['trimmed'],
        ));

        return self::SUCCESS;
    }
}
