<?php

use App\Filament\Livewire\ImportTagsTable;
use App\Filament\Livewire\ImportVaultsTable;
use App\Filament\Pages\EntryDetail;
use App\Filament\Pages\ImportReport;
use App\Filament\Pages\Imports;
use App\Filament\Pages\ImportWizard;
use App\Models\Entry;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportPlanEditor;
use App\Services\Imports\ImportRunLifecycle;
use App\Services\Imports\ImportSourceStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fixtures\KeepassXmlBuilder;
use Tests\Fixtures\SyspassExportBuilder;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

/**
 * @param  array<string, mixed>  $ir
 * @param  array<string, mixed>|null  $plan
 */
function import_ui_stage(User $admin, array $ir, ?array $plan = null): ImportRun
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

/**
 * @return array<string, mixed>
 */
function import_ui_ir(): array
{
    return vaultigen_syspass_ir(
        accounts: [
            vaultigen_syspass_account('1', ['client_id' => '1', 'category_id' => '10', 'tag_ids' => ['5']]),
            vaultigen_syspass_account('2', ['client_id' => '2', 'category_id' => '20', 'tag_ids' => ['5']]),
        ],
        clients: [
            ['id' => '1', 'name' => 'Acme'],
            ['id' => '2', 'name' => 'Beta'],
        ],
        categories: [
            ['id' => '10', 'name' => 'Databases'],
            ['id' => '20', 'name' => 'Email'],
        ],
        tags: [
            ['id' => '5', 'name' => 'shared'],
        ],
    );
}

function import_ui_upload(SyspassExportBuilder $builder, string $filename = 'syspass-export.xml'): UploadedFile
{
    $accounts = '  <Accounts>'.PHP_EOL
        .$builder->account('1', 'Example', '1', '10', 'alice', 'https://example.com', 'note one', 'secret-one')
        .'  </Accounts>'.PHP_EOL;

    $xml = $builder->build([
        $builder->simpleFragment('Categories', 'Category', [10 => 'Databases']),
        $builder->simpleFragment('Clients', 'Client', [1 => 'Acme']),
        $accounts,
    ], ['Generator' => 'sysPass']);

    return UploadedFile::fake()->createWithContent($filename, $xml);
}

test('the wizard normalizes axes and rejects an invalid plan', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->set('data.layout', 'vault_per_category')
        ->assertSet('data.vault_axis', 'category')
        ->set('data.folder_axis', 'category')
        ->assertSet('data.folder_axis', 'client')
        ->set('data.layout', 'single_vault')
        ->assertSet('data.vault_axis', 'none')
        ->set('data.single_vault_name', '')
        ->call('save')
        ->assertNotified('The import plan is not valid');
});

test('the vault table lists the planned vaults and can skip one', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportVaultsTable::class, ['run' => $run->id])
        ->assertSee('Imported passwords')
        ->call('updateTableColumnState', 'include', '0', false)
        ->assertOk();

    $plan = $run->refresh()->plan;

    expect($plan['vaults']['vault']['include'])->toBeFalse()
        ->and($plan['assignments'][0]['include'])->toBeFalse()
        ->and($plan['tags'])->toBe([]);
});

test('the vault table can rename a vault inline', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportVaultsTable::class, ['run' => $run->id])
        ->call('updateTableColumnState', 'name', '0', 'Renamed vault')
        ->assertOk();

    expect($run->refresh()->plan['vaults']['vault']['name'])->toBe('Renamed vault');
});

test('the tag table lists the planned tags and can skip one', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportTagsTable::class, ['run' => $run->id])
        ->assertSee('shared')
        ->set('tableSearch', 'shared')
        ->call('updateTableColumnState', 'include', '0', false)
        ->assertOk();

    $plan = $run->refresh()->plan;
    $shared = collect($plan['tags'])->firstWhere('name', 'shared');

    expect($shared['include'])->toBeFalse()
        ->and($plan['assignments'][0]['tag_names'])->not->toContain('shared');
});

