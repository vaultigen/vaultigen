<?php

use App\Models\BackupRun;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Services\Operations\BackupCipher;
use App\Services\Operations\BackupService;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationFailedException;
use App\Services\Operations\RestoreService;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\FakeBackupRuntime;

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

function preflight_failure(string $directory): string
{
    try {
        app(RestoreService::class)->preflight($directory);
    } catch (OperationFailedException $exception) {
        return $exception->failureCode;
    }

    return 'no-error';
}

it('fails preflight when the manifest is missing or malformed', function (): void {
    expect(preflight_failure('/tmp/vaultigen-missing-set'))->toBe('manifest_missing');

    $directory = sys_get_temp_dir().'/vaultigen-set-'.Str::uuid();
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/manifest.json', 'not-json');
    file_put_contents($directory.'/manifest.mac', 'mac');
    expect(preflight_failure($directory))->toBe('manifest_malformed');
});

it('fails preflight when the backup key is unavailable or the manifest is not authenticated', function (): void {
    [$directory, $manifest] = vaultigen_fake_backup_set(['backup_key_id' => 'unknown-key']);
    expect(preflight_failure($directory))->toBe('backup_key_missing');

    [$tampered] = vaultigen_fake_backup_set();
    file_put_contents($tampered.'/manifest.mac', str_repeat('0', 64));
    expect(preflight_failure($tampered))->toBe('manifest_authentication_failed');
});

it('fails preflight when the installation identity differs', function (): void {
    [$directory] = vaultigen_fake_backup_set(['installation_id' => (string) Str::uuid()]);

    expect(preflight_failure($directory))->toBe('installation_mismatch');
});

it('fails preflight when a required key is missing', function (): void {
    [$directory] = vaultigen_fake_backup_set(['required_key_ids' => ['kek' => ['missing-kek'], 'audit' => ['audit-v1']]]);
    expect(preflight_failure($directory))->toBe('kek_key_missing');

    [$appKey] = vaultigen_fake_backup_set(['app_key_present' => true]);
    config(['app.key' => null]);
    expect(preflight_failure($appKey))->toBe('app_key_missing');

    [$passkey] = vaultigen_fake_backup_set(['passkey_secret_present' => true]);
    config(['vaultigen.passkey_secret' => null]);
    expect(preflight_failure($passkey))->toBe('passkey_secret_missing');
});

it('fails preflight when checksums do not match', function (): void {
    [$snapshot] = vaultigen_fake_backup_set(['snapshot' => ['file' => 'database.enc', 'checksum' => str_repeat('a', 64), 'bytes' => 1]]);
    expect(preflight_failure($snapshot))->toBe('integrity_failed');

    [$object] = vaultigen_fake_backup_set(['objects' => [['kind' => 'attachment', 'id' => (string) Str::uuid(), 'file' => 'objects/a.enc', 'source_path' => 'x', 'bytes' => 1, 'checksum' => str_repeat('a', 64)]]]);
    expect(preflight_failure($object))->toBe('integrity_failed');

    [$malformedSnapshot] = vaultigen_fake_backup_set(['snapshot' => 'not-an-array']);
    expect(preflight_failure($malformedSnapshot))->toBe('manifest_malformed');
});

it('fails preflight for a scalar manifest and empty or absent checksums', function (): void {
    $scalar = sys_get_temp_dir().'/vaultigen-set-'.Str::uuid();
    mkdir($scalar, 0700, true);
    file_put_contents($scalar.'/manifest.json', '"scalar"');
    file_put_contents($scalar.'/manifest.mac', 'mac');
    expect(preflight_failure($scalar))->toBe('manifest_malformed');

    [$emptyChecksum] = vaultigen_fake_backup_set(['snapshot' => ['file' => 'database.enc', 'checksum' => '', 'bytes' => 1]]);
    expect(preflight_failure($emptyChecksum))->toBe('integrity_failed');

    [$missingObject] = vaultigen_fake_backup_set(['objects' => [['kind' => 'attachment', 'id' => (string) Str::uuid(), 'file' => 'objects/missing.enc', 'source_path' => 'x', 'bytes' => 1, 'checksum' => str_repeat('b', 64)]]]);
    expect(preflight_failure($missingObject))->toBe('integrity_failed');
});

it('reports a snapshot that is not authentic', function (): void {
    $source = (string) tempnam(sys_get_temp_dir(), 'vaultigen-in-');
    $output = (string) tempnam(sys_get_temp_dir(), 'vaultigen-out-');
    file_put_contents($source, 'garbage');

    expect(fn () => app(RestoreService::class)->decryptSnapshotTo($source, $output, [
        'backup_key_id' => 'backup-test-v1',
        'backup_run_id' => (string) Str::uuid(),
    ]))->toThrow(OperationFailedException::class);

    @unlink($source);
    @unlink($output);
});

