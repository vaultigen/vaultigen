<?php

use App\Models\Entry;
use App\Models\Folder;
use App\Models\ImportRun;
use App\Models\Tag;
use App\Models\User;
use App\Services\Imports\ImportResult;
use App\Services\Imports\ImportRunner;
use App\Services\Imports\ImportSourceStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

function syspass_runner_plan(array $ir, array $input, User $admin): array
{
    return vaultigen_syspass_plan($ir, $input, $admin);
}

function syspass_run(ImportRun $run, string $masterPassword = 'ignored'): ImportResult
{
    return app(ImportRunner::class)->run($run, ['master_password' => $masterPassword]);
}

it('imports vaults, folders, tags, grants, and entries', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $viewer = User::factory()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('a1', ['name' => 'One', 'client_id' => '1', 'category_id' => '10', 'tag_ids' => ['7'], 'pass' => 'secret-one']),
            vaultigen_syspass_account('a2', ['name' => 'Two', 'client_id' => '1', 'category_id' => '10', 'pass' => 'secret-two']),
            vaultigen_syspass_account('a3', ['name' => 'Three', 'client_id' => '2', 'pass' => 'secret-three']),
        ],
        clients: [['id' => '1', 'name' => 'Acme', 'description' => null], ['id' => '2', 'name' => 'Beta', 'description' => null]],
        categories: [['id' => '10', 'name' => 'Databases', 'description' => null]],
        tags: [['id' => '7', 'name' => 'ssh']],
    );
    $plan = syspass_runner_plan($ir, [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'category',
        'client' => 'vault',
        'category' => 'folder',
        'grants' => [['principal_id' => $viewer->principal_id, 'permission' => 'viewer']],
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->succeeded())->toBeTrue()
        ->and($result->counters)->toBe(['vaults' => 2, 'folders' => 1, 'tags' => 1, 'entries' => 3, 'grants' => 2, 'attachments' => 0, 'attachment_failures' => 0])
        ->and($result->failureCount)->toBe(0)
        ->and($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($run->created_vault_ids)->toHaveCount(2)
        ->and($run->random_storage_path)->toBeNull();

    $vaultOne = $run->created_vault_ids[0];
    expect(Folder::query()->where('vault_id', $vaultOne)->pluck('name')->all())->toBe(['Databases'])
        ->and(Tag::query()->where('vault_id', $vaultOne)->pluck('name')->all())->toBe(['ssh'])
        ->and(DB::table('vault_grants')->where('vault_id', $vaultOne)->whereNotNull('active_slot')->count())->toBe(2)
        ->and(Entry::query()->where('vault_id', $vaultOne)->count())->toBe(2);
});

it('records a partial password failure and continues', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(accounts: [
        vaultigen_syspass_account('a1', ['pass' => 'plain-secret']),
        vaultigen_syspass_account('a2', ['pass' => 'def50200bogus', 'key' => 'def10000bogus']),
    ]);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->succeeded())->toBeTrue()
        ->and($result->failureCount)->toBe(1)
        ->and($result->failures[0]['error_code'])->toBe('password_decrypt_failed')
        ->and($result->counters['entries'])->toBe(1);
});

it('treats an encrypted password without a key as a failure', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(accounts: [
        vaultigen_syspass_account('a1', ['pass' => 'def50200abc', 'key' => '']),
        vaultigen_syspass_account('a2', ['pass' => 'plain-secret']),
    ]);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->succeeded())->toBeTrue()
        ->and($result->failureCount)->toBe(1)
        ->and($result->failures[0]['error_code'])->toBe('password_decrypt_failed')
        ->and($result->counters['entries'])->toBe(1);
});

it('rejects a wrong master password and imports nothing', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    // More than the five-account sample, so the sample cap is exercised.
    $accounts = [];
    foreach (range(1, 6) as $i) {
        $accounts[] = vaultigen_syspass_account('a'.$i, ['pass' => 'def50200bogus', 'key' => 'def10000bogus']);
    }
    $ir = vaultigen_syspass_ir(accounts: $accounts);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run, 'wrong');

    expect($result->succeeded())->toBeFalse()
        ->and($result->errorCode)->toBe('master_password_rejected')
        ->and(Entry::query()->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(ImportRun::STATUS_FAILED);
});

it('records entry field failures without stopping the run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(accounts: [
        vaultigen_syspass_account('a1', ['name' => str_repeat('t', 201)]),
        vaultigen_syspass_account('a2', ['url' => 'https://example.com/'.str_repeat('u', 2048)]),
        vaultigen_syspass_account('a3', ['notes' => str_repeat('n', 20001)]),
        vaultigen_syspass_account('a4', ['pass' => str_repeat('p', 4097)]),
        vaultigen_syspass_account('a5', ['name' => 'Fine']),
    ]);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->failureCount)->toBe(4)
        ->and(array_column($result->failures, 'error_code'))->toBe([
            'title_too_long', 'url_too_long', 'notes_too_long', 'unknown',
        ])
        ->and($result->counters['entries'])->toBe(1);
});