test('the tag table can change a tag scope inline', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportTagsTable::class, ['run' => $run->id])
        ->set('tableSearch', 'shared')
        ->call('updateTableColumnState', 'scope', '0', 'global')
        ->assertOk();

    $plan = $run->refresh()->plan;
    $shared = collect($plan['tags'])->firstWhere('name', 'shared');

    expect($shared['scope'])->toBe('global');
});

test('the vault table searches and sorts the plan rows', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportVaultsTable::class, ['run' => $run->id])
        ->set('tableSearch', 'Imported')
        ->assertOk()
        ->call('sortTable', 'entry_count', 'desc')
        ->assertOk();
});

test('the vault table refreshes and can change custodian and manager inline', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $other = User::factory()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportVaultsTable::class, ['run' => $run->id])
        ->call('refreshPlan')
        ->assertOk()
        ->call('updateTableColumnState', 'custodian_user_id', '0', $other->id)
        ->assertOk()
        ->call('updateTableColumnState', 'manager_user_id', '0', $other->id)
        ->assertOk();

    $plan = $run->refresh()->plan;

    expect($plan['vaults']['vault']['custodian_user_id'])->toBe($other->id)
        ->and($plan['vaults']['vault']['manager_user_id'])->toBe($other->id);
});

test('the tag table refreshes and sorts the plan rows', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportTagsTable::class, ['run' => $run->id])
        ->call('refreshPlan')
        ->assertOk()
        ->call('sortTable', 'entry_count', 'desc')
        ->assertOk();
});

test('the wizard stores a default plan on mount so confirm can run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->assertSet('planSaved', true)
        ->call('onPlanUpdated');

    expect($run->refresh()->plan)->not->toBeNull();
});

test('the wizard renders the access repeater and stores its grants', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->set('data.grants', [['principal_id' => $admin->principal_id, 'permission' => 'viewer']])
        ->call('save')
        ->assertNotified('Import plan saved');

    expect($run->refresh()->plan['grants'])->toBeArray()->not->toBeEmpty();
});

test('the wizard applies default custodian and manager to the vault plan', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->set('data.default_custodian_user_id', $custodian->id)
        ->set('data.default_manager_user_id', $manager->id)
        ->call('save')
        ->assertNotified('Import plan saved');

    $plan = $run->refresh()->plan;

    expect($plan['defaults'])->toEqual(['custodian_user_id' => $custodian->id, 'manager_user_id' => $manager->id])
        ->and($plan['vaults']['vault']['custodian_user_id'])->toBe($custodian->id)
        ->and($plan['vaults']['vault']['manager_user_id'])->toBe($manager->id);
});

test('the wizard confirm step posts the master password through a plain form', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    $html = Livewire::test(ImportWizard::class, ['run' => $run->id])->html();

    expect($html)
        ->toContain('id="execute-import-form"')
        ->toContain('id="master_password"')
        ->not->toContain('wire:model="data.master_password"')
        ->not->toContain('cancel-import-form')
        ->and(substr_count($html, 'form="execute-import-form"'))->toBeGreaterThanOrEqual(2);
});

test('the import hub lists runs and renders the upload form', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    import_ui_stage($admin, import_ui_ir());
    ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'source_format' => 'keepass_xml',
        'status' => ImportRun::STATUS_COMPLETED,
        'counters' => ['vaults' => 1, 'entries' => 3],
        'failure_count' => 2,
    ]);

    $this->actingAs($admin)
        ->get(Imports::getUrl())
        ->assertOk()
        ->assertSee('New import')
        ->assertSee('Import history')
        ->assertSee('Past and in-progress imports.')
        ->assertSee('keepass_xml')
        ->assertSee('3 entries in 1 vaults, 2 failed');

    expect(Imports::getNavigationBadge())->toBe('1');
});