it('reports snapshot open failures while decrypting', function (): void {
    $service = app(RestoreService::class);
    $manifest = ['backup_key_id' => 'backup-test-v1', 'backup_run_id' => (string) Str::uuid()];

    expect(fn () => $service->decryptSnapshotTo('/nonexistent/snapshot.enc', (string) tempnam(sys_get_temp_dir(), 'vaultigen-out-'), $manifest))
        ->toThrow(OperationFailedException::class);

    $directory = sys_get_temp_dir().'/vaultigen-outdir-'.Str::uuid();
    mkdir($directory, 0700, true);
    $source = (string) tempnam(sys_get_temp_dir(), 'vaultigen-in-');
    file_put_contents($source, 'ciphertext');
    expect(fn () => $service->decryptSnapshotTo($source, $directory, $manifest))
        ->toThrow(OperationFailedException::class);

    @unlink($source);
    @rmdir($directory);
});

it('passes preflight for a well-formed set without touching the database', function (): void {
    [$directory, $manifest] = vaultigen_fake_backup_set();

    $result = app(RestoreService::class)->preflight($directory);

    expect($result['backup_run_id'])->toBe($manifest['backup_run_id'])
        ->and($result['installation_id'])->toBe($manifest['installation_id']);
});

it('fails preflight when the snapshot cannot be opened for checksum verification', function (): void {
    [$directory] = vaultigen_fake_backup_set();
    $snapshot = $directory.'/database.enc';
    FakeBackupRuntime::$overrides['fopen'] = fn (string $path, string $mode): mixed => $path === $snapshot ? false : fopen($path, $mode);

    expect(preflight_failure($directory))->toBe('integrity_failed');
    $this->assertDatabaseCount('backup_runs', 0);
});

it('leaves the target untouched and removes staging when a SQLite inventory table is missing', function (string $availableTable): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific inventory validation.');
    }

    [$directory, $manifest] = vaultigen_fake_backup_set(['objects' => []]);
    $database = Storage::disk('local')->path('incomplete.sqlite');
    $pdo = new PDO('sqlite:'.$database);
    $pdo->exec('CREATE TABLE migrations (id INTEGER)');
    $pdo->exec('CREATE TABLE '.$availableTable.' (id TEXT, random_storage_path TEXT)');
    $pdo = null;

    $source = fopen($database, 'rb');
    $destination = fopen($directory.'/database.enc', 'wb');
    $cipher = app(BackupCipher::class);
    try {
        $encrypted = $cipher->encryptSnapshot($source, $destination, 'backup-test-v1', str_repeat('b', 32), $manifest['backup_run_id']);
    } finally {
        fclose($source);
        fclose($destination);
    }
    $manifest['snapshot']['checksum'] = $encrypted['checksum'];
    $manifest['snapshot']['bytes'] = $encrypted['ciphertext_bytes'];
    $manifest['total_bytes'] = $encrypted['ciphertext_bytes'];
    $raw = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($directory.'/manifest.json', $raw);
    file_put_contents($directory.'/manifest.mac', $cipher->manifestMac('backup-test-v1', str_repeat('b', 32), $manifest['backup_run_id'], $raw));

    $target = Storage::disk('local')->path('untouched.sqlite');
    file_put_contents($target, 'original database');
    config(['database.connections.sqlite.database' => $target]);
    $mode = Mockery::mock(MaintenanceMode::class);
    $mode->shouldReceive('active')->andReturnTrue();
    app()->instance(MaintenanceMode::class, $mode);
    $stagingBefore = glob(config('vaultigen.backup_temporary_root').'/snapshot-*');

    try {
        expect(fn () => app(RestoreService::class)->restore($directory, true))
            ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('inventory_mismatch'));

        expect(file_get_contents($target))->toBe('original database');
        expect(is_file(storage_path('framework/restore-in-progress.json')))->toBeFalse();
        expect(glob(config('vaultigen.backup_temporary_root').'/snapshot-*'))->toBe($stagingBefore);
        $this->assertDatabaseMissing('audit_events', ['action' => 'restore.completed']);
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    'missing attachment inventory' => ['import_runs'],
    'missing import inventory' => ['file_attachments'],
]);

it('restores a real backup into a clean SQLite file', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific test.');
    }

    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault'], ['title' => 'Recovered login']);
    $run = vaultigen_run_backup($fixture['admin']);
    expect($run->state)->toBe(BackupRun::STATE_COMPLETED);

    $target = tempnam(sys_get_temp_dir(), 'vaultigen-restore-');
    @unlink($target);
    config(['database.connections.sqlite.database' => $target]);

    $mode = Mockery::mock(MaintenanceMode::class);
    $mode->shouldReceive('active')->andReturnTrue();
    app()->instance(MaintenanceMode::class, $mode);
    app(RestoreService::class)->restore(vaultigen_backup_directory($run), true);

    expect(is_file($target))->toBeTrue();
    $pdo = new PDO('sqlite:'.$target);
    $titles = $pdo->query('select title from entries')->fetchAll(PDO::FETCH_COLUMN);
    expect($titles)->toContain('Recovered login');

    @unlink($target);
});

