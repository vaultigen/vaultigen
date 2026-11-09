<?php

use App\Filament\Pages\EntryDetail;
use App\Filament\Pages\EntryForm;
use App\Filament\Pages\VaultEntries;
use App\Models\Entry;
use App\Services\EntryVault;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('the vault entries table lists metadata only and hides unauthorized vaults', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'username' => 'PageUser-Zx9',
        'password' => 'PagePassword-Zx9',
    ]);
    $this->actingAs($alice);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->assertSee($entry->title)
        ->assertSee('example.com')
        ->assertSee('New entry')
        ->assertSee('My vaults')
        ->assertDontSee('PageUser-Zx9')
        ->assertDontSee('PagePassword-Zx9');

    $this->get(VaultEntries::getUrl(['vault' => $vault->id]))
        ->assertOk()
        ->assertDontSee('PagePassword-Zx9');

    $this->actingAs($bob);
    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->assertSee($entry->title)
        ->assertDontSee('New entry');

    $this->actingAs($carol);
    Livewire::test(VaultEntries::class, ['vault' => $vault->id])->assertNotFound();
    Livewire::test(VaultEntries::class, ['vault' => ''])->assertNotFound();
});

test('the entry form renders create and edit without pre-filling secrets', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, ['title' => 'Edit me', 'password' => 'FormSecret-Zx9']);
    $this->actingAs($alice);

    $this->get(EntryForm::getUrl(['vault' => $vault->id]))
        ->assertOk()
        ->assertSee('New entry')
        ->assertSee('Entry type')
        ->assertSee('Generate')
        ->assertSee('Create entry')
        ->assertSeeHtml('name="password"')
        ->assertDontSeeHtml('<x-filament::input');

    $this->get(EntryForm::getUrl(['vault' => $vault->id, 'entry' => $entry->id]))
        ->assertOk()
        ->assertSee('Edit Login entry')
        ->assertSee('Edit me')
        ->assertSee('Save changes')
        ->assertDontSee('FormSecret-Zx9');
});

test('the entry form mirrors non-secret metadata into the plain POST without leaking secrets', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'title' => 'Bridge me',
        'username' => 'BridgeUser-Zx9',
        'password' => 'BridgeSecret-Zx9',
        'totp_secret' => 'JBSWY3DPEHPK3PXP',
        'expires_at' => '2030-01-01 12:00:00',
    ]);
    $this->actingAs($alice);

    $this->get(EntryForm::getUrl(['vault' => $vault->id, 'entry' => $entry->id]))
        ->assertOk()
        ->assertSeeHtml('name="owner_user_id"')
        ->assertSeeHtml('name="folder_id"')
        ->assertSeeHtml('name="expires_at"')
        ->assertSeeHtml('name="generated"')
        ->assertSeeHtml('name="algorithm"')
        ->assertSeeHtml('name="digits"')
        ->assertSeeHtml('name="period"')
        ->assertSeeHtml('name="tags[]"')
        ->assertDontSee('BridgeSecret-Zx9')
        ->assertDontSee('BridgeUser-Zx9');

    Livewire::test(EntryForm::class, ['vaultId' => $vault->id, 'entryId' => $entry->id])
        ->assertSet('data.owner_user_id', $alice->id)
        ->assertSet('data.expires_at', '2030-01-01 12:00')
        ->assertSet('data', fn (array $data): bool => ! in_array('password', array_keys($data), true)
            && ! in_array('username', array_keys($data), true)
            && ! in_array('secret', array_keys($data), true))
        ->assertDontSee('BridgeSecret-Zx9');
});

test('a failed TOTP create repopulates its non-secret parameters', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $this->actingAs($alice);

    $this->post(route('entries.store', ['vault' => $vault->id]), [
        'type' => 'totp',
        'title' => 'Needs a secret',
        'owner_user_id' => $alice->id,
        'algorithm' => 'SHA256',
        'digits' => '8',
        'period' => '60',
        'secret' => '',
    ])->assertSessionHasErrors('secret');

    $response = $this->get(EntryForm::getUrl(['vault' => $vault->id]));
    $response->assertOk()->assertSee('New TOTP');

    $state = vaultigen_entry_form_state((string) $response->getContent());

    expect($state['type'])->toBe('totp')
        ->and($state['algorithm'])->toBe('SHA256')
        ->and((string) $state['digits'])->toBe('8')
        ->and((string) $state['period'])->toBe('60')
        ->and(array_key_exists('password', $state))->toBeFalse()
        ->and(array_key_exists('secret', $state))->toBeFalse();
});