test('the import navigation badge hides without staged runs or permission', function () {
    expect(Imports::getNavigationBadge())->toBeNull();

    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    expect(Imports::getNavigationBadge())->toBeNull();

    ImportRun::factory()->create(['requested_by_user_id' => $admin->id, 'status' => ImportRun::STATUS_COMPLETED]);
    expect(Imports::getNavigationBadge())->toBeNull();
});

test('the hub cancels a staged run and reports a finished run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(Imports::class)
        ->callTableAction('cancel', $run)
        ->assertNotified('Import cancelled and staging removed');

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_CANCELLED)
        ->and($run->random_storage_path)->toBeNull();
});

test('the cancel route refuses a finished run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_COMPLETED,
    ]);
    $this->actingAs($admin);

    $this->from(Imports::getUrl())
        ->post(route('imports.cancel', ['run' => $run->id]))
        ->assertRedirect(Imports::getUrl())
        ->assertSessionHasErrors('run');
});

test('a staged run can be cancelled through the route', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    $this->post(route('imports.cancel', ['run' => $run->id]))
        ->assertRedirect(Imports::getUrl());

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_CANCELLED)
        ->and($run->random_storage_path)->toBeNull();
});

test('the report livewire component rehydrates for a terminal run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_COMPLETED,
        'counters' => ['vaults' => 0, 'entries' => 0],
    ]);
    $this->actingAs($admin);

    Livewire::test(ImportReport::class, ['run' => $run->id])
        ->call('$refresh')
        ->assertSee('Import report');
});

test('a staged upload is stored and the wizard is opened', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $file = import_ui_upload(new SyspassExportBuilder('export-pass', 'master-pass'));

    $response = $this->actingAs($admin)->post(route('imports.upload'), [
        'source' => 'syspass_xml',
        'file' => $file,
        'export_password' => 'export-pass',
    ]);

    $run = ImportRun::query()->sole();
    $response->assertRedirect(ImportWizard::getUrl(['run' => $run->id]));

    expect($run->status)->toBe(ImportRun::STATUS_STAGED)
        ->and($run->random_storage_path)->toStartWith('imports/');
});

test('a wrong export password is rejected without a run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $file = import_ui_upload(new SyspassExportBuilder('export-pass'));

    $this->actingAs($admin)
        ->from(Imports::getUrl())
        ->post(route('imports.upload'), [
            'source' => 'syspass_xml',
            'file' => $file,
            'export_password' => 'wrong',
        ])
        ->assertRedirect(Imports::getUrl())
        ->assertSessionHasErrors('file');

    expect(ImportRun::query()->count())->toBe(0);
});

test('the wizard renders the staged review and saves a plan', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->assertSee('Review import')
        ->assertSee('Setup')
        ->assertSee('What will be imported')
        ->assertSee('Accounts')
        ->assertSee('Layout and axes')
        ->assertSee('Additional access')
        ->assertDontSee('syspass-export.xml')
        ->call('save')
        ->assertNotified('Import plan saved');

    expect($run->refresh()->plan)->not->toBeNull()
        ->and($run->layout)->toBe('single_vault');
});

test('changing the layout resolves new vaults and skips invalid choices', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->set('data.layout', 'vault_per_client')
        ->assertSet('data.vault_axis', 'client')
        ->set('data.folder_axis', 'none')
        ->set('data.category', 'skip')
        ->call('save')
        ->assertNotified('Import plan saved');

    $plan = $run->refresh()->plan;
    expect($plan['axes']['vault_axis'])->toBe('client')
        ->and($plan['axes']['folder_axis'])->toBe('none')
        ->and($plan['vaults'])->toHaveKeys(['client:1', 'client:2']);
});

test('the wizard follows a run plan when one is already stored', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $plan = vaultigen_syspass_plan(import_ui_ir(), [
        'layout' => 'vault_per_category',
        'vault_axis' => 'category',
        'folder_axis' => 'client',
        'client' => 'folder',
        'category' => 'vault',
    ], $admin);
    $run = import_ui_stage($admin, import_ui_ir(), $plan);

    $this->actingAs($admin);

    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->assertSet('data.layout', 'vault_per_category')
        ->assertSet('planSaved', true)
        ->assertSee('Confirm and import');
});

