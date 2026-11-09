<?php

use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, int>  $ages  Heartbeat ages in minutes by name.
 */
function role_health_heartbeats(array $ages): void
{
    foreach ($ages as $name => $minutes) {
        DB::table('operational_heartbeats')->insert(['name' => $name, 'observed_at' => now()->subMinutes($minutes)]);
    }
}

it('reports whether the role is healthy', function (string $role, array $ages, bool $healthy): void {
    $this->freezeTime();
    role_health_heartbeats($ages);

    $command = $this->artisan('vaultigen:health', ['role' => $role, '--grace' => 0]);

    $healthy ? $command->assertSuccessful() : $command->expectsOutputToContain('heartbeat is stale')->assertFailed();
})->with([
    'scheduler with a fresh heartbeat' => ['scheduler', ['scheduler' => 1], true],
    'scheduler with a stale heartbeat' => ['scheduler', ['scheduler' => 5], false],
    'scheduler that never ran' => ['scheduler', [], false],
    'worker that processed the probe' => ['worker', ['scheduler' => 1, 'queue' => 1], true],
    'worker that ignores a live scheduler' => ['worker', ['scheduler' => 1, 'queue' => 5], false],
    'worker while the scheduler is down' => ['worker', ['scheduler' => 10, 'queue' => 10], true],
]);

it('rejects an unknown role', function (): void {
    $this->artisan('vaultigen:health', ['role' => 'web'])->assertFailed();
});
