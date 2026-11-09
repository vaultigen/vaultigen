<?php

use App\Filament\Pages\ImportReport;
use App\Filament\Pages\Imports;
use App\Filament\Pages\ImportWizard;
use App\Jobs\ExecuteImportRun;
use App\Models\Entry;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportCredentialStore;
use App\Services\Imports\ImportPlanEditor;
use App\Services\Imports\ImportRetention;
use App\Services\Imports\ImportRunLifecycle;
use App\Services\Imports\ImportRunner;
use App\Services\Imports\ImportSourceStore;
use App\Services\Imports\Syspass\SyspassXmlDriver;
use App\Services\Operations\MaintenanceActiveException;
use App\Services\Operations\MaintenanceWindow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fixtures\KeepassXmlBuilder;
use Tests\Fixtures\SyspassExportBuilder;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

/**
 * @return array<string, mixed>
 */
function import_queue_ir(): array
{
    return vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('1', ['client_id' => '1', 'category_id' => '10']),
            vaultigen_syspass_account('2', ['client_id' => '2', 'category_id' => '20']),
        ],
        clients: [
            ['id' => '1', 'name' => 'Acme'],
            ['id' => '2', 'name' => 'Beta'],
        ],
        categories: [
            ['id' => '10', 'name' => 'Databases'],
            ['id' => '20', 'name' => 'Email'],
        ],
    );
}

/**
 * @return array<string, mixed>
 */
function import_queue_plan(User $admin): array
{
    return vaultigen_syspass_plan(import_queue_ir(), [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'category',
        'client' => 'skip',
        'category' => 'folder',
        'single_vault_name' => 'Imported',
    ], $admin);
}

function import_queue_run(User $admin, string $master = 'master-pass', string $status = ImportRun::STATUS_QUEUED): ImportRun
{
    $run = vaultigen_staged_syspass_run($admin, import_queue_ir(), import_queue_plan($admin));
    app(ImportCredentialStore::class)->seal($run, ['master_password' => $master]);
    $run->forceFill([
        'status' => $status,
        'queued_at' => now(),
        'progress_total' => 2,
    ])->save();

    return $run->refresh();
}

it('seals and opens import credentials without persisting the plaintext', function () {
    $run = ImportRun::factory()->create(['status' => ImportRun::STATUS_STAGED]);
    $store = app(ImportCredentialStore::class);

    $store->seal($run, ['master_password' => 'super-secret-master']);

    expect($run->refresh()->wrapped_credentials)->not->toBeNull()
        ->and($store->open($run))->toBe(['master_password' => 'super-secret-master'])
        ->and(DB::table('import_runs')->where('id', $run->id)->value('wrapped_credentials'))->not->toContain('super-secret-master');

    $store->purge($run);

    expect($run->refresh()->wrapped_credentials)->toBeNull()
        ->and($run->credentials_key_id)->toBeNull();
});

it('refuses to open credentials that were never sealed', function () {
    $run = ImportRun::factory()->create(['status' => ImportRun::STATUS_STAGED]);

    expect(fn () => app(ImportCredentialStore::class)->open($run))
        ->toThrow(RuntimeException::class);
});

it('refuses to open a tampered credential envelope', function () {
    $run = ImportRun::factory()->create(['status' => ImportRun::STATUS_STAGED]);
    $store = app(ImportCredentialStore::class);
    $store->seal($run, ['master_password' => 'master-pass']);

    $run->forceFill([
        'wrapped_credentials' => substr((string) $run->wrapped_credentials, 0, -4).'AAAA',
    ])->save();

    expect(fn () => $store->open($run->refresh()))->toThrow(RuntimeException::class);
});

it('hides the wrapped credentials from serialization', function () {
    $run = import_queue_run(User::factory()->superAdmin()->withTwoFactor()->create());

    expect($run->toArray())->not->toHaveKey('wrapped_credentials')
        ->and($run->toArray())->not->toHaveKey('credentials_key_id');
});

it('queues an import instead of running it in the request', function () {
    Queue::fake();

    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = vaultigen_staged_syspass_run($admin, import_queue_ir(), import_queue_plan($admin));
    $this->actingAs($admin);

    $this->post(route('imports.execute', ['run' => $run->id]), ['master_password' => 'master-pass'])
        ->assertRedirect(ImportReport::getUrl(['run' => $run->id]));

    $run->refresh();

    expect($run->status)->toBe(ImportRun::STATUS_QUEUED)
        ->and($run->queued_at)->not->toBeNull()
        ->and($run->progress_total)->toBe(2)
        ->and($run->wrapped_credentials)->not->toBeNull();

    Queue::assertPushed(ExecuteImportRun::class, fn (ExecuteImportRun $job): bool => $job->runId === $run->id);
});