test('executing an import writes entries and the report links the vaults', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $plan = vaultigen_syspass_plan(import_ui_ir(), [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'category',
        'client' => 'skip',
        'category' => 'folder',
        'single_vault_name' => 'Imported',
    ], $admin);
    $run = import_ui_stage($admin, import_ui_ir(), $plan);
    $this->actingAs($admin);

    $this->post(route('imports.execute', ['run' => $run->id]), ['master_password' => ''])
        ->assertRedirect(ImportReport::getUrl(['run' => $run->id]));

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and(Entry::query()->count())->toBe(2);

    $this->get(ImportReport::getUrl(['run' => $run->id]))
        ->assertOk()
        ->assertSee('Created vaults')
        ->assertSee('Imported')
        ->assertSee('2 entries imported into 1 vaults; 0 failed.');
});

test('a wrong master password is reported as a failed run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $file = import_ui_upload(new SyspassExportBuilder('export-pass', 'master-pass'));
    $this->actingAs($admin);

    $this->post(route('imports.upload'), [
        'source' => 'syspass_xml',
        'file' => $file,
        'export_password' => 'export-pass',
    ]);

    $run = ImportRun::query()->sole();
    app(ImportPlanEditor::class)->plan($run, [
        'layout' => 'single_vault',
        'vault_axis' => 'none',
        'folder_axis' => 'category',
        'client' => 'skip',
        'category' => 'folder',
        'single_vault_name' => 'Imported',
    ]);

    $this->post(route('imports.execute', ['run' => $run->id]), ['master_password' => 'wrong'])
        ->assertRedirect(ImportReport::getUrl(['run' => $run->id]));

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('master_password_rejected');

    $this->get(ImportReport::getUrl(['run' => $run->id]))
        ->assertOk()
        ->assertSee('The master password was rejected; nothing was imported.');
});

test('the report redacts failures and shows a stored error code', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_COMPLETED,
        'counters' => ['vaults' => 1, 'entries' => 5],
        'failures' => [
            ['source_id' => '1', 'title' => 'Example', 'client' => 'Acme', 'error_code' => 'title_too_long'],
            ['source_id' => '2', 'title' => 'Other', 'client' => 'Beta', 'error_code' => 'custom_code'],
        ],
        'failure_count' => 10,
    ]);
    $this->actingAs($admin);

    $this->get(ImportReport::getUrl(['run' => $run->id]))
        ->assertOk()
        ->assertSee('Example')
        ->assertSee('Acme')
        ->assertSee('Other')
        ->assertSee('The title is too long.')
        ->assertSee('custom_code')
        ->assertSee('Only the first stored failures are listed');
});

test('the report shows a continue link for a run that is not terminal', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = import_ui_stage($admin, import_ui_ir());
    $this->actingAs($admin);

    $this->get(ImportReport::getUrl(['run' => $run->id]))
        ->assertOk()
        ->assertSee('Continue reviewing this import');
});

test('import routes and pages are denied to non-administrators', function () {
    $user = User::factory()->withTwoFactor()->create();
    $run = ImportRun::factory()->create(['requested_by_user_id' => $user->id, 'status' => ImportRun::STATUS_STAGED]);

    $this->actingAs($user);

    $this->get(Imports::getUrl())->assertForbidden();
    $this->get(ImportWizard::getUrl(['run' => $run->id]))->assertForbidden();
    $this->get(ImportReport::getUrl(['run' => $run->id]))->assertForbidden();

    $this->post(route('imports.upload'), [
        'source' => 'syspass_xml',
        'export_password' => 'x',
    ])->assertForbidden();

    $this->post(route('imports.execute', ['run' => $run->id]), ['master_password' => 'x'])
        ->assertForbidden();

    $this->post(route('imports.cancel', ['run' => $run->id]))->assertForbidden();
});

