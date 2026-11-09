<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecordHeartbeat extends Command
{
    protected $signature = 'vaultigen:heartbeat';

    protected $description = 'Record scheduler health and enqueue a worker health probe';

    public function handle(): int
    {
        DB::table('operational_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['observed_at' => now()]);
        QueueHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
