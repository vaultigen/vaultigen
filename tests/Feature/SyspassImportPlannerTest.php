<?php

use App\Models\User;
use App\Services\Imports\ImportIr;
use App\Services\Imports\ImportPlanner;
use App\Services\Imports\Syspass\SyspassXmlDriver;
use Illuminate\Validation\ValidationException;

function syspass_planner_ir(): array
{
    return vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('a1', ['name' => 'One', 'client_id' => '1', 'category_id' => '10', 'tag_ids' => ['7']]),
            vaultigen_syspass_account('a2', ['name' => 'Two', 'client_id' => '2', 'category_id' => '20', 'tag_ids' => ['7', '999']]),
            vaultigen_syspass_account('a3', ['name' => 'Three', 'client_id' => '1', 'category_id' => null, 'tag_ids' => []]),
        ],
        clients: [
            ['id' => '1', 'name' => 'Acme', 'description' => null],
            ['id' => '2', 'name' => 'Beta', 'description' => null],
        ],
        categories: [
            ['id' => '10', 'name' => 'Databases', 'description' => null],
            ['id' => '20', 'name' => '', 'description' => null],
        ],
        tags: [['id' => '7', 'name' => 'ssh']],
    );
}

it('defaults to a single vault with the category as a folder', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $driver = app(SyspassXmlDriver::class);
    $plan = app(ImportPlanner::class)->defaults($driver, $driver->canonicalIr(syspass_planner_ir()), $admin);

    expect($plan['layout'])->toBe('single_vault')
        ->and(array_keys($plan['vaults']))->toBe(['vault'])
        ->and($plan['vaults']['vault']['name'])->toBe('Imported passwords')
        ->and($plan['vaults']['vault']['entry_count'])->toBe(3)
        ->and($plan['assignments'][0]['folder_source'])->toBe('10')
        ->and($plan['assignments'][1]['folder_source'])->toBeNull();
});

it('plans one vault per client', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $plan = vaultigen_syspass_plan(syspass_planner_ir(), [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'category',
        'client' => 'vault',
        'category' => 'folder',
    ], $admin);

    expect(array_keys($plan['vaults']))->toBe(['client:1', 'client:2'])
        ->and($plan['vaults']['client:1']['name'])->toBe('Acme')
        ->and($plan['vaults']['client:1']['entry_count'])->toBe(2)
        ->and($plan['vaults']['client:1']['custodian_user_id'])->toBe($admin->id)
        ->and($plan['assignments'][0]['folder_source'])->toBe('10')
        ->and($plan['assignments'][1]['folder_source'])->toBeNull();

    $tags = collect($plan['tags'])->keyBy('name');
    expect($tags['ssh']['scope'])->toBe('global')
        ->and($tags['ssh']['vault_count'])->toBe(2)
        ->and($tags['ssh']['entry_count'])->toBe(2);
});

it('plans one vault per category with a placeholder for unnamed categories', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $plan = vaultigen_syspass_plan(syspass_planner_ir(), [
        'layout' => 'vault_per_category',
        'vault_axis' => 'category',
        'folder_axis' => 'client',
        'client' => 'folder',
        'category' => 'vault',
    ], $admin);

    expect(array_keys($plan['vaults']))->toBe(['category:10', 'category:20', 'category:'])
        ->and($plan['vaults']['category:10']['name'])->toBe('Databases')
        ->and($plan['vaults']['category:20']['name'])->toBe('Untitled')
        ->and($plan['vaults']['category:']['name'])->toBe('Untitled (2)')
        ->and($plan['assignments'][0]['folder_source'])->toBe('1')
        ->and($plan['assignments'][2]['folder_source'])->toBe('1');
});

it('auto-suffixes vault names that collide after normalization', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('a1', ['client_id' => '1']),
            vaultigen_syspass_account('a2', ['client_id' => '2']),
        ],
        clients: [
            ['id' => '1', 'name' => 'Same', 'description' => null],
            ['id' => '2', 'name' => 'same', 'description' => null],
        ],
    );

    $plan = vaultigen_syspass_plan($ir, [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'none',
        'client' => 'vault',
        'category' => 'skip',
    ], $admin);

    expect(array_column($plan['vaults'], 'name'))->toBe(['Same', 'same (2)']);
});

