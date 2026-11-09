<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckRoleHealth extends Command
{
    protected $signature = 'vaultigen:health {role : worker or scheduler} {--grace=180 : Seconds after container start before a missing heartbeat counts}';

    protected $description = 'Container health check for the queue worker and scheduler roles';

    /**
     * The scheduler is healthy when it recorded a heartbeat recently. The
     * worker is unhealthy only when the scheduler is alive but the worker has
     * not processed its heartbeat job, so a stopped scheduler does not also
     * mark a working queue as failed. An unreachable database throws, which
     * fails the check with a non-zero exit as well.
     */
    public function handle(): int
    {
        $role = (string) $this->argument('role');
        if (! in_array($role, ['worker', 'scheduler'], true)) {
            $this->error('Choose "worker" or "scheduler".');

            return self::FAILURE;
        }

        $fresh = fn (string $name): bool => DB::table('operational_heartbeats')
            ->where('name', $name)->where('observed_at', '>=', now()->subMinutes(3))->exists();
        $healthy = $role === 'scheduler' ? $fresh('scheduler') : ($fresh('queue') || ! $fresh('scheduler'));

        if ($healthy || $this->startedWithin((int) $this->option('grace'))) {
            return self::SUCCESS;
        }
        $this->error(ucfirst($role).' heartbeat is stale.');

        return self::FAILURE;
    }

    private function startedWithin(int $seconds): bool
    {
        $started = @filectime('/proc/1');

        return $seconds > 0 && $started !== false && time() - $started < $seconds;
    }
}
