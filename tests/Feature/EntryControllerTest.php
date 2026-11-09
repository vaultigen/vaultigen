<?php

use App\Filament\Pages\EntryDetail;
use App\Filament\Pages\EntryForm;
use App\Models\Entry;
use App\Services\EntryVault;
use Illuminate\Support\Facades\DB;

test('the store endpoint creates an encrypted entry and redirects to it', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $this->actingAs($alice);

    $response = $this->post(route('entries.store', ['vault' => $vault->id]), [
        'title' => 'HTTP login',
        'owner_user_id' => $alice->id,
        'expires_at' => '',
        'generated' => '0',
        'username' => 'http-user',
        'password' => 'HttpSecret-Zx9',
        'url' => 'https://http.example.com/login',
        'notes' => 'created over http',
    ]);

    $entry = Entry::query()->sole();
    $response->assertRedirect(EntryDetail::getUrl(['entry' => $entry->id]));

    expect($entry->url_host)->toBe('http.example.com')
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'password'))->toBe('HttpSecret-Zx9')
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'username'))->toBe('http-user');
});

test('the store endpoint resolves submitted tag input to tag ids', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $tag = vaultigen_make_tag($alice, $vault, 'Production');
    $this->actingAs($alice);

    $this->post(route('entries.store', ['vault' => $vault->id]), [
        'title' => 'Tagged over http',
        'owner_user_id' => $alice->id,
        'expires_at' => '',
        'generated' => '0',
        'username' => 'tagged',
        'password' => 'TaggedSecret-Zx9',
        'url' => '',
        'notes' => '',
        'tags' => [$tag->id, 'Fresh tag'],
    ])->assertRedirect();

    $entry = Entry::query()->where('title', 'Tagged over http')->sole();
    expect(DB::table('entry_tag')->where('entry_id', $entry->id)->count())->toBe(2);
});

test('a failed create redirects back without flashing typed secrets', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $this->actingAs($alice);

    $response = $this->post(route('entries.store', ['vault' => $vault->id]), [
        'title' => '',
        'owner_user_id' => $alice->id,
        'username' => 'should-not-flash-user',
        'password' => 'ShouldNotFlash-Secret',
    ]);

    $response->assertRedirect(EntryForm::getUrl(['vault' => $vault->id]));
    $response->assertSessionHasErrors('title');

    /** @var array<string, mixed> $old */
    $old = session('_old_input');
    expect(array_key_exists('password', $old))->toBeFalse()
        ->and(array_key_exists('username', $old))->toBeFalse()
        ->and($old['owner_user_id'] ?? null)->toBe($alice->id);
    expect(Entry::query()->count())->toBe(0);
});

test('the update endpoint saves changes and reports stale edits', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, ['password' => 'OriginalPassword']);
    $this->actingAs($alice);

    $response = $this->put(route('entries.update', ['vault' => $vault->id, 'entry' => $entry->id]), [
        'title' => 'Updated over http',
        'owner_user_id' => $alice->id,
        'expires_at' => '',
        'generated' => '0',
        'username' => '',
        'password' => 'UpdatedPassword',
        'url' => '',
        'notes' => '',
        'lock_version' => '1',
    ]);

    $response->assertRedirect(EntryDetail::getUrl(['entry' => $entry->id]));
    expect($entry->fresh()->title)->toBe('Updated over http')
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'password'))->toBe('UpdatedPassword');

    $conflict = $this->put(route('entries.update', ['vault' => $vault->id, 'entry' => $entry->id]), [
        'title' => 'Stale write',
        'owner_user_id' => $alice->id,
        'username' => '',
        'password' => '',
        'url' => '',
        'notes' => '',
        'lock_version' => '1',
    ]);

    $conflict->assertRedirect(EntryForm::getUrl(['vault' => $vault->id, 'entry' => $entry->id]));
    $conflict->assertSessionHasErrors('entry');
    expect($entry->fresh()->title)->toBe('Updated over http');
});