it('applies admin overrides, custodian, manager, and grants', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $viewer = User::factory()->withTwoFactor()->create();

    $plan = vaultigen_syspass_plan(syspass_planner_ir(), [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'none',
        'client' => 'vault',
        'category' => 'skip',
        'vaults' => [
            'client:1' => ['name' => 'Custom name', 'custodian_user_id' => $custodian->id, 'manager_user_id' => $manager->id],
        ],
        'grants' => [
            'not-a-grant',
            ['principal_id' => $viewer->principal_id, 'permission' => 'viewer'],
            ['principal_id' => $viewer->principal_id, 'permission' => 'viewer'],
        ],
    ], $admin);

    expect($plan['vaults']['client:1']['name'])->toBe('Custom name')
        ->and($plan['vaults']['client:1']['custodian_user_id'])->toBe($custodian->id)
        ->and($plan['vaults']['client:1']['manager_user_id'])->toBe($manager->id)
        ->and($plan['vaults']['client:2']['manager_user_id'])->toBe($admin->id)
        ->and($plan['grants'])->toBe([['principal_id' => $viewer->principal_id, 'permission' => 'viewer']]);
});

it('applies default custodian and manager to every vault and allows overrides', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $override = User::factory()->withTwoFactor()->create();

    $plan = vaultigen_syspass_plan(syspass_planner_ir(), [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'none',
        'client' => 'vault',
        'category' => 'skip',
        'default_custodian_user_id' => $custodian->id,
        'default_manager_user_id' => $manager->id,
        'vaults' => [
            'client:1' => ['manager_user_id' => $override->id],
        ],
    ], $admin);

    expect($plan['defaults'])->toBe(['custodian_user_id' => $custodian->id, 'manager_user_id' => $manager->id])
        ->and($plan['vaults']['client:1']['custodian_user_id'])->toBe($custodian->id)
        ->and($plan['vaults']['client:1']['manager_user_id'])->toBe($override->id)
        ->and($plan['vaults']['client:2']['custodian_user_id'])->toBe($custodian->id)
        ->and($plan['vaults']['client:2']['manager_user_id'])->toBe($manager->id);
});

it('rejects an unsupported grant permission', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $user = User::factory()->withTwoFactor()->create();

    expect(fn () => vaultigen_syspass_plan(syspass_planner_ir(), [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'category',
        'client' => 'skip',
        'category' => 'folder',
        'single_vault_name' => 'Vault',
        'grants' => [['principal_id' => $user->principal_id, 'permission' => 'owner']],
    ], $admin))->toThrow(ValidationException::class);
});

it('rejects invalid axis configurations', function (array $input) {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    expect(fn () => vaultigen_syspass_plan(syspass_planner_ir(), $input, $admin))
        ->toThrow(ValidationException::class);
})->with([
    'same vault and folder axis' => [[
        'layout' => 'vault_per_client', 'vault_axis' => 'client', 'folder_axis' => 'client',
        'client' => 'vault', 'category' => 'skip',
    ]],
    'layout mismatch' => [[
        'layout' => 'vault_per_client', 'vault_axis' => 'category', 'folder_axis' => 'client',
        'client' => 'folder', 'category' => 'vault',
    ]],
    'client destination invalid' => [[
        'layout' => 'vault_per_client', 'vault_axis' => 'client', 'folder_axis' => 'none',
        'client' => 'folder', 'category' => 'skip',
    ]],
    'category destination invalid' => [[
        'layout' => 'vault_per_client', 'vault_axis' => 'client', 'folder_axis' => 'category',
        'client' => 'vault', 'category' => 'vault',
    ]],
    'missing single vault name' => [[
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'category',
        'client' => 'skip', 'category' => 'folder',
    ]],
]);

it('applies tag scope overrides and bounds tag names', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = syspass_planner_ir();
    $ir['tags'][] = ['id' => '8', 'name' => str_repeat('a', 70)];
    $ir['accounts'][0]['tag_ids'] = ['7', '8'];

    $plan = vaultigen_syspass_plan($ir, [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'category',
        'client' => 'vault',
        'category' => 'folder',
        'tags' => [
            ['scope' => 'global'],
            ['name' => 'ssh', 'scope' => 'skip'],
            ['name' => 'ignored', 'scope' => 'nonsense'],
        ],
    ], $admin);

    $tags = collect($plan['tags'])->keyBy('name');

    expect(array_column($plan['tags'], 'name'))->toBe(['ssh', str_repeat('a', 60)])
        ->and($tags['ssh']['include'])->toBeFalse()
        ->and($tags[str_repeat('a', 60)]['include'])->toBeTrue()
        ->and($plan['assignments'][0]['tag_names'])->toBe([str_repeat('a', 60)])
        ->and($plan['assignments'][1]['tag_names'])->toBe([]);
});

