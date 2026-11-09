<?php

use App\Models\BackupRun;
use App\Models\KeyRotationRun;
use App\Models\User;

it('exposes backup and rotation relationships', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $backup = BackupRun::factory()->create(['requested_by_user_id' => $admin->id]);
    expect($backup->requestedBy?->id)->toBe($admin->id);

    ['vault' => $vault] = vaultigen_entry_fixture();
    $rotation = KeyRotationRun::factory()->create([
        'vault_id' => $vault->id,
        'started_by_user_id' => $admin->id,
        'status' => KeyRotationRun::STATUS_PENDING,
    ]);

    expect($rotation->vault?->id)->toBe($vault->id)
        ->and($rotation->startedBy?->id)->toBe($admin->id)
        ->and($rotation->isTerminal())->toBeFalse();

    $completed = KeyRotationRun::factory()->create([
        'vault_id' => $vault->id,
        'started_by_user_id' => $admin->id,
        'status' => KeyRotationRun::STATUS_COMPLETED,
    ]);
    expect($completed->isTerminal())->toBeTrue();
});
