<?php

use App\Models\User;
use App\Models\Vault;
use App\Models\VaultKeyVersion;
use App\Services\Crypto\VaultKeyManager;
use Illuminate\Support\Facades\DB;

test('vault key versions are wrapped under the active kek and fail closed when tampered', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    $keys = app(VaultKeyManager::class);

    $version = VaultKeyVersion::query()->where('vault_id', $vault->id)->sole();
    $wrapping = DB::table('vault_key_wrappings')->where('vault_key_version_id', $version->id)->sole();

    expect($version->version)->toBe(1)
        ->and($wrapping->recipient_type)->toBe('server_kek')
        ->and($wrapping->recipient_key_id)->toBe('kek-test-v1')
        ->and($wrapping->algorithm)->toBe('xchacha20-poly1305')
        ->and($wrapping->nonce)->not->toBe('')
        ->and(strlen($keys->unwrap($vault->id, $version->id)))->toBe(32);

    expect(fn () => $keys->unwrap('00000000-0000-0000-0000-000000000000', $version->id))
        ->toThrow(RuntimeException::class, 'key material');
    expect(fn () => $keys->createFirstVersion($vault->id, $admin->id))
        ->toThrow(RuntimeException::class, 'already has an active key');

    $binary = vaultigen_base64url_decode((string) $wrapping->wrapped_key);
    DB::table('vault_key_wrappings')->where('id', $wrapping->id)->update([
        'wrapped_key' => vaultigen_base64url_encode(vaultigen_flip_byte($binary, -1)),
    ]);
    expect(fn () => $keys->unwrap($vault->id, $version->id))
        ->toThrow(RuntimeException::class, 'Unable to decrypt');

    DB::table('vault_key_wrappings')->where('id', $wrapping->id)->update(['wrapped_key' => 'not-an-envelope']);
    expect(fn () => $keys->unwrap($vault->id, $version->id))
        ->toThrow(RuntimeException::class, 'malformed');

    DB::table('vault_key_wrappings')->where('id', $wrapping->id)->delete();
    expect(fn () => $keys->unwrap($vault->id, $version->id))
        ->toThrow(RuntimeException::class, 'key material');
});

test('a different installation identity cannot unwrap an existing vault key', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    $version = DB::table('vault_key_versions')->where('vault_id', $vault->id)->sole();

    config(['vaultigen.installation_id' => '660e8400-e29b-41d4-a716-446655440000']);
    expect(fn () => app(VaultKeyManager::class)->unwrap($vault->id, $version->id))
        ->toThrow(RuntimeException::class, 'Unable to decrypt');
});

test('active key lookup fails closed for a vault that has no key version', function () {
    $vault = Vault::factory()->create();

    expect(fn () => app(VaultKeyManager::class)->activeVersionId($vault->id))
        ->toThrow(RuntimeException::class, 'no active key');
});

test('rotating or rewrapping missing key material fails closed', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $keys = app(VaultKeyManager::class);

    $emptyVault = Vault::factory()->create();
    expect(fn () => $keys->rotateActiveVersion($emptyVault->id, $admin->id))
        ->toThrow(RuntimeException::class, 'no active key');

    $vault = vaultigen_make_vault($admin, $manager);
    $versionId = (string) DB::table('vault_active_keys')->where('vault_id', $vault->id)->value('vault_key_version_id');

    expect($keys->rotateActiveVersion($vault->id, $admin->id))->not->toBe($versionId);
    expect(fn () => $keys->rewrapTo($vault->id, $versionId, 'unknown-kek', 'kek-test-v1'))
        ->toThrow(RuntimeException::class, 'key material');
});