it('excludes unchecked vaults and everything that would go into them', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('a1', ['client_id' => '1', 'tag_ids' => ['7']]),
            vaultigen_syspass_account('a2', ['client_id' => '2', 'tag_ids' => ['8']]),
        ],
        clients: [
            ['id' => '1', 'name' => 'Acme', 'description' => null],
            ['id' => '2', 'name' => 'Beta', 'description' => null],
        ],
        tags: [
            ['id' => '7', 'name' => 'shared'],
            ['id' => '8', 'name' => 'beta-only'],
        ],
    );

    $plan = vaultigen_syspass_plan($ir, [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'none',
        'client' => 'vault',
        'category' => 'skip',
        'vaults' => [
            'client:2' => ['include' => false],
        ],
    ], $admin);

    expect($plan['vaults']['client:1']['include'])->toBeTrue()
        ->and($plan['vaults']['client:2']['include'])->toBeFalse()
        ->and($plan['vaults']['client:2']['entry_count'])->toBe(1)
        ->and($plan['assignments'][0]['include'])->toBeTrue()
        ->and($plan['assignments'][1]['include'])->toBeFalse();

    // The tag used only by the excluded vault is not planned at all.
    expect(array_column($plan['tags'], 'name'))->toBe(['shared']);
    expect($plan['assignments'][1]['tag_names'])->toBe([]);
});

it('excludes unchecked tags from every assignment', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $plan = vaultigen_syspass_plan(syspass_planner_ir(), [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'none',
        'client' => 'skip',
        'category' => 'skip',
        'single_vault_name' => 'Vault',
        'tags' => [
            ['name' => 'ssh', 'scope' => 'global', 'include' => false],
        ],
    ], $admin);

    $tags = collect($plan['tags'])->keyBy('name');

    expect($tags['ssh']['include'])->toBeFalse()
        ->and($plan['assignments'][0]['tag_names'])->toBe([])
        ->and($plan['assignments'][1]['tag_names'])->toBe([]);
});

it('keeps syspass tags when both axes are skipped', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $plan = vaultigen_syspass_plan(syspass_planner_ir(), [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'none',
        'client' => 'skip',
        'category' => 'skip',
        'single_vault_name' => 'Vault',
    ], $admin);

    expect($plan['assignments'][0]['folder_source'])->toBeNull()
        ->and($plan['assignments'][0]['tag_names'])->toBe(['ssh'])
        ->and($plan['assignments'][1]['tag_names'])->toBe(['ssh']);
});

it('previews non-secret structure counts', function () {
    $ir = syspass_planner_ir();
    $ir['accounts'][] = vaultigen_syspass_account('a4', ['pass' => '']);
    $ir['tags'][] = 'not-a-tag';

    $preview = app(SyspassXmlDriver::class)->structure($ir);

    expect($preview['clients'])->toBe(2)
        ->and($preview['categories'])->toBe(2)
        ->and($preview['tags'])->toBe(2)
        ->and($preview['accounts'])->toBe(4)
        ->and($preview['empty_passwords'])->toBe(1)
        ->and($preview['meta']['generator'])->toBe('sysPass');
});

it('turns the category axis into a tag when configured', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $ir = syspass_planner_ir();
    $ir['tags'][] = 'not-a-tag';

    $plan = vaultigen_syspass_plan($ir, [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'client',
        'client' => 'folder',
        'category' => 'tag',
        'single_vault_name' => 'Everything',
    ], $admin);

    $tags = collect($plan['tags'])->keyBy('name');

    expect($tags->has('Databases'))->toBeTrue()
        ->and($plan['assignments'][0]['folder_source'])->toBe('1');
});

it('maps entity names and only array accounts to the canonical representation', function () {
    $ir = syspass_planner_ir();
    $ir['accounts'][] = 'not-an-account';

    $canonical = app(SyspassXmlDriver::class)->canonicalIr($ir);

    expect(ImportIr::entries($canonical))->toHaveCount(3)
        ->and(ImportIr::axisLabel($canonical, 'client', '1'))->toBe('Acme')
        ->and(ImportIr::axisLabel($canonical, 'client', 'missing'))->toBeNull()
        ->and(ImportIr::axisLabel($canonical, 'category', '20'))->toBeNull()
        ->and($canonical['entries'][0]['context'])->toBe('Acme')
        ->and($canonical['entries'][1]['tag_names'])->toBe(['ssh']);
});