test('import pages reject non-import sources and terminal wizard runs', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    $this->post(route('imports.upload'), [
        'source' => 'unknown_source',
        'file' => import_ui_upload(new SyspassExportBuilder('export-pass')),
        'export_password' => 'x',
    ])->assertNotFound();

    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_COMPLETED,
    ]);

    $this->get(ImportWizard::getUrl(['run' => $run->id]))
        ->assertRedirect(ImportReport::getUrl(['run' => $run->id]));

    $this->post(route('imports.execute', ['run' => $run->id]), ['master_password' => 'x'])
        ->assertRedirect(ImportReport::getUrl(['run' => $run->id]));
});

test('cancelling a finished run is rejected by the lifecycle service', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'status' => ImportRun::STATUS_COMPLETED,
    ]);

    expect(fn () => app(ImportRunLifecycle::class)->cancel($run, $admin))
        ->toThrow(ValidationException::class);
});

test('the hub shows each source with only its own credential fields', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->get(Imports::getUrl())
        ->assertSee('KeePass / KeePassXC (XML export)')
        ->assertSee('The XML file is not encrypted: delete it once the import is done.')
        ->assertSeeHtml('action="'.route('imports.upload').'"')
        ->assertSeeHtml('x-show="(details[source]?.fields ?? []).includes(&#039;export_password&#039;)"')
        ->assertSeeHtml('x-bind:disabled="! (details[source]?.fields ?? []).includes(&#039;export_password&#039;)"')
        ->assertDontSee('id="master_password"', false);
});

test('a keepass upload is staged without a password and opens the wizard', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $file = UploadedFile::fake()->createWithContent('team.xml', $builder->build([$builder->entry('One')]));

    $response = $this->actingAs($admin)->post(route('imports.upload'), [
        'source' => 'keepass_xml',
        'file' => $file,
    ]);

    $run = ImportRun::query()->sole();
    $response->assertRedirect(ImportWizard::getUrl(['run' => $run->id]));
    expect($run->source_format)->toBe('keepass_xml')
        ->and($run->status)->toBe(ImportRun::STATUS_STAGED);
});

test('an unreadable keepass upload is rejected with its reason', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->from(Imports::getUrl())
        ->post(route('imports.upload'), [
            'source' => 'keepass_xml',
            'file' => UploadedFile::fake()->createWithContent('other.xml', '<Root/>'),
        ])
        ->assertRedirect(Imports::getUrl())
        ->assertSessionHasErrors(['file' => 'The file is not a KeePass 2.x or KeePassXC XML export.']);

    expect(ImportRun::query()->count())->toBe(0);
});

test('the keepass wizard offers group layouts and needs no password to confirm', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $run = vaultigen_staged_keepass_run($admin, $builder->build([$builder->group('Work', [$builder->entry('One')])]));
    $this->actingAs($admin);

    Livewire::test(ImportWizard::class, ['run' => $run->id])
        ->assertSet('data.layout', 'single_vault')
        ->assertSet('data.folder_axis', 'group_path')
        ->assertSee('Group path destination')
        ->assertSee('This source needs no password.')
        ->assertDontSeeHtml('id="master_password"')
        ->set('data.layout', 'vault_per_top_group')
        ->assertSet('data.folder_axis', 'subgroup')
        ->assertSet('data.group_path', 'skip')
        ->set('data.top_group', 'tag')
        ->assertSet('data.top_group', 'vault');

    expect($run->refresh()->plan['axes']['vault_axis'])->toBe('top_group');
});

test('executing a keepass import seals an empty credential envelope and imports', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $run = vaultigen_planned_keepass_run($admin, $builder->build([$builder->entry('One'), $builder->entry('Two')]));
    $this->actingAs($admin);

    $this->post(route('imports.execute', ['run' => $run->id]), ['master_password' => 'ignored'])
        ->assertRedirect(ImportReport::getUrl(['run' => $run->id]));

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($run->wrapped_credentials)->toBeNull()
        ->and(Entry::query()->count())->toBe(2);
});

