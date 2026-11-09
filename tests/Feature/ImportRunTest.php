<?php

use App\Models\ImportRun;
use App\Models\User;

it('reports terminal statuses', function (string $status, bool $terminal, bool $running) {
    $run = new ImportRun(['status' => $status]);

    expect($run->isTerminal())->toBe($terminal)
        ->and($run->isRunning())->toBe($running);
})->with([
    'staging' => ['staging', false, false],
    'staged' => ['staged', false, false],
    'queued' => ['queued', false, true],
    'importing' => ['importing', false, true],
    'completed' => ['completed', true, false],
    'failed' => ['failed', true, false],
    'cancelled' => ['cancelled', true, false],
]);

it('casts its json columns and exposes the requester', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'counters' => ['vaults' => 1, 'folders' => 0, 'tags' => 0, 'entries' => 2, 'grants' => 0],
        'created_vault_ids' => ['a', 'b'],
        'failures' => [['source_id' => '1', 'title' => 'x', 'error_code' => 'unknown']],
        'started_at' => now(),
    ]);

    $run->refresh();

    expect($run->counters)->toBeArray()->and($run->counters['entries'])->toBe(2)
        ->and($run->created_vault_ids)->toBe(['a', 'b'])
        ->and($run->failures[0]['error_code'])->toBe('unknown')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->requestedBy?->id)->toBe($admin->id);
});