it('refuses to enqueue a run that is already running', function () {
    Queue::fake();

    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_IMPORTING);
    $this->actingAs($admin);

    $this->post(route('imports.execute', ['run' => $run->id]), ['master_password' => 'other'])
        ->assertRedirect(ImportReport::getUrl(['run' => $run->id]));

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_IMPORTING);
    Queue::assertNothingPushed();
});

it('runs a queued import in the job and purges the credentials', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin);

    ExecuteImportRun::dispatchSync($run->id);

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($run->wrapped_credentials)->toBeNull()
        ->and($run->random_storage_path)->toBeNull()
        ->and(Entry::query()->count())->toBe(2);
});

it('clears a stale failure marker when the authoritative attempt completes', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin);
    $run->forceFill([
        'error_code' => 'queue_failed',
        'failed_at' => now()->subMinute(),
    ])->save();

    ExecuteImportRun::dispatchSync($run->id);

    $run->refresh();

    expect($run->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($run->error_code)->toBeNull()
        ->and($run->failed_at)->toBeNull()
        ->and(Entry::query()->count())->toBe(2);
});

it('decrypts passwords lazily and records a per-entry decrypt failure without a blank entry', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('1'),
            vaultigen_syspass_account('2', ['pass' => 'def50200'.str_repeat('ab', 64), 'key' => '']),
        ],
    );
    $plan = vaultigen_syspass_plan($ir, [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'none',
        'client' => 'skip',
        'category' => 'skip',
        'single_vault_name' => 'Imported',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = app(ImportRunner::class)->run($run, ['master_password' => 'ignored']);

    expect($result->counters['entries'])->toBe(1)
        ->and($result->failureCount)->toBe(1)
        ->and($run->refresh()->failures[0]['error_code'])->toBe('password_decrypt_failed')
        ->and(Entry::query()->count())->toBe(1);
});

it('persists access grants from the repeater and applies them to the vaults', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $editor = User::factory()->withTwoFactor()->create();
    $viewer = User::factory()->withTwoFactor()->create();
    $run = vaultigen_staged_syspass_run($admin, import_queue_ir(), import_queue_plan($admin));
    $this->actingAs($admin);

    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->set('data.grants', [
            ['principal_id' => $editor->principal_id, 'permission' => 'editor'],
            ['principal_id' => $viewer->principal_id, 'permission' => 'viewer'],
        ]);

    expect($run->refresh()->plan['grants'])->toHaveCount(2);

    app(ImportCredentialStore::class)->seal($run, ['master_password' => 'ignored']);
    $run->forceFill(['status' => ImportRun::STATUS_QUEUED, 'queued_at' => now(), 'progress_total' => 2])->save();

    ExecuteImportRun::dispatchSync($run->id);

    expect(DB::table('vault_grants')->where('principal_id', $editor->principal_id)->where('permission', 'editor')->whereNotNull('active_slot')->exists())->toBeTrue()
        ->and(DB::table('vault_grants')->where('principal_id', $viewer->principal_id)->where('permission', 'viewer')->whereNotNull('active_slot')->exists())->toBeTrue();
});

it('does not run a job for a run that is not queued', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_STAGED);

    ExecuteImportRun::dispatchSync($run->id);

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_STAGED)
        ->and(Entry::query()->count())->toBe(0);
});

it('ignores a job for a run that is already terminal', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_COMPLETED);

    ExecuteImportRun::dispatchSync($run->id);

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($run->wrapped_credentials)->not->toBeNull();
});

it('cancels a running import from the hub and reports a pending stop', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_IMPORTING);
    $this->actingAs($admin);

    Livewire::test(Imports::class)
        ->callTableAction('cancel', $run)
        ->assertNotified('Cancellation requested; the import will stop shortly');

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_IMPORTING)
        ->and($run->cancel_requested_at)->not->toBeNull();
});

it('does not import twice when a job is delivered again', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin);

    ExecuteImportRun::dispatchSync($run->id);
    ExecuteImportRun::dispatchSync($run->id);

    expect(Entry::query()->count())->toBe(2)
        ->and($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED);
});