it('records the client name alongside a failed entry', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('a1', ['name' => str_repeat('t', 201), 'client_id' => '1']),
        ],
        clients: [['id' => '1', 'name' => 'Acme']],
    );
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->failures[0]['context'])->toBe('Acme')
        ->and($result->failures[0]['kind'])->toBe('entry')
        ->and($result->failures[0]['error_code'])->toBe('title_too_long');
});

it('uses a placeholder title and allows an empty password', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(accounts: [
        vaultigen_syspass_account('a1', ['name' => '', 'pass' => '']),
    ]);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    $entry = Entry::query()->firstOrFail();
    expect($result->counters['entries'])->toBe(1)
        ->and($entry->title)->toBe('Untitled entry');
});

it('reuses an existing global tag and skips duplicate and failed tags', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    vaultigen_make_global_tag($admin, 'ssh');
    $ir = vaultigen_syspass_ir(accounts: [
        vaultigen_syspass_account('a1', ['tag_ids' => ['7']]),
    ], tags: [['id' => '7', 'name' => 'ssh']]);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'tag', 'category' => 'skip', 'single_vault_name' => 'Imported',
        'tags' => [['name' => 'ssh', 'scope' => 'global']],
    ], $admin);
    // Force a global entry that already exists plus one that cannot be created.
    $plan['tags'][] = ['name' => str_repeat('z', 70), 'scope' => 'global', 'entry_count' => 1, 'vault_count' => 1];
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->succeeded())->toBeTrue()
        ->and($result->counters['tags'])->toBe(0)
        ->and(Tag::query()->where('scope', Tag::SCOPE_GLOBAL)->count())->toBe(1)
        ->and(Entry::query()->firstOrFail()->tags()->count())->toBe(1);
});

it('records a vault failure for every entry when the vault cannot be created', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(accounts: [vaultigen_syspass_account('a1')]);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $plan['vaults']['vault']['custodian_user_id'] = (string) Str::uuid();
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->succeeded())->toBeTrue()
        ->and($result->failureCount)->toBe(1)
        ->and($result->failures[0]['error_code'])->toBe('vault_create_failed')
        ->and($result->counters['vaults'])->toBe(0);
});

it('records folder failures when the manager cannot create folders', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $inactive = User::factory()->withTwoFactor()->create(['is_active' => false]);
    $ir = vaultigen_syspass_ir(
        accounts: [vaultigen_syspass_account('a1', ['category_id' => '10'])],
        categories: [['id' => '10', 'name' => 'Databases', 'description' => null]],
    );
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'category',
        'client' => 'skip', 'category' => 'folder', 'single_vault_name' => 'Imported',
    ], $admin);
    $plan['vaults']['vault']['manager_user_id'] = $inactive->id;
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->succeeded())->toBeTrue()
        ->and($result->failureCount)->toBe(1)
        ->and($result->failures[0]['error_code'])->toBe('folder_name_invalid');
});

it('skips grants for the manager and custodian and survives an invalid principal', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $viewer = User::factory()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(accounts: [vaultigen_syspass_account('a1')]);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $plan['vaults']['vault']['manager_user_id'] = $manager->id;
    $plan['vaults']['vault']['custodian_user_id'] = $custodian->id;
    $plan['grants'] = [
        ['principal_id' => $manager->principal_id, 'permission' => 'manager'],
        ['principal_id' => $custodian->principal_id, 'permission' => 'viewer'],
        ['principal_id' => (string) Str::uuid(), 'permission' => 'viewer'],
        ['principal_id' => $viewer->principal_id, 'permission' => 'editor'],
    ];
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->counters['grants'])->toBe(1);
});

it('fails the run when the staged representation is unavailable', function (string $payload) {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = ImportRun::factory()->create(['requested_by_user_id' => $admin->id, 'status' => ImportRun::STATUS_STAGING]);
    $sealed = app(ImportSourceStore::class)->seal($run->id, $payload);
    $run->forceFill([...$sealed, 'status' => ImportRun::STATUS_STAGED, 'plan' => []])->save();

    $result = syspass_run($run->refresh());

    expect($result->succeeded())->toBeFalse()
        ->and($result->errorCode)->toBe('staging_unavailable')
        ->and($run->refresh()->status)->toBe(ImportRun::STATUS_FAILED);
})->with([
    'invalid json' => ['not-json'],
    'non-array json' => ['123'],
]);

