<?php

use App\Services\Operations\BackupManifest;
use App\Services\Operations\OperationFailedException;

it('rejects an invalid backup manifest with the appropriate failure code', function (string $field, mixed $value, string $code): void {
    [, $manifest] = vaultigen_fake_backup_set();
    data_set($manifest, $field, $value);

    expect(fn () => app(BackupManifest::class)->validate($manifest))
        ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe($code));
})->with([
    'missing application version' => ['app_version', '', 'manifest_malformed'],
    'empty required key identifier' => ['required_key_ids.kek.0', '', 'manifest_malformed'],
    'unsafe snapshot path' => ['snapshot.file', '../database.enc', 'unsafe_path'],
    'object outside object directory' => ['objects.0.file', 'manifest.json', 'unsafe_path'],
    'incorrect object count' => ['object_count', 2, 'manifest_malformed'],
    'negative object size' => ['objects.0.bytes', -1, 'manifest_malformed'],
]);

it('refuses recovery fingerprints when a required secret is missing', function (): void {
    config(['app.key' => null]);

    expect(fn () => app(BackupManifest::class)->recoveryChecks([]))
        ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('recovery_input_missing'));
});

it('rejects a version two manifest without recovery fingerprints', function (): void {
    [, $manifest] = vaultigen_fake_backup_set(['format_version' => 2]);

    expect(fn () => app(BackupManifest::class)->verifyRecovery($manifest))
        ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('manifest_malformed'));
});
