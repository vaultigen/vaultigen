<?php

use App\Jobs\RunBackup;
use App\Jobs\RunKeyRotation;
use App\Models\BackupRun;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Services\Operations\BackupService;
use App\Services\Operations\KeyRotationService;
use App\Services\Operations\MaintenanceWindow;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['vaultigen.backup_root' => Storage::disk('local')->path('backups')]);
    Storage::fake('attachments');
    config([
        'vaultigen.backup_keyring' => json_encode([
            'active_key_id' => 'backup-test-v1',
            'keys' => ['backup-test-v1' => base64_encode(str_repeat('b', 32))],
        ], JSON_THROW_ON_ERROR),
    ]);
});

it('runs a backup job and ignores a terminal run', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = app(BackupService::class)->start($admin, BackupRun::TRIGGER_MANUAL);

    $job = new RunBackup((string) $run->id);
    expect($job->uniqueId())->toBe((string) $run->id);

    $job->handle(app(BackupService::class));
    $run->refresh();
    expect($run->state)->toBe(BackupRun::STATE_COMPLETED);

    $completedAt = $run->completed_at;
    $job->handle(app(BackupService::class));
    expect($run->refresh()->completed_at->equalTo($completedAt))->toBeTrue();
});

it('fails a backup job and closes the maintenance window', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = BackupRun::factory()->create(['state' => BackupRun::STATE_RUNNING, 'requested_by_user_id' => $admin->id]);
    app(MaintenanceWindow::class)->begin('backup', (string) $run->id, 'test');

    (new RunBackup((string) $run->id))->failed(new RuntimeException('worker died'));

    expect($run->refresh()->state)->toBe(BackupRun::STATE_FAILED)
        ->and($run->error_code)->toBe('queue_failed')
        ->and(app(MaintenanceWindow::class)->active())->toBeFalse();
});

it('runs a rotation job and ignores a completed run', function (): void {
    ['vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();
    $run = KeyRotationRun::factory()->create([
        'operation' => 'bogus',
        'vault_id' => $vault->id,
        'status' => KeyRotationRun::STATUS_PENDING,
        'started_by_user_id' => $admin->id,
    ]);

    $job = new RunKeyRotation((string) $run->id);
    expect($job->uniqueId())->toBe((string) $run->id);
    $job->handle(app(KeyRotationService::class));

    expect($run->refresh()->status)->toBe(KeyRotationRun::STATUS_FAILED);

    $completed = KeyRotationRun::factory()->create([
        'operation' => 'bogus',
        'vault_id' => $vault->id,
        'status' => KeyRotationRun::STATUS_COMPLETED,
        'started_by_user_id' => $admin->id,
    ]);
    (new RunKeyRotation((string) $completed->id))->handle(app(KeyRotationService::class));
    expect($completed->refresh()->status)->toBe(KeyRotationRun::STATUS_COMPLETED);
});

it('resumes a rotation from the operations route and rejects non-admins', function (): void {
    ['vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();
    $run = KeyRotationRun::factory()->create([
        'operation' => 'bogus',
        'vault_id' => $vault->id,
        'status' => KeyRotationRun::STATUS_FAILED,
        'started_by_user_id' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->post(route('operations.rotations.resume', ['run' => $run->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    $completed = KeyRotationRun::factory()->create([
        'operation' => 'bogus',
        'vault_id' => $vault->id,
        'status' => KeyRotationRun::STATUS_COMPLETED,
        'started_by_user_id' => $admin->id,
    ]);
    $this->actingAs($admin)
        ->post(route('operations.rotations.resume', ['run' => $completed->id]))
        ->assertRedirect()
        ->assertSessionMissing('status');

    $plain = User::factory()->withTwoFactor()->create();
    $this->actingAs($plain)
        ->post(route('operations.rotations.resume', ['run' => $run->id]))
        ->assertForbidden();
});
