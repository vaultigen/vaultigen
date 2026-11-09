<?php

namespace App\Console\Commands;

use App\Services\Operations\MaintenanceWindow;
use App\Services\TrashLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PurgeTrash extends Command
{
    protected $signature = 'vaultigen:purge-trash {--limit=100}';

    protected $description = 'Purge vaults, trashed entries and folders past retention and prune old revisions';

    public function handle(TrashLifecycle $lifecycle, MaintenanceWindow $maintenance): int
    {
        if ($maintenance->active()) {
            $this->warn('A maintenance window is open; skipping trash purge.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $result = Cache::lock('vaultigen-purge-trash', 300)->get(fn (): array => $lifecycle->purgeDue($limit));

        if (! is_array($result)) {
            $this->warn('Another purge run is already in progress.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Purged %d entries, %d folders, %d attachments, %d revisions, %d vaults; released %d reservations; %d failures.',
            $result['entries'],
            $result['folders'],
            $result['attachments'],
            $result['revisions'],
            $result['vaults'],
            $result['reservations'],
            $result['failures'],
        ));

        return self::SUCCESS;
    }
}