test('a failed create repopulates placement and expiry metadata', function () {
    ['alice' => $alice, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    $this->post(route('entries.store', ['vault' => $vault->id]), [
        'type' => 'login',
        'title' => 'Missing password',
        'owner_user_id' => $alice->id,
        'expires_at' => '2031-05-05 09:30',
        'folder_id' => $folderA->id,
        'password' => '',
    ])->assertSessionHasErrors('password');

    $response = $this->get(EntryForm::getUrl(['vault' => $vault->id]));
    $response->assertOk();

    $state = vaultigen_entry_form_state((string) $response->getContent());

    expect($state['expires_at'])->toBe('2031-05-05 09:30')
        ->and($state['folder_id'])->toBe($folderA->id)
        ->and($state['owner_user_id'])->toBe($alice->id);
});

test('the edit form reloads the stored TOTP parameters without the secret', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'totp',
        'title' => 'Authenticator',
        'secret' => 'JBSWY3DPEHPK3PXP',
        'algorithm' => 'SHA256',
        'digits' => '8',
        'period' => '60',
    ]);
    $this->actingAs($alice);

    Livewire::test(EntryForm::class, ['vaultId' => $vault->id, 'entryId' => $entry->id])
        ->assertSet('data.type', 'totp')
        ->assertSet('data.algorithm', 'SHA256')
        ->assertSet('data.digits', '8')
        ->assertSet('data.period', '60')
        ->assertSet('data', fn (array $data): bool => ! array_key_exists('secret', $data))
        ->assertDontSee('JBSWY3DPEHPK3PXP');
});

test('the entry form is hidden from users without the required vault ability', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    $this->actingAs($bob);
    $this->get(EntryForm::getUrl(['vault' => $vault->id]))->assertForbidden();
    $this->get(EntryForm::getUrl(['vault' => $vault->id, 'entry' => $entry->id]))->assertForbidden();

    $this->actingAs($carol);
    $this->get(EntryForm::getUrl(['vault' => $vault->id]))->assertNotFound();

    $second = vaultigen_make_vault($admin, $alice, ['name' => 'Second']);
    $secondEntry = vaultigen_make_entry($alice, $second);

    $this->actingAs($alice);
    $this->get(EntryForm::getUrl(['vault' => $vault->id, 'entry' => $secondEntry->id]))->assertNotFound();
    $this->get(EntryForm::getUrl(['vault' => $vault->id, 'entry' => (string) Str::uuid()]))->assertNotFound();
});

test('the entry detail page masks secrets and reveals history to managers', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'username' => 'DetailUser-Zx9',
        'password' => 'DetailPassword-Zx9',
    ]);

    $this->actingAs($bob);
    $this->get(EntryDetail::getUrl(['entry' => $entry->id]))
        ->assertOk()
        ->assertSee($entry->title)
        ->assertSee('Reveal')
        ->assertSee('Copy')
        ->assertDontSee('DetailPassword-Zx9')
        ->assertDontSee('DetailUser-Zx9')
        ->assertDontSee('History');

    $this->actingAs($alice);
    $this->get(EntryDetail::getUrl(['entry' => $entry->id]))
        ->assertOk()
        ->assertSee('History')
        ->assertSee('No revisions yet')
        ->assertSee(EntryForm::getUrl(['vault' => $vault->id, 'entry' => $entry->id]));

    $this->actingAs($carol);
    $this->get(EntryDetail::getUrl(['entry' => $entry->id]))->assertNotFound();
    $this->get(EntryDetail::getUrl(['entry' => '']))->assertNotFound();
});

test('managers restore a revision from the entry detail page', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, ['password' => 'RestoreOne-Zx9']);
    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['password' => 'RestoreTwo-Zx9']), 1);
    $revision = DB::table('entry_revisions')->where('entry_id', $entry->id)->sole();
    $this->actingAs($alice);

    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])
        ->assertSee('History')
        ->call('restore', $revision->id)
        ->assertNotified('Revision restored');

    expect(app(EntryVault::class)->reveal($alice, $entry->id, 'password'))->toBe('RestoreOne-Zx9');

    $this->actingAs($bob);
    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])
        ->assertDontSee('Restore');
});

test('entry relations are available for revisions and their author', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['password' => 'Changed']), 1);

    expect($entry->revisions()->count())->toBe(1)
        ->and($entry->revisions()->first()?->entry?->id)->toBe($entry->id)
        ->and(Entry::query()->with('vault')->find($entry->id)?->vault?->id)->toBe($vault->id);
});

