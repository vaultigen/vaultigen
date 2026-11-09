<?php

use App\Services\Operations\BackupStorage;
use App\Services\Operations\OperationFailedException;
use App\Services\Operations\OperationLock;
use App\Services\Operations\PrivatePaths;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

it('refuses backup storage that cannot provide local paths', function (): void {
    Storage::shouldReceive('build')->once()->andReturn(Mockery::mock(Filesystem::class));

    expect(fn () => app(BackupStorage::class)->disk())
        ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('backup_configuration_invalid'));
});

it('refuses an operation when its lock file cannot be created', function (): void {
    Storage::fake('lock-test');
    $original = storage_path();
    app()->useStoragePath(Storage::disk('lock-test')->path('missing-parent'));
    try {
        expect(fn () => (new OperationLock)->acquire())
            ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('object_unwritable'));
    } finally {
        app()->useStoragePath($original);
    }
});

it('rejects a nonabsolute private storage root', function (): void {
    expect(fn () => PrivatePaths::root('relative/root'))
        ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('unsafe_path'));
});

it('releases the operation lock once after repeated acquisition by the same owner', function (): void {
    $lock = new OperationLock;
    $lock->acquire();
    $competitor = fopen(storage_path('framework/backup-operations.lock'), 'c');

    try {
        $lock->acquire();

        expect(flock($competitor, LOCK_EX | LOCK_NB))->toBeFalse();

        $lock->release();

        expect(flock($competitor, LOCK_EX | LOCK_NB))->toBeTrue();
    } finally {
        $lock->release();
        fclose($competitor);
    }
});

it('rejects a private file path without a file name', function (string $path): void {
    expect(fn () => PrivatePaths::file($path))
        ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('unsafe_path'));
})->with([
    'empty path' => [''],
    'root directory' => ['/'],
    'current directory' => ['/tmp/.'],
    'parent directory' => ['/tmp/..'],
]);

it('reports when a private directory cannot be created', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('obstruction', 'keep');

    expect(fn () => PrivatePaths::directory(Storage::disk('local')->path('obstruction/child')))
        ->toThrow(fn (OperationFailedException $exception) => expect($exception->failureCode)->toBe('object_unwritable'));
    expect(Storage::disk('local')->get('obstruction'))->toBe('keep');
});
