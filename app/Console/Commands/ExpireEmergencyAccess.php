<?php

namespace App\Console\Commands;

use App\Services\EmergencyAccess;
use Illuminate\Console\Command;

class ExpireEmergencyAccess extends Command
{
    protected $signature = 'vaultigen:expire-emergency-access';

    protected $description = 'Close emergency administrator sessions whose expiry has passed';

    public function handle(EmergencyAccess $emergency): int
    {
        $this->info(sprintf('Closed %d expired emergency access session(s).', $emergency->expireDue()));

        return self::SUCCESS;
    }
}