it('caps the stored failure list while keeping the exact count', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $accounts = [];
    for ($i = 0; $i < ImportRunner::MAX_FAILURES + 1; $i++) {
        $accounts[] = vaultigen_syspass_account('a'.$i, ['pass' => str_repeat('p', 4097)]);
    }
    $ir = vaultigen_syspass_ir(accounts: $accounts);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->failureCount)->toBe(ImportRunner::MAX_FAILURES + 1)
        ->and($result->failures)->toHaveCount(ImportRunner::MAX_FAILURES);
});

it('persists progress while importing more than one batch of entries', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $accounts = [];
    for ($i = 0; $i < 26; $i++) {
        $accounts[] = vaultigen_syspass_account('a'.$i);
    }
    $ir = vaultigen_syspass_ir(accounts: $accounts);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->counters['entries'])->toBe(26)
        ->and($run->refresh()->counters['entries'])->toBe(26);
});

it('reuses one folder when two source values share a name', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('a1', ['category_id' => '10']),
            vaultigen_syspass_account('a2', ['category_id' => '11']),
        ],
        categories: [
            ['id' => '10', 'name' => 'Databases', 'description' => null],
            ['id' => '11', 'name' => 'databases', 'description' => null],
        ],
    );
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'category',
        'client' => 'skip', 'category' => 'folder', 'single_vault_name' => 'Imported',
    ], $admin);
    $plan['vaults']['bogus'] = 'not-a-vault-row';
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->counters['folders'])->toBe(1)
        ->and($result->counters['entries'])->toBe(2);
});

it('skips an excluded vault and the entries destined for it', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('a1', ['client_id' => '1']),
            vaultigen_syspass_account('a2', ['client_id' => '2']),
        ],
        clients: [
            ['id' => '1', 'name' => 'Acme', 'description' => null],
            ['id' => '2', 'name' => 'Beta', 'description' => null],
        ],
    );
    $plan = syspass_runner_plan($ir, [
        'layout' => 'vault_per_client', 'vault_axis' => 'client', 'folder_axis' => 'none',
        'client' => 'vault', 'category' => 'skip',
        'vaults' => ['client:2' => ['include' => false]],
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->succeeded())->toBeTrue()
        ->and($result->counters['vaults'])->toBe(1)
        ->and($result->counters['entries'])->toBe(1)
        ->and($result->failureCount)->toBe(0)
        ->and($run->refresh()->created_vault_ids)->toHaveCount(1)
        ->and(Entry::query()->count())->toBe(1);
});

it('does not create or attach an excluded tag', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(
        accounts: [vaultigen_syspass_account('a1', ['tag_ids' => ['7']])],
        tags: [['id' => '7', 'name' => 'ssh']],
    );
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
        'tags' => [['name' => 'ssh', 'scope' => 'local', 'include' => false]],
    ], $admin);
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->succeeded())->toBeTrue()
        ->and($result->counters['tags'])->toBe(0)
        ->and($result->counters['entries'])->toBe(1)
        ->and(Tag::query()->count())->toBe(0)
        ->and(Entry::query()->firstOrFail()->tags()->count())->toBe(0);
});

it('creates valid tags and skips invalid, orphan, or unreferenced tags', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $longLocal = str_repeat('l', 70);
    $ir = vaultigen_syspass_ir(accounts: [
        vaultigen_syspass_account('a1', ['name' => '', 'pass' => str_repeat('p', 4097)]),
        vaultigen_syspass_account('a2', ['tag_ids' => ['7']]),
        vaultigen_syspass_account('a3', ['tag_ids' => ['7']]),
    ], tags: [['id' => '7', 'name' => 'ssh']]);
    $plan = syspass_runner_plan($ir, [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
        'tags' => [
            ['name' => 'ssh', 'scope' => 'local'],
        ],
    ], $admin);
    $plan['tags'][] = ['name' => 'brandnew', 'scope' => 'global', 'entry_count' => 1, 'vault_count' => 1];
    $plan['tags'][] = ['name' => '   ', 'scope' => 'global', 'entry_count' => 1, 'vault_count' => 1];
    $plan['tags'][] = 'not-a-tag';
    $plan['tags'][] = ['name' => $longLocal, 'scope' => 'local', 'entry_count' => 1, 'vault_count' => 1];
    $plan['assignments'][1]['tag_names'][] = 42;
    $plan['assignments'][1]['tag_names'][] = 'ghost';
    $plan['assignments'][1]['tag_names'][] = $longLocal;
    $run = vaultigen_staged_syspass_run($admin, $ir, $plan);

    $result = syspass_run($run);

    expect($result->succeeded())->toBeTrue()
        ->and($result->counters['tags'])->toBe(2)
        ->and($result->failureCount)->toBe(1)
        ->and($result->failures[0]['title'])->toBe('Untitled entry')
        ->and($result->failures[0]['error_code'])->toBe('unknown');
});