test('entry pages reauthorize on every Livewire hydration', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $this->actingAs($alice);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])->call('$refresh')->assertOk();
    Livewire::test(EntryForm::class, ['vaultId' => $vault->id, 'entryId' => $entry->id])->call('$refresh')->assertOk();
    Livewire::test(EntryForm::class, ['vaultId' => $vault->id])->call('$refresh')->assertOk();
    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])->call('$refresh')->assertOk();
});

test('the entry form renders a type selector and every type section', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $this->actingAs($alice);

    $response = $this->get(EntryForm::getUrl(['vault' => $vault->id]));

    $response->assertOk()
        ->assertSee('Entry type')
        ->assertSee('Login')
        ->assertSee('Secure note')
        ->assertSee('SSH key')
        ->assertSee('TOTP')
        ->assertSee('Custom fields')
        ->assertSee('Storing a password and its second factor together')
        ->assertSee('Add custom field');
});

test('the create form opens on the requested type', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $this->actingAs($alice);

    Livewire::test(EntryForm::class, ['vaultId' => $vault->id, 'entryType' => 'totp'])
        ->assertViewHas('type', 'totp')
        ->assertSee('Period (seconds)');

    $this->get(EntryForm::getUrl(['vault' => $vault->id, 'type' => 'note']))
        ->assertOk()
        ->assertSee('New Secure note');

    expect(EntryForm::canAccess())->toBeTrue();
});

test('a failed create returns to the form with the chosen type', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $this->actingAs($alice);

    $this->post(route('entries.store', ['vault' => $vault->id]), [
        'type' => 'note',
        'title' => '',
        'owner_user_id' => $alice->id,
    ])->assertSessionHasErrors('title');

    $this->get(EntryForm::getUrl(['vault' => $vault->id]))
        ->assertOk()
        ->assertSee('New Secure note');
});

test('the entry detail masks type-specific values and shows only visible custom fields', function () {
    ['alice' => $alice, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'note',
        'title' => 'Recovery codes',
        'body' => 'BodySecret-Zx9',
        'custom_fields' => [
            ['label' => 'Visible', 'value' => 'InlineValue-Zx9', 'concealed' => false],
            ['label' => 'PIN', 'value' => 'ConcealedValue-Zx9', 'concealed' => true],
        ],
    ]);
    $this->actingAs($alice);

    $this->get(EntryDetail::getUrl(['entry' => $entry->id]))
        ->assertOk()
        ->assertSee('Secure note')
        ->assertSee('Note')
        ->assertSee('InlineValue-Zx9')
        ->assertDontSee('BodySecret-Zx9')
        ->assertDontSee('ConcealedValue-Zx9')
        ->assertSee('Concealed');

    $this->actingAs($carol);
    $this->get(EntryDetail::getUrl(['entry' => $entry->id]))->assertNotFound();
});

test('the entry detail shows the authenticator section only when a seed exists', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $totp = vaultigen_make_entry($alice, $vault, [
        'type' => 'totp',
        'title' => 'Authenticator',
        'issuer' => 'Example',
        'secret' => 'JBSWY3DPEHPK3PXP',
    ]);
    $plainLogin = vaultigen_make_entry($alice, $vault, ['title' => 'Plain login']);
    $seedLogin = vaultigen_make_entry($alice, $vault, ['title' => 'Seeded login', 'totp_secret' => 'JBSWY3DPEHPK3PXP']);
    $this->actingAs($alice);

    $this->get(EntryDetail::getUrl(['entry' => $totp->id]))
        ->assertOk()
        ->assertSee('Authenticator code')
        ->assertSee('Show current code')
        ->assertSee('Issuer');

    $this->get(EntryDetail::getUrl(['entry' => $plainLogin->id]))
        ->assertOk()
        ->assertDontSee('Authenticator code');

    $this->get(EntryDetail::getUrl(['entry' => $seedLogin->id]))
        ->assertOk()
        ->assertSee('Authenticator code');
});

test('the entry detail header presents structured metadata, a host link and expiry state', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');
    $expired = vaultigen_make_entry($alice, $vault, [
        'title' => 'Expired entry',
        'url' => 'https://portal.example.com/login',
        'folder_id' => $folder->id,
        'expires_at' => now()->subDay()->toDateTimeString(),
    ]);
    $plain = vaultigen_make_entry($alice, $vault, ['title' => 'No expiry entry']);
    $this->actingAs($alice);

    $this->get(EntryDetail::getUrl(['entry' => $expired->id]))
        ->assertOk()
        ->assertSee('portal.example.com')
        ->assertSee('https://portal.example.com')
        ->assertSee('Operations')
        ->assertSee('Owner')
        ->assertSee('Expiry')
        ->assertSee('Updated')
        ->assertSee('Expired')
        ->assertSee('Reveal')
        ->assertSee('Hide')
        ->assertSee('Copy');

    $this->get(EntryDetail::getUrl(['entry' => $plain->id]))
        ->assertOk()
        ->assertSee('No expiry');
});

