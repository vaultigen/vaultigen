<?php

use App\Models\User;
use App\Services\Imports\ImportPlanner;
use App\Services\Imports\Keepass\KeepassXmlDriver;
use App\Services\Imports\Keepass\KeepassXmlParser;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\KeepassXmlBuilder;

/**
 * Root entry, Work/Direct, Work/Servers/Deep, Home/Mail.
 *
 * @return array<string, mixed>
 */
function import_planner_keepass_ir(): array
{
    $builder = new KeepassXmlBuilder;
    $xml = $builder->build([
        $builder->entry('At root', [], ['tags' => 'loose']),
        $builder->group('Work', [
            $builder->entry('Direct'),
            $builder->group('Servers', [$builder->entry('Deep')], KeepassXmlBuilder::uuid('servers')),
        ], KeepassXmlBuilder::uuid('work')),
        $builder->group('Home', [$builder->entry('Mail')], KeepassXmlBuilder::uuid('home')),
    ], 'Team DB');

    return app(KeepassXmlParser::class)->parse($xml, 1024, 1024);
}

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function import_planner_resolve(array $input, User $admin): array
{
    return app(ImportPlanner::class)->resolve(app(KeepassXmlDriver::class), import_planner_keepass_ir(), [
        'single_vault_name' => 'KeePass',
        ...$input,
    ], $admin);
}

it('offers a single vault plus one vault layout per declared axis', function () {
    expect(app(ImportPlanner::class)->layouts(app(KeepassXmlDriver::class)))
        ->toBe(['vault_per_top_group', 'vault_per_subgroup', 'vault_per_group_path', 'single_vault']);
});

it('defaults keepass imports to a single vault with the group path as folders', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $plan = app(ImportPlanner::class)->defaults(app(KeepassXmlDriver::class), import_planner_keepass_ir(), $admin);

    expect($plan['layout'])->toBe('single_vault')
        ->and($plan['axes'])->toBe([
            'vault_axis' => 'none',
            'folder_axis' => 'group_path',
            'top_group' => 'skip',
            'subgroup' => 'skip',
            'group_path' => 'folder',
        ])
        ->and(array_column($plan['assignments'], 'folder_source'))->toBe([
            null,
            KeepassXmlBuilder::uuid('work'),
            KeepassXmlBuilder::uuid('servers'),
            KeepassXmlBuilder::uuid('home'),
        ]);
});

it('names the vault of root-level entries after the root group', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $plan = import_planner_resolve([
        'layout' => 'vault_per_top_group', 'vault_axis' => 'top_group', 'folder_axis' => 'subgroup',
        'top_group' => 'vault', 'subgroup' => 'folder', 'group_path' => 'skip',
    ], $admin);

    expect(array_column($plan['vaults'], 'name'))->toBe(['Team DB', 'Work', 'Home'])
        ->and(array_keys($plan['vaults']))->toBe(['top_group:', 'top_group:'.KeepassXmlBuilder::uuid('work'), 'top_group:'.KeepassXmlBuilder::uuid('home')])
        ->and(array_column($plan['assignments'], 'folder_source'))->toBe([null, null, KeepassXmlBuilder::uuid('servers'), null]);
});

it('gives root-level entries no axis tag', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $plan = import_planner_resolve([
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'top_group' => 'tag', 'subgroup' => 'tag', 'group_path' => 'skip',
    ], $admin);

    expect(array_column($plan['assignments'], 'tag_names'))->toBe([['loose'], ['Work'], ['Work', 'Servers'], ['Home']]);
});

it('rejects axes that exclude each other', function (array $input) {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    expect(fn () => import_planner_resolve($input, $admin))->toThrow(ValidationException::class);
})->with([
    'group path folders in a top group vault' => [[
        'layout' => 'vault_per_top_group', 'vault_axis' => 'top_group', 'folder_axis' => 'group_path',
        'top_group' => 'vault', 'subgroup' => 'skip', 'group_path' => 'folder',
    ]],
    'top group tag next to group path folders' => [[
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'group_path',
        'top_group' => 'tag', 'subgroup' => 'skip', 'group_path' => 'folder',
    ]],
    'two conflicting tag axes' => [[
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'top_group' => 'skip', 'subgroup' => 'tag', 'group_path' => 'tag',
    ]],
]);

it('normalizes a configuration so excluded axes are skipped and the changed axis wins', function () {
    $planner = app(ImportPlanner::class);
    $driver = app(KeepassXmlDriver::class);

    $perTopGroup = $planner->normalize($driver, ['layout' => 'vault_per_top_group', 'folder_axis' => 'group_path', 'group_path' => 'folder']);
    $newTagWins = $planner->normalize($driver, [
        'layout' => 'single_vault', 'folder_axis' => 'none', 'top_group' => 'tag', 'subgroup' => 'skip', 'group_path' => 'tag',
    ], 'group_path');

    expect($perTopGroup)->toBe([
        'layout' => 'vault_per_top_group', 'vault_axis' => 'top_group', 'folder_axis' => 'subgroup',
        'top_group' => 'vault', 'subgroup' => 'folder', 'group_path' => 'skip',
    ])->and($newTagWins)->toBe([
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'top_group' => 'skip', 'subgroup' => 'skip', 'group_path' => 'tag',
    ]);
});

it('falls back to the driver default layout for an unknown layout', function () {
    $normalized = app(ImportPlanner::class)->normalize(app(KeepassXmlDriver::class), ['layout' => 'vault_per_client']);

    expect($normalized)->toBe([
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'group_path',
        'top_group' => 'skip', 'subgroup' => 'skip', 'group_path' => 'folder',
    ]);
});

it('offers no folder axis that the vault axis excludes', function () {
    expect(app(ImportPlanner::class)->folderAxisChoices(app(KeepassXmlDriver::class), 'group_path'))->toBe(['none']);
});
