<?php

use App\Models\User;
use App\Services\Crypto\EntryCipher;
use App\Services\Crypto\VaultKeyManager;
use Illuminate\Support\Str;

test('entry cipher round trips payloads and snapshots', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    $versionId = app(VaultKeyManager::class)->activeVersionId($vault->id);
    $cipher = app(EntryCipher::class);

    $entryId = (string) Str::uuid();
    $plaintext = '{"schema_version":1,"type":"login"}';
    $sealed = $cipher->sealPayload($vault->id, $entryId, $versionId, $plaintext);

    expect($sealed->envelope)->not->toBe('')
        ->and($sealed->nonce)->not->toBe('')
        ->and($cipher->openPayload($vault->id, $entryId, $versionId, $sealed->envelope))->toBe($plaintext);

    $revisionId = (string) Str::uuid();
    $snapshot = $cipher->sealSnapshot($vault->id, $revisionId, $versionId, '{"snapshot":true}');

    expect($cipher->openSnapshot($vault->id, $revisionId, $versionId, $snapshot->envelope))->toBe('{"snapshot":true}');
});

test('entry cipher rejects record swaps wrong identifiers tampering and unknown keys', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    $other = vaultigen_make_vault($admin, $manager, ['name' => 'Other']);
    $versionId = app(VaultKeyManager::class)->activeVersionId($vault->id);
    $otherVersionId = app(VaultKeyManager::class)->activeVersionId($other->id);
    $cipher = app(EntryCipher::class);

    $entryId = (string) Str::uuid();
    $sealed = $cipher->sealPayload($vault->id, $entryId, $versionId, '{"ok":true}');

    expect(fn () => $cipher->openSnapshot($vault->id, $entryId, $versionId, $sealed->envelope))
        ->toThrow(RuntimeException::class);
    expect(fn () => $cipher->openPayload($vault->id, (string) Str::uuid(), $versionId, $sealed->envelope))
        ->toThrow(RuntimeException::class);
    expect(fn () => $cipher->openPayload($other->id, $entryId, $otherVersionId, $sealed->envelope))
        ->toThrow(RuntimeException::class);
    expect(fn () => $cipher->openPayload($vault->id, $entryId, $otherVersionId, $sealed->envelope))
        ->toThrow(RuntimeException::class);

    $binary = vaultigen_base64url_decode($sealed->envelope);
    $tampered = vaultigen_base64url_encode(vaultigen_flip_byte($binary, strlen($binary) - 1));

    expect(fn () => $cipher->openPayload($vault->id, $entryId, $versionId, $tampered))
        ->toThrow(RuntimeException::class);
});