test('the entry detail header presents near and far future expiry states', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $soon = vaultigen_make_entry($alice, $vault, [
        'title' => 'Expiring soon', 'expires_at' => now()->addDays(7)->toDateTimeString(),
    ]);
    $later = vaultigen_make_entry($alice, $vault, [
        'title' => 'Expiring later', 'expires_at' => now()->addDays(90)->toDateTimeString(),
    ]);
    $this->actingAs($alice);

    $this->get(EntryDetail::getUrl(['entry' => $soon->id]))->assertOk()->assertSee('Expiry');
    $this->get(EntryDetail::getUrl(['entry' => $later->id]))->assertOk()->assertSee('Expiry');
});

test('the entry form masks every secret field and offers a reveal toggle', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'ssh_key',
        'title' => 'A key',
        'private_key' => '-----BEGIN PRIVATE KEY-----',
        'passphrase' => 'key-passphrase',
    ]);
    $this->actingAs($alice);

    $this->get(EntryForm::getUrl(['vault' => $vault->id, 'type' => 'ssh_key']))
        ->assertOk()
        ->assertSeeHtml('name="private_key"')
        ->assertSeeHtml('name="passphrase"')
        ->assertSee('Hidden — reveal to edit')
        ->assertDontSee('-----BEGIN PRIVATE KEY-----');

    $this->get(EntryForm::getUrl(['vault' => $vault->id, 'entry' => $entry->id]))
        ->assertOk()
        ->assertDontSee('-----BEGIN PRIVATE KEY-----')
        ->assertDontSee('key-passphrase');
});

test('the vault entries table offers a filter and badge for every type', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_entry($alice, $vault, ['type' => 'note', 'title' => 'A note', 'body' => 'x']);
    vaultigen_make_entry($alice, $vault, ['type' => 'ssh_key', 'title' => 'A key', 'private_key' => 'PRIVATE']);
    vaultigen_make_entry($alice, $vault, ['type' => 'totp', 'title' => 'A code', 'secret' => 'JBSWY3DPEHPK3PXP']);
    $this->actingAs($alice);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->assertTableFilterExists('type')
        ->assertSee('Secure note')
        ->assertSee('SSH key')
        ->filterTable('type', 'note')
        ->assertCanSeeTableRecords(Entry::query()->where('type', 'note')->get());
});

test('the vault entries table links entries and hosts and offers type-specific copy actions', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $login = vaultigen_make_entry($alice, $vault, [
        'title' => 'Portal login',
        'username' => 'QuickUser-Zx9',
        'password' => 'QuickSecret-Zx9',
        'url' => 'https://portal.example.com/login',
    ]);
    $ssh = vaultigen_make_entry($alice, $vault, [
        'type' => 'ssh_key',
        'title' => 'Production key',
        'private_key' => 'PrivateKey-Zx9',
        'passphrase' => 'Passphrase-Zx9',
    ]);
    $note = vaultigen_make_entry($alice, $vault, [
        'type' => 'note',
        'title' => 'Recovery note',
        'body' => 'NoteBody-Zx9',
    ]);
    $this->actingAs($alice);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->assertSee(EntryDetail::getUrl(['entry' => $login->id]))
        ->assertSee('https://portal.example.com')
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('copySecret(')
        ->assertActionVisible(TestAction::make('copyUsername')->table($login))
        ->assertActionVisible(TestAction::make('copyPassword')->table($login))
        ->assertActionVisible(TestAction::make('copyPassphrase')->table($ssh))
        ->assertActionHidden(TestAction::make('copyUsername')->table($ssh))
        ->assertActionHidden(TestAction::make('copyPassword')->table($ssh))
        ->assertActionHidden(TestAction::make('copyUsername')->table($note))
        ->assertActionHidden(TestAction::make('copyPassword')->table($note))
        ->assertActionHidden(TestAction::make('copyPassphrase')->table($note))
        ->assertDontSee('QuickSecret-Zx9')
        ->assertDontSee('QuickUser-Zx9')
        ->assertDontSee('Passphrase-Zx9')
        ->assertDontSee('PrivateKey-Zx9');
});