it('resets inherited runtime state after a restore', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    BackupRun::factory()->create(['state' => BackupRun::STATE_RUNNING, 'requested_by_user_id' => $admin->id]);
    KeyRotationRun::factory()->create(['status' => KeyRotationRun::STATUS_RUNNING, 'started_by_user_id' => $admin->id]);
    app(MaintenanceWindow::class)->begin('backup', null, 'test');

    app(RestoreService::class)->resetRuntimeState();

    expect(BackupRun::query()->where('state', BackupRun::STATE_FAILED)->count())->toBe(1)
        ->and(KeyRotationRun::query()->where('status', KeyRotationRun::STATUS_FAILED)->count())->toBe(1)
        ->and(app(MaintenanceWindow::class)->active())->toBeFalse();
});

it('requires the original audit recovery key before touching the target', function (): void {
    [$directory] = vaultigen_fake_backup_set(['database_driver' => DB::connection()->getDriverName()]);
    config(['vaultigen.audit_key' => null]);

    expect(preflight_failure($directory))->toBe('audit_key_missing');
    $this->assertDatabaseCount('backup_runs', 0);
});

it('rejects a snapshot whose stored file references differ from its authenticated inventory', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific staging target.');
    }
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    $entry = vaultigen_make_entry($admin, $vault);
    vaultigen_upload_attachment($admin, $entry, 'required file');
    $run = vaultigen_run_backup($admin);
    $manifest = app(BackupService::class)->readManifest((string) $run->path);
    $manifest['objects'] = [];
    $manifest['object_count'] = 0;
    $manifest['total_bytes'] = $manifest['snapshot']['bytes'];
    $directory = vaultigen_backup_directory($run);
    $raw = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($directory.'/manifest.json', $raw);
    file_put_contents($directory.'/manifest.mac', app(BackupCipher::class)->manifestMac('backup-test-v1', str_repeat('b', 32), (string) $run->id, $raw));
    $mode = Mockery::mock(MaintenanceMode::class);
    $mode->shouldReceive('active')->andReturnTrue();
    app()->instance(MaintenanceMode::class, $mode);
    $target = Storage::disk('local')->path('untouched.sqlite');
    config(['database.connections.sqlite.database' => $target]);

    expect(fn () => app(RestoreService::class)->restore(vaultigen_backup_directory($run), true))
        ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('inventory_mismatch'));

    expect(is_file($target))->toBeFalse();
    expect(is_file(storage_path('framework/restore-in-progress.json')))->toBeFalse();
});

it('fails safely when restore staging or recovery-marker writes fail', function (string $failure, string $code): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific staging target.');
    }
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    $entry = vaultigen_make_entry($admin, $vault);
    vaultigen_upload_attachment($admin, $entry, 'file bytes');
    $run = vaultigen_run_backup($admin);
    $directory = vaultigen_backup_directory($run);
    $originalPdo = DB::connection()->getPdo();
    $target = Storage::disk('local')->path('restore-fault.sqlite');
    config(['database.connections.sqlite.database' => $target]);
    $mode = Mockery::mock(MaintenanceMode::class);
    $mode->shouldReceive('active')->andReturnTrue();
    app()->instance(MaintenanceMode::class, $mode);
    $marker = storage_path('framework/restore-in-progress.json');
    FakeBackupRuntime::$overrides[$failure] = match ($failure) {
        'copy' => fn (string $from, string $to): bool => str_ends_with($to, '/object-0') ? false : copy($from, $to),
        'file_put_contents' => fn (string $path, mixed $data): int|false => $path === $marker ? false : file_put_contents($path, $data),
        'unlink' => fn (string $path): bool => $path === $marker ? false : unlink($path),
    };
    $stagingBefore = glob(config('vaultigen.backup_temporary_root').'/snapshot-*');
    try {
        expect(fn () => app(RestoreService::class)->restore($directory, true))
            ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe($code));

        expect(app()->isDownForMaintenance())->toBeTrue();
        expect(is_file($target))->toBe($failure === 'unlink');
        expect(is_file($marker))->toBe($failure === 'unlink');
        expect(glob(config('vaultigen.backup_temporary_root').'/snapshot-*'))->toBe($stagingBefore);
    } finally {
        FakeBackupRuntime::$overrides = [];
        @unlink($marker);
        DB::purge();
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::connection()->setPdo($originalPdo);
    }
})->with([
    'object staging failure' => ['copy', 'object_unwritable'],
    'marker creation failure' => ['file_put_contents', 'object_unwritable'],
    'marker removal failure' => ['unlink', 'restore_apply_failed'],
]);
