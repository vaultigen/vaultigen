<?php

use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function vaultigen_insert_entry(Vault $vault, User $owner, array $overrides = []): void
{
    DB::table('entries')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'vault_id' => $vault->id,
        'owner_user_id' => $owner->id,
        'type' => 'login',
        'title' => 'Direct entry',
        'normalized_title' => 'direct entry',
        'url_host' => null,
        'vault_key_version_id' => DB::table('vault_active_keys')->where('vault_id', $vault->id)->value('vault_key_version_id'),
        'cipher_suite' => 'xchacha20-poly1305',
        'payload_format_version' => 1,
        'payload_schema_version' => 1,
        'payload_nonce' => 'nonce',
        'encrypted_payload' => 'ciphertext',
        'lock_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

test('entry state constraints reject invalid direct writes', function (array $overrides) {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    expect(fn () => vaultigen_insert_entry($vault, $alice, $overrides))->toThrow(QueryException::class);
})->with([
    [['type' => 'credit_card']],
    [['lock_version' => 0]],
]);

test('entry key references must belong to the same vault', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $other = vaultigen_make_vault($admin, $alice, ['name' => 'Other']);
    $foreignVersion = DB::table('vault_active_keys')->where('vault_id', $other->id)->value('vault_key_version_id');

    expect(fn () => vaultigen_insert_entry($vault, $alice, ['vault_key_version_id' => $foreignVersion]))
        ->toThrow(QueryException::class);
});

test('revisions are unique per entry and constrained to their own vault', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $other = vaultigen_make_vault($admin, $alice, ['name' => 'Other']);

    vaultigen_insert_entry($vault, $alice);
    $entry = DB::table('entries')->where('vault_id', $vault->id)->sole();
    $versionId = DB::table('vault_active_keys')->where('vault_id', $vault->id)->value('vault_key_version_id');

    $revision = [
        'id' => (string) Str::uuid(),
        'vault_id' => $vault->id,
        'entry_id' => $entry->id,
        'revision_number' => 1,
        'vault_key_version_id' => $versionId,
        'cipher_suite' => 'xchacha20-poly1305',
        'payload_format_version' => 1,
        'payload_schema_version' => 1,
        'payload_nonce' => 'nonce',
        'encrypted_snapshot' => 'ciphertext',
        'changed_by_user_id' => $alice->id,
        'created_at' => now(),
    ];

    DB::table('entry_revisions')->insert($revision);
    expect(DB::table('entry_revisions')->count())->toBe(1);

    expect(fn () => DB::table('entry_revisions')->insert($revision))->toThrow(QueryException::class);

    expect(fn () => DB::table('entry_revisions')->insert(array_merge($revision, [
        'id' => (string) Str::uuid(),
        'revision_number' => 2,
        'vault_id' => $other->id,
    ])))->toThrow(QueryException::class);

    expect(fn () => DB::table('entry_revisions')->insert(array_merge($revision, [
        'id' => (string) Str::uuid(),
        'revision_number' => 0,
    ])))->toThrow(QueryException::class);
});
