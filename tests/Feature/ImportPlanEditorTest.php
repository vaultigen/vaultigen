<?php

use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportPlanEditor;
use App\Services\Imports\ImportSourceStore;
use App\Services\Normalizer;

/**
 * A staged run with an encrypted source and an optional stored plan.
 *
 * @param  array<string, mixed>  $ir
 * @param  array<string, mixed>|null  $plan
 */
function plan_editor_staged(User $admin, array $ir, ?array $plan = null): ImportRun
{
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_STAGING,
        'plan' => $plan,
    ]);
    $sealed = app(ImportSourceStore::class)->seal($run->id, json_encode($ir, JSON_THROW_ON_ERROR));
    $run->forceFill([...$sealed, 'status' => ImportRun::STATUS_STAGED, 'plan' => $plan])->save();

    return $run->refresh();
}

/** @return array<string, mixed> */
function plan_editor_ir(): array
{
    return vaultigen_syspass_ir(
        accounts: [vaultigen_syspass_account('a1', ['tag_ids' => ['7']])],
        tags: [['id' => '7', 'name' => 'shared']],
    );
}

/**
 * A plan with malformed vault and tag rows, set directly on the run.
 *
 * @return array<string, mixed>
 */
function plan_editor_malformed_plan(): array
{
    return [
        'layout' => 'single_vault',
        'axes' => ['vault_axis' => 'none', 'folder_axis' => 'none', 'client' => 'skip', 'category' => 'skip'],
        'vaults' => [
            'vault' => ['name' => 'Imported passwords', 'include' => true],
            'bogus' => 'not-a-vault',
        ],
        'tags' => [
            ['name' => 'shared', 'scope' => 'local', 'include' => true],
            'not-a-tag',
            ['scope' => 'global'],
        ],
        'grants' => [],
    ];
}

it('skips malformed vault and tag rows when reading a plan', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_STAGED,
        'plan' => plan_editor_malformed_plan(),
    ]);

    $editor = app(ImportPlanEditor::class);
    $input = $editor->inputFromPlan($run);

    expect($editor->vaultRows($run))->toHaveCount(1)
        ->and($editor->tagRows($run))->toHaveCount(1)
        ->and($input['vaults'])->toHaveKey('vault')
        ->and($input['tags'])->toHaveCount(1)
        ->and($input['single_vault_name'])->toBe('Imported passwords');
});

it('falls back to the driver default plan when none is stored', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = plan_editor_staged($admin, plan_editor_ir());

    $editor = app(ImportPlanEditor::class);

    expect($editor->vaultRows($run))->not->toBeEmpty()
        ->and($editor->tagRows($run))->not->toBeEmpty();
});

it('applies a vault change to the plan', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = plan_editor_staged($admin, plan_editor_ir());

    app(ImportPlanEditor::class)->applyVaultChange($run, 'vault', ['name' => 'Renamed', 'include' => false]);

    $plan = $run->refresh()->plan;

    expect($plan['vaults']['vault']['name'])->toBe('Renamed')
        ->and($plan['vaults']['vault']['include'])->toBeFalse()
        ->and($plan['assignments'][0]['include'])->toBeFalse();
});

it('propagates a changed default to vaults without a per-vault override', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $first = User::factory()->withTwoFactor()->create();
    $second = User::factory()->withTwoFactor()->create();
    $pinned = User::factory()->withTwoFactor()->create();

    $ir = vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('a1', ['client_id' => '1']),
            vaultigen_syspass_account('a2', ['client_id' => '2']),
        ],
        clients: [
            ['id' => '1', 'name' => 'Acme'],
            ['id' => '2', 'name' => 'Beta'],
        ],
    );

    $plan = vaultigen_syspass_plan($ir, [
        'layout' => 'vault_per_client',
        'vault_axis' => 'client',
        'folder_axis' => 'none',
        'client' => 'vault',
        'category' => 'skip',
        'default_manager_user_id' => $first->id,
    ], $admin);

    $run = plan_editor_staged($admin, $ir, $plan);
    $editor = app(ImportPlanEditor::class);

    $editor->applyVaultChange($run, 'client:2', ['manager_user_id' => $pinned->id]);
    $editor->applyConfigAndGrants($run, ['default_manager_user_id' => $second->id], []);

    $stored = $run->refresh()->plan;

    expect($stored['defaults']['manager_user_id'])->toBe($second->id)
        ->and($stored['vaults']['client:1']['manager_user_id'])->toBe($second->id)
        ->and($stored['vaults']['client:2']['manager_user_id'])->toBe($pinned->id);
});

it('applies a tag change to the plan', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = plan_editor_staged($admin, plan_editor_ir());

    $normalized = app(Normalizer::class)->normalize('shared');
    app(ImportPlanEditor::class)->applyTagChange($run, $normalized, ['scope' => 'global', 'include' => false]);

    $plan = $run->refresh()->plan;
    $shared = collect($plan['tags'])->firstWhere('name', 'shared');

    expect($shared['scope'])->toBe('global')
        ->and($shared['include'])->toBeFalse();
});