test('a run staged in the original syspass format still imports after the refactor', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $legacyPlan = [
        'layout' => 'vault_per_client',
        'axes' => ['vault_axis' => 'client', 'folder_axis' => 'category', 'client' => 'vault', 'category' => 'folder'],
        'defaults' => ['custodian_user_id' => $admin->id, 'manager_user_id' => $admin->id],
        'grants' => [],
        'vaults' => [
            'client:1' => ['name' => 'Acme', 'custodian_user_id' => $admin->id, 'manager_user_id' => $admin->id, 'entry_count' => 1, 'include' => true],
            'client:2' => ['name' => 'Beta', 'custodian_user_id' => $admin->id, 'manager_user_id' => $admin->id, 'entry_count' => 1, 'include' => false],
        ],
        'tags' => [['name' => 'shared', 'scope' => 'local', 'entry_count' => 1, 'vault_count' => 1, 'include' => true]],
        'assignments' => [
            ['vault_key' => 'client:1', 'include' => true, 'folder_source' => '10', 'tag_names' => ['shared']],
            ['vault_key' => 'client:2', 'include' => false, 'folder_source' => '20', 'tag_names' => ['shared']],
        ],
    ];
    $run = import_ui_stage($admin, import_ui_ir(), $legacyPlan);
    $this->actingAs($admin);

    $this->post(route('imports.execute', ['run' => $run->id]), ['master_password' => ''])
        ->assertRedirect(ImportReport::getUrl(['run' => $run->id]));

    $entry = Entry::query()->sole();
    expect($run->refresh()->status)->toBe(ImportRun::STATUS_COMPLETED)
        ->and($run->counters)->toMatchArray(['vaults' => 1, 'folders' => 1, 'tags' => 1, 'entries' => 1])
        ->and($entry->title)->toBe('Account 1')
        ->and($entry->folder?->name)->toBe('Databases');
});

test('a syspass staging object with an unknown schema version fails the run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $plan = vaultigen_syspass_plan(import_ui_ir(), [
        'layout' => 'single_vault', 'vault_axis' => 'none', 'folder_axis' => 'none',
        'client' => 'skip', 'category' => 'skip', 'single_vault_name' => 'Imported',
    ], $admin);
    $run = import_ui_stage($admin, [...import_ui_ir(), 'schema' => 2], $plan);
    $this->actingAs($admin);

    $this->post(route('imports.execute', ['run' => $run->id]), ['master_password' => '']);

    expect($run->refresh()->status)->toBe(ImportRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('staging_unavailable')
        ->and(Entry::query()->count())->toBe(0);
});

test('the report lists attachment failures by position with a link to the entry', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $entryId = (string) Str::uuid();
    $run = ImportRun::factory()->create([
        'requested_by_user_id' => $admin->id,
        'source_format' => 'keepass_xml',
        'status' => ImportRun::STATUS_COMPLETED,
        'counters' => ['vaults' => 1, 'entries' => 1, 'attachments' => 2, 'attachment_failures' => 1],
        'failures' => [[
            'kind' => 'attachment', 'source_id' => 'u1', 'title' => 'With files', 'context' => 'Work / Servers',
            'error_code' => 'invalid_base64', 'entry_id' => $entryId, 'index' => 3, 'of' => 3,
        ]],
        'failure_count' => 0,
    ]);
    $this->actingAs($admin);

    $this->get(ImportReport::getUrl(['run' => $run->id]))
        ->assertSee('1 entries imported into 1 vaults; 0 failed.')
        ->assertSee('2 attachments imported; 1 attachments missing.')
        ->assertSee('Attachment 3 of 3')
        ->assertSee('Work / Servers')
        ->assertSee('Group')
        ->assertSee('The attachment data is not valid base64.')
        ->assertSeeHtml('href="'.e(EntryDetail::getUrl(['entry' => $entryId])).'"');
});
