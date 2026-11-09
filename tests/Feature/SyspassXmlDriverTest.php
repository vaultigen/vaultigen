<?php

use App\Models\Entry;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\EntryVault;
use App\Services\Imports\ImportPlanEditor;
use App\Services\Imports\ImportRunner;
use App\Services\Imports\Syspass\SyspassXmlDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\SyspassExportBuilder;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

function syspass_driver_export(SyspassExportBuilder $builder, string $filename = 'syspass-export.xml'): UploadedFile
{
    $accounts = '  <Accounts>'.PHP_EOL
        .$builder->account('1', 'Example', '1', '10', 'alice', 'https://example.com', 'note one', 'secret-one')
        .$builder->account('2', 'Second', '2', '20', 'bob', 'https://second.example', 'note two', 'secret-two')
        .'  </Accounts>'.PHP_EOL;

    $xml = $builder->build([
        $builder->simpleFragment('Categories', 'Category', [10 => 'Databases', 20 => 'Email']),
        $builder->simpleFragment('Clients', 'Client', [1 => 'Acme', 2 => 'Beta']),
        $accounts,
    ], ['Generator' => 'sysPass']);

    return UploadedFile::fake()->createWithContent($filename, $xml);
}

it('stages an encrypted export and previews its structure', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new SyspassExportBuilder('export-pass', 'master-pass');
    $file = syspass_driver_export($builder);

    $driver = app(SyspassXmlDriver::class);
    $run = $driver->stage($file, ['export_password' => 'export-pass'], $admin);

    expect($run->status)->toBe(ImportRun::STATUS_STAGED)
        ->and($run->source_format)->toBe('syspass_xml')
        ->and($run->original_filename)->toBe('syspass-export.xml')
        ->and($run->source_fingerprint)->toHaveLength(64)
        ->and($run->random_storage_path)->toStartWith('imports/');

    $preview = $driver->preview($run->refresh());

    expect($preview['structure']['accounts'])->toBe(2)
        ->and($preview['structure']['clients'])->toBe(2)
        ->and($preview['structure']['meta']['generator'])->toBe('sysPass');
});

it('falls back to a default filename when the upload name is unsafe', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new SyspassExportBuilder('export-pass', 'master-pass');
    $file = syspass_driver_export($builder, "\x01");

    $run = app(SyspassXmlDriver::class)->stage($file, ['export_password' => 'export-pass'], $admin);

    expect($run->original_filename)->toBe('syspass-export.xml');
});

it('rejects a wrong export password without creating a run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new SyspassExportBuilder('export-pass');
    $file = syspass_driver_export($builder);

    expect(fn () => app(SyspassXmlDriver::class)->stage($file, ['export_password' => 'wrong'], $admin))
        ->toThrow(ValidationException::class)
        ->and(ImportRun::query()->count())->toBe(0);
});

it('rejects an upload without an export password', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new SyspassExportBuilder('export-pass');
    $file = syspass_driver_export($builder);

    expect(fn () => app(SyspassXmlDriver::class)->stage($file, [], $admin))
        ->toThrow(ValidationException::class);
});

it('rejects invalid, empty, and oversized uploads', function (UploadedFile $file) {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    expect(fn () => app(SyspassXmlDriver::class)->stage($file, ['export_password' => 'export-pass'], $admin))
        ->toThrow(ValidationException::class);
})->with([
    'invalid upload' => [fn (): UploadedFile => new UploadedFile('/tmp/vaultigen-missing-upload', 'x.xml', null, UPLOAD_ERR_NO_FILE, true)],
    'empty upload' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('empty.xml', '')],
    'oversized upload' => [fn (): UploadedFile => UploadedFile::fake()->create('big.xml', 70000)],
]);

it('builds a default plan and persists a resolved plan', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new SyspassExportBuilder('export-pass', 'master-pass');
    $file = syspass_driver_export($builder);

    $driver = app(SyspassXmlDriver::class);
    $run = $driver->stage($file, ['export_password' => 'export-pass'], $admin);

    $defaults = app(ImportPlanEditor::class)->defaultPlan($run);
    expect($defaults['layout'])->toBe('single_vault');

    $plan = app(ImportPlanEditor::class)->plan($run, [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'category',
        'client' => 'vault',
        'category' => 'folder',
    ]);

    expect($plan['layout'])->toBe('vault_per_client')
        ->and($run->refresh()->layout)->toBe('vault_per_client')
        ->and($run->axis_config['vault_axis'])->toBe('client')
        ->and($run->plan['vaults'])->toHaveKey('client:1');
});

it('runs a staged import and reveals the imported secrets', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new SyspassExportBuilder('export-pass', 'master-pass');
    $file = syspass_driver_export($builder);

    $driver = app(SyspassXmlDriver::class);
    $run = $driver->stage($file, ['export_password' => 'export-pass'], $admin);
    app(ImportPlanEditor::class)->plan($run, [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'category',
        'client' => 'vault',
        'category' => 'folder',
    ]);

    $result = app(ImportRunner::class)->run($run->refresh(), ['master_password' => 'master-pass']);

    expect($result->succeeded())->toBeTrue()
        ->and($result->counters['vaults'])->toBe(2)
        ->and($result->counters['entries'])->toBe(2)
        ->and($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($run->random_storage_path)->toBeNull();

    $vaultId = $run->created_vault_ids[0];
    $entry = Entry::query()->where('vault_id', $vaultId)->firstOrFail();
    $manager = User::query()->findOrFail($admin->id);

    expect(app(EntryVault::class)->reveal($manager, $entry->id, 'password'))->toBeIn(['secret-one', 'secret-two'])
        ->and(app(EntryVault::class)->reveal($manager, $entry->id, 'username'))->toBeIn(['alice', 'bob']);
});

it('never creates a global tag that the plan excluded', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(
        accounts: [vaultigen_syspass_account('1', ['tag_ids' => ['5']])],
        tags: [['id' => '5', 'name' => 'shared']],
    );
    $plan = vaultigen_syspass_plan($ir, [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'none',
        'client' => 'skip',
        'category' => 'skip',
        'single_vault_name' => 'Imported',
    ], $admin);
    $plan['tags'] = [['name' => 'shared', 'scope' => 'global', 'include' => false]];

    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);
    app(ImportRunner::class)->run($run, ['master_password' => 'ignored']);

    expect(DB::table('tags')->where('name', 'shared')->count())->toBe(0)
        ->and(Entry::query()->count())->toBe(1);
});

it('marks the run failed and imports nothing with a wrong master password', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new SyspassExportBuilder('export-pass', 'master-pass');
    $file = syspass_driver_export($builder);

    $driver = app(SyspassXmlDriver::class);
    $run = $driver->stage($file, ['export_password' => 'export-pass'], $admin);
    app(ImportPlanEditor::class)->plan($run, [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'category',
        'client' => 'skip',
        'category' => 'folder',
        'single_vault_name' => 'Imported',
    ]);

    $result = app(ImportRunner::class)->run($run->refresh(), ['master_password' => 'wrong-master']);

    expect($result->succeeded())->toBeFalse()
        ->and($result->errorCode)->toBe('master_password_rejected')
        ->and(Entry::query()->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(ImportRun::STATUS_FAILED);
});
