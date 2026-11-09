<?php

namespace App\Console\Commands;

use App\Services\AuditRetention;
use App\Services\Operations\MaintenanceWindow;
use Illuminate\Console\Command;

class PruneAudit extends Command
{
    protected $signature = 'vaultigen:prune-audit';

    protected $description = 'Redact aged IP metadata and prune audit events after preserving the keyed checkpoint and chain anchor';

    public function handle(AuditRetention $retention, MaintenanceWindow $maintenance): int
    {
        if ($maintenance->active()) {
            $this->warn('A maintenance window is open; skipping audit retention.');

            return self::SUCCESS;
        }

        $result = $retention->prune();

        $this->info(sprintf(
            'Audit retention complete: redacted %d metadata rows, pruned %d events%s. Chain verified: %s.',
            $result['redacted'],
            $result['pruned'],
            $result['last_sequence'] === null ? '' : " (#{$result['first_sequence']}–#{$result['last_sequence']})",
            $result['verified'] ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