it('fails a queued import whose credentials are wrong without writing anything', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new SyspassExportBuilder('export-pass', 'master-pass');
    $accounts = '  <Accounts>'.PHP_EOL
        .$builder->account('1', 'Example', '1', '10', 'alice', 'https://example.com', 'note', 'secret-one')
        .'  </Accounts>'.PHP_EOL;
    $xml = $builder->build([
        $builder->simpleFragment('Categories', 'Category', [10 => 'Databases']),
        $builder->simpleFragment('Clients', 'Client', [1 => 'Acme']),
        $accounts,
    ], ['Generator' => 'sysPass']);

    $driver = app(SyspassXmlDriver::class);
    $run = $driver->stage(UploadedFile::fake()->createWithContent('export.xml', $xml), ['export_password' => 'export-pass'], $admin);
    app(ImportPlanEditor::class)->plan($run, [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'category',
        'client' => 'skip',
        'category' => 'folder',
        'single_vault_name' => 'Imported',
    ]);
    app(ImportCredentialStore::class)->seal($run, ['master_password' => 'wrong-master']);
    $run->forceFill(['status' => ImportRun::STATUS_QUEUED, 'queued_at' => now(), 'progress_total' => 1])->save();

    ExecuteImportRun::dispatchSync($run->id);

    $run->refresh();

    expect($run->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('master_password_rejected')
        ->and($run->wrapped_credentials)->toBeNull()
        ->and($run->random_storage_path)->toBeNull()
        ->and(Entry::query()->count())->toBe(0);
});

it('marks a run failed and purges credentials when the job itself fails', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_IMPORTING);

    app(ExecuteImportRun::class, ['runId' => $run->id])->failed(new RuntimeException('worker died'));

    $run->refresh();

    expect($run->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('queue_failed')
        ->and($run->wrapped_credentials)->toBeNull();
});

it('ignores a failed callback for a run that is already terminal', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_COMPLETED);

    app(ExecuteImportRun::class, ['runId' => $run->id])->failed(new RuntimeException('too late'));

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($run->wrapped_credentials)->not->toBeNull();
});

it('cancels a queued run immediately and purges its secrets', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin);
    $path = (string) $run->random_storage_path;

    app(ImportRunLifecycle::class)->cancel($run, $admin);

    $run->refresh();

    expect($run->status)->toBe(ImportRun::STATUS_CANCELLED)
        ->and($run->wrapped_credentials)->toBeNull()
        ->and($run->random_storage_path)->toBeNull();

    $storedPath = DB::table('import_runs')->where('id', $run->id)->value('random_storage_path');
    expect($storedPath)->toBeNull()
        ->and(fn () => app(ImportSourceStore::class)->open($run))->toThrow(RuntimeException::class)
        ->and(Storage::disk(ImportSourceStore::DISK)->exists($path))->toBeFalse();
});

it('requests cancellation of a running run instead of pulling its staging', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_IMPORTING);
    $path = (string) $run->random_storage_path;

    app(ImportRunLifecycle::class)->cancel($run, $admin);

    $run->refresh();

    expect($run->status)->toBe(ImportRun::STATUS_IMPORTING)
        ->and($run->cancel_requested_at)->not->toBeNull()
        ->and($run->random_storage_path)->toBe($path)
        ->and($run->wrapped_credentials)->not->toBeNull();
});

it('stops a running import during password preparation when cancellation is requested', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin);
    $run->forceFill(['cancel_requested_at' => now()])->save();

    ExecuteImportRun::dispatchSync($run->id);

    $run->refresh();

    expect($run->status)->toBe(ImportRun::STATUS_CANCELLED)
        ->and($run->wrapped_credentials)->toBeNull()
        ->and($run->random_storage_path)->toBeNull();
});

it('stops a running import at its next checkpoint when cancellation is requested', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $plan = vaultigen_syspass_plan(import_queue_ir(), [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'category',
        'client' => 'vault',
        'category' => 'folder',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, import_queue_ir(), $plan);

    $requested = false;
    ImportRun::saved(function (ImportRun $model) use ($run, &$requested): void {
        // After the first vault is persisted, simulate a cancellation request
        // arriving before the next vault's checkpoint.
        if (! $requested && $model->id === $run->id && $model->counters !== null) {
            $requested = true;
            DB::table('import_runs')->where('id', $run->id)->update(['cancel_requested_at' => now()]);
        }
    });

    $result = app(ImportRunner::class)->run($run, ['master_password' => 'ignored']);

    expect($result->status)->toBe('cancelled')
        ->and($run->refresh()->status)->toBe(ImportRun::STATUS_CANCELLED);
});

it('fails and purges a queued run that no worker picks up', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin);
    DB::table('import_runs')->where('id', $run->id)->update(['queued_at' => now()->subHours(3)]);

    $result = app(ImportRetention::class)->purgeDue();

    $run->refresh();

    expect($result['stalled'])->toBe(1)
        ->and($run->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('queue_unavailable')
        ->and($run->wrapped_credentials)->toBeNull()
        ->and($run->random_storage_path)->toBeNull();
});

