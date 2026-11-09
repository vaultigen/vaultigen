<?php

use App\Models\ImportRun;
use App\Services\Imports\ImportSourceStore;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

it('seals and opens an intermediate representation', function () {
    $run = ImportRun::factory()->create(['status' => ImportRun::STATUS_STAGING]);
    $sealed = app(ImportSourceStore::class)->seal($run->id, 'the intermediate representation');

    expect($sealed)->toHaveKeys([
        'random_storage_path', 'envelope_format_version', 'import_key_algorithm',
        'import_key_nonce', 'import_key_key_id', 'wrapped_import_key',
    ])->and($sealed['random_storage_path'])->toStartWith('imports/');

    $run->forceFill($sealed)->save();

    expect(app(ImportSourceStore::class)->open($run->refresh()))->toBe('the intermediate representation');
});

it('fails to open a run without staging metadata', function () {
    $run = ImportRun::factory()->create(['status' => ImportRun::STATUS_STAGING, 'random_storage_path' => null]);

    expect(fn () => app(ImportSourceStore::class)->open($run))
        ->toThrow(RuntimeException::class);
});

it('fails to open when the staged object is missing', function () {
    $run = ImportRun::factory()->create(['status' => ImportRun::STATUS_STAGING]);
    $sealed = app(ImportSourceStore::class)->seal($run->id, 'payload');
    $run->forceFill($sealed)->save();
    Storage::disk(ImportSourceStore::DISK)->delete($sealed['random_storage_path']);

    expect(fn () => app(ImportSourceStore::class)->open($run->refresh()))
        ->toThrow(RuntimeException::class);
});

it('fails to open a tampered staging object', function () {
    $run = ImportRun::factory()->create(['status' => ImportRun::STATUS_STAGING]);
    $sealed = app(ImportSourceStore::class)->seal($run->id, 'payload');
    $sealed['wrapped_import_key'] = substr($sealed['wrapped_import_key'], 0, -4).'AAAA';
    $run->forceFill($sealed)->save();

    expect(fn () => app(ImportSourceStore::class)->open($run->refresh()))
        ->toThrow(RuntimeException::class);
});

it('purges a staged object and clears its path', function () {
    $run = ImportRun::factory()->create(['status' => ImportRun::STATUS_STAGING]);
    $sealed = app(ImportSourceStore::class)->seal($run->id, 'payload');
    $run->forceFill($sealed)->save();

    app(ImportSourceStore::class)->purge($run);

    expect($run->refresh()->random_storage_path)->toBeNull()
        ->and(Storage::disk(ImportSourceStore::DISK)->exists($sealed['random_storage_path']))->toBeFalse();
});

it('purges a run with no staged object without failing', function () {
    $run = ImportRun::factory()->create(['status' => ImportRun::STATUS_STAGING, 'random_storage_path' => null]);

    app(ImportSourceStore::class)->purge($run);

    expect($run->refresh()->random_storage_path)->toBeNull();
});
