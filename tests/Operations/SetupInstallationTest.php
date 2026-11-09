<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->connection = config('database.default');
    $this->keys = sys_get_temp_dir().'/vaultigen-setup-'.bin2hex(random_bytes(8));
    mkdir($this->keys, 0700);
    config([
        'app.key' => null,
        'vaultigen.app_key_file' => $this->keys.'/app.key',
        'vaultigen.installation_file' => $this->keys.'/installation.json',
        'vaultigen.installation_id' => null,
        'vaultigen.passkey_secret' => null,
        'vaultigen.audit_key' => null,
        'vaultigen.kek_provider' => 'file',
        'vaultigen.kek_file' => $this->keys.'/kek.json',
        'vaultigen.backup_keyring' => null,
        'vaultigen.backup_key_file' => $this->keys.'/backup.json',
    ]);
});

afterEach(function (): void {
    if (! isset($this->keys)) {
        return;
    }

    File::deleteDirectory($this->keys);
    DB::connection($this->connection)->table('installation_settings')->where('id', 1)->update(['bootstrapped_at' => null, 'installation_id' => null]);
});

/**
 * Point the default connection at an empty SQLite database, as on a new server.
 */
function setup_installation_use_empty_database(): void
{
    $path = test()->keys.'/empty.sqlite';
    touch($path);
    config(['database.connections.setup_empty' => ['driver' => 'sqlite', 'database' => $path, 'foreign_key_constraints' => true]]);
    config(['database.default' => 'setup_empty']);
}

it('creates every missing key on an empty database, then migrates and creates the administrator in fresh processes', function (): void {
    setup_installation_use_empty_database();
    Process::fake();

    $this->artisan('vaultigen:setup', ['--email' => 'admin@example.com', '--name' => 'Ada'])
        ->expectsOutputToContain('Setup completed.')
        ->assertSuccessful();

    foreach (['app.key', 'installation.json', 'kek.json', 'backup.json'] as $file) {
        expect(fileperms($this->keys.'/'.$file) & 0777)->toBe(0600);
    }
    expect(strlen((string) base64_decode(substr((string) file_get_contents($this->keys.'/app.key'), 7), true)))->toBe(32);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['php', 'artisan', 'migrate', '--force', '--no-interaction']);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['php', 'artisan', 'vaultigen:bootstrap', 'admin@example.com', '--name=Ada']);
});

it('resumes without replacing keys or creating another administrator', function (): void {
    Process::fake();
    $installationId = '550e8400-e29b-41d4-a716-446655440000';
    $files = [
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'installation.json' => json_encode(['installation_id' => $installationId, 'passkey_secret' => 'cA==', 'audit_key' => 'YQ==']),
        'kek.json' => json_encode(['active_key_id' => 'kek-1', 'keys' => ['kek-1' => base64_encode(str_repeat('k', 32))]]),
        'backup.json' => json_encode(['active_key_id' => 'backup-1', 'keys' => ['backup-1' => base64_encode(str_repeat('b', 32))]]),
    ];
    foreach ($files as $file => $contents) {
        file_put_contents($this->keys.'/'.$file, $contents);
    }
    config([
        'app.key' => $files['app.key'],
        'vaultigen.installation_id' => $installationId,
        'vaultigen.passkey_secret' => 'cA==',
        'vaultigen.audit_key' => 'YQ==',
    ]);
    DB::table('installation_settings')->where('id', 1)->update(['bootstrapped_at' => now(), 'installation_id' => $installationId]);

    $this->artisan('vaultigen:setup')
        ->expectsOutputToContain('administrator creation is skipped')
        ->assertSuccessful();

    foreach ($files as $file => $contents) {
        expect(file_get_contents($this->keys.'/'.$file))->toBe($contents);
    }
    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 1);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['php', 'artisan', 'migrate', '--force', '--no-interaction']);
});

it('refuses an existing database whose keys are missing and creates nothing', function (): void {
    Process::fake();

    $this->artisan('vaultigen:setup')
        ->expectsOutputToContain('recovery inputs are missing: app_key, installation, kek, backup_key')
        ->assertFailed();

    expect(glob($this->keys.'/*'))->toBe([]);
    Process::assertNothingRan();
});

it('refuses keys that belong to another installation', function (): void {
    Process::fake();
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'vaultigen.installation_id' => '550e8400-e29b-41d4-a716-446655440000',
        'vaultigen.passkey_secret' => 'cA==',
        'vaultigen.audit_key' => 'YQ==',
        'vaultigen.kek_provider' => 'environment',
        'vaultigen.kek_keyring' => json_encode(['active_key_id' => 'kek-1', 'keys' => ['kek-1' => base64_encode(str_repeat('k', 32))]]),
        'vaultigen.backup_keyring' => json_encode(['active_key_id' => 'backup-1', 'keys' => ['backup-1' => base64_encode(str_repeat('b', 32))]]),
    ]);
    DB::table('installation_settings')->where('id', 1)->update(['bootstrapped_at' => now(), 'installation_id' => '11111111-1111-4111-8111-111111111111']);

    $this->artisan('vaultigen:setup')
        ->expectsOutputToContain('do not belong to this database')
        ->assertFailed();

    Process::assertNothingRan();
});

it('refuses an unusable database password before touching the database', function (): void {
    Process::fake();
    config(['database.default' => 'pgsql', 'database.connections.pgsql.password' => 'change-me']);

    $this->artisan('vaultigen:setup')->expectsOutputToContain('placeholder value')->assertFailed();

    expect(glob($this->keys.'/*'))->toBe([]);
    Process::assertNothingRan();
});

it('never replaces an existing but unusable :file', function (string $file, string $contents): void {
    setup_installation_use_empty_database();
    Process::fake();
    file_put_contents($this->keys.'/'.$file, $contents);

    $this->artisan('vaultigen:setup', ['--email' => 'admin@example.com'])
        ->expectsOutputToContain('Setup never replaces an existing key file')
        ->assertFailed();

    expect(file_get_contents($this->keys.'/'.$file))->toBe($contents);
    Process::assertNothingRan();
})->with([
    'application key file' => ['app.key', ''],
    'installation identity file' => ['installation.json', '{}'],
]);

it('stops before creating an administrator without interactive input', function (): void {
    setup_installation_use_empty_database();
    Process::fake();

    $this->artisan('vaultigen:setup', ['--no-interaction' => true])
        ->expectsOutputToContain('interactively')
        ->assertFailed();

    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 1);
});

it('keeps the created keys when :step fails so a rerun can resume', function (string $failing, string $message): void {
    setup_installation_use_empty_database();
    Process::fake([$failing => Process::result(exitCode: 1), '*' => Process::result()]);

    $this->artisan('vaultigen:setup', ['--email' => 'admin@example.com'])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(is_file($this->keys.'/kek.json'))->toBeTrue()
        ->and(is_file($this->keys.'/app.key'))->toBeTrue();
})->with([
    'the migration' => ['*migrate*', 'Migrations failed'],
    'the administrator creation' => ['*vaultigen:bootstrap*', 'The administrator was not created'],
]);