test('the reveal endpoint returns only the requested field with no-store headers', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'username' => 'RevealUser-Zx9',
        'password' => 'RevealPassword-Zx9',
    ]);
    $this->actingAs($alice);

    $response = $this->post(route('entries.reveal', ['entry' => $entry->id, 'field' => 'password']));

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertJson(['field' => 'password', 'value' => 'RevealPassword-Zx9']);

    /** @var array<string, mixed> $json */
    $json = $response->json();
    expect(array_keys($json))->toBe(['field', 'value'])
        ->and($response->getContent())->not->toContain('RevealUser-Zx9');
});

test('the entry endpoints enforce authorization and never leak identifiers', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    $this->actingAs($bob);
    $this->post(route('entries.store', ['vault' => $vault->id]), vaultigen_entry_update($bob))
        ->assertForbidden();
    $this->put(route('entries.update', ['vault' => $vault->id, 'entry' => $entry->id]), vaultigen_entry_update($bob))
        ->assertForbidden();

    $this->actingAs($carol);
    $this->post(route('entries.store', ['vault' => $vault->id]), vaultigen_entry_update($carol))
        ->assertNotFound();
    $this->put(route('entries.update', ['vault' => $vault->id, 'entry' => $entry->id]), vaultigen_entry_update($carol))
        ->assertNotFound();
    $this->post(route('entries.reveal', ['entry' => $entry->id, 'field' => 'password']))
        ->assertNotFound();

    $this->actingAs($alice);
    $this->post(route('entries.reveal', ['entry' => $entry->id, 'field' => 'bogus']))
        ->assertNotFound();
});

test('guests are redirected away from the entry endpoints', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    $this->post(route('entries.store', ['vault' => $vault->id]), [])->assertRedirect();
    $this->post(route('entries.reveal', ['entry' => $entry->id, 'field' => 'password']))->assertRedirect();
});

test('the custom-field endpoint delivers one audited value with no-store headers', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'note',
        'title' => 'Note',
        'body' => 'x',
        'custom_fields' => [
            ['label' => 'Visible', 'value' => 'open', 'concealed' => false],
            ['label' => 'PIN', 'value' => 'SecretPin-Zx9', 'concealed' => true],
        ],
    ]);
    $this->actingAs($bob);

    $response = $this->post(route('entries.reveal_custom', ['entry' => $entry->id, 'index' => 1]));

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertJson(['field' => 'custom_field', 'index' => 1, 'value' => 'SecretPin-Zx9']);

    $this->actingAs($alice);
    $this->post(route('entries.reveal_custom', ['entry' => $entry->id, 'index' => 9]))->assertNotFound();
    $this->post(route('entries.reveal_custom', ['entry' => $entry->id, 'index' => 'abc']))->assertNotFound();

    $this->actingAs($carol);
    $this->post(route('entries.reveal_custom', ['entry' => $entry->id, 'index' => 1]))->assertNotFound();
});

test('the totp endpoint returns the current code with no-store headers', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'totp',
        'title' => 'TOTP',
        'secret' => 'JBSWY3DPEHPK3PXP',
    ]);
    $plain = vaultigen_make_entry($alice, $vault, ['title' => 'No seed']);
    $this->actingAs($bob);

    $response = $this->post(route('entries.totp', ['entry' => $entry->id]));

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonStructure(['code', 'seconds_remaining', 'period', 'algorithm', 'digits']);

    $this->post(route('entries.totp', ['entry' => $plain->id]))->assertNotFound();

    $this->actingAs($carol);
    $this->post(route('entries.totp', ['entry' => $entry->id]))->assertNotFound();
});

test('guests are redirected away from the custom and totp endpoints', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, ['type' => 'totp', 'title' => 'TOTP', 'secret' => 'JBSWY3DPEHPK3PXP']);

    $this->post(route('entries.reveal_custom', ['entry' => $entry->id, 'index' => 0]))->assertRedirect();
    $this->post(route('entries.totp', ['entry' => $entry->id]))->assertRedirect();
});