it('fails and purges a running run whose worker died', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_IMPORTING);
    DB::table('import_runs')->where('id', $run->id)->update(['started_at' => now()->subDay()]);

    $result = app(ImportRetention::class)->purgeDue();

    $run->refresh();

    expect($result['stalled'])->toBe(1)
        ->and($run->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('import_timeout')
        ->and($run->wrapped_credentials)->toBeNull();
});

it('leaves a freshly queued run alone', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin);

    app(ImportRetention::class)->purgeDue();

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_QUEUED);
});

it('shows progress for a running import and hides the review link', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_IMPORTING);
    $run->forceFill([
        'counters' => ['vaults' => 1, 'folders' => 0, 'tags' => 0, 'entries' => 1, 'grants' => 0],
        'failure_count' => 0,
        'progress_total' => 2,
    ])->save();
    $this->actingAs($admin);

    $this->get(ImportReport::getUrl(['run' => $run->id]))
        ->assertOk()
        ->assertSee('1 of 2 entries processed')
        ->assertSeeHtml('wire:poll.keep-alive.2s="refresh"')
        ->assertSee('Cancel')
        ->assertDontSee('Continue reviewing this import');
});

it('shows a failed run without a reason when no error code was stored', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_FAILED,
        'error_code' => null,
    ]);
    $this->actingAs($admin);

    $this->get(ImportReport::getUrl(['run' => $run->id]))->assertOk();
});

it('updates the progress view when the worker advances the counters', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_IMPORTING);
    $run->forceFill([
        'progress_total' => 2,
        'counters' => ['vaults' => 1, 'folders' => 0, 'tags' => 0, 'entries' => 0, 'grants' => 0],
        'failure_count' => 0,
    ])->save();
    $this->actingAs($admin);

    $component = Livewire::test(ImportReport::class, ['run' => $run->id])
        ->assertSee('0 of 2 entries processed');

    $run->forceFill(['counters' => ['vaults' => 1, 'folders' => 0, 'tags' => 0, 'entries' => 1, 'grants' => 0]])->save();

    // `refresh` is the method the `wire:poll` directive invokes.
    $component->call('refresh')->assertSee('1 of 2 entries processed');
});

it('ignores an invalid access-repeater row while it is being edited', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = vaultigen_staged_syspass_run($admin, import_queue_ir(), import_queue_plan($admin));
    $this->actingAs($admin);

    // Changing the repeater runs the live `afterStateUpdated` hook; an invalid
    // row must be ignored rather than block or error the plan.
    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->set('data.grants', [['principal_id' => 'not-a-uuid', 'permission' => 'editor']])
        ->assertOk();

    expect($run->refresh()->plan['grants'])->toBe([]);
});

it('warns when a queued import has no worker heartbeat', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin, status: ImportRun::STATUS_QUEUED);
    $this->actingAs($admin);

    $this->get(ImportReport::getUrl(['run' => $run->id]))
        ->assertOk()
        ->assertSee('Waiting for a queue worker');
});

it('runs a queued import whose driver needs no credentials from an empty envelope', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $run = vaultigen_planned_keepass_run($admin, $builder->build([$builder->entry('One', ['Password' => 'p'])]));
    app(ImportCredentialStore::class)->seal($run, []);
    $run->forceFill(['status' => ImportRun::STATUS_QUEUED, 'queued_at' => now(), 'progress_total' => 1])->save();

    ExecuteImportRun::dispatchSync($run->id);

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($run->wrapped_credentials)->toBeNull()
        ->and(Entry::query()->count())->toBe(1);
});

it('fails a queued run that has no credential envelope at all', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $run = vaultigen_planned_keepass_run($admin, $builder->build([$builder->entry('One')]));
    $run->forceFill(['status' => ImportRun::STATUS_QUEUED, 'queued_at' => now(), 'progress_total' => 1])->save();

    expect(fn () => ExecuteImportRun::dispatchSync($run->id))->toThrow(RuntimeException::class)
        ->and(Entry::query()->count())->toBe(0);
});

it('fails an import job that would write during a backup, but not during other windows', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_queue_run($admin);
    $maintenance = app(MaintenanceWindow::class);

    $backupOwner = (string) Str::uuid();
    $maintenance->begin('backup', $backupOwner, 'Database backup');
    expect(fn () => ExecuteImportRun::dispatchSync($run->id))->toThrow(MaintenanceActiveException::class);
    expect($run->refresh()->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($run->started_at)->toBeNull();
    $maintenance->endOwned('backup', $backupOwner);

    $other = import_queue_run($admin);
    $maintenance->begin('backup_retention', (string) Str::uuid(), 'Backup retention');
    ExecuteImportRun::dispatchSync($other->id);
    expect($other->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED);
});
