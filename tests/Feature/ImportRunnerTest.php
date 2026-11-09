<?php

use App\Models\Entry;
use App\Models\FileAttachment;
use App\Models\Folder;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\EntryAttachments;
use App\Services\EntryVault;
use App\Services\Imports\ImportRunner;
use App\Services\Imports\ImportSourceStore;
use App\Services\Imports\ImportStager;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\KeepassXmlBuilder;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

it('writes totp seeds, custom fields, and expiry from a keepass export', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $run = vaultigen_planned_keepass_run($admin, $builder->build([
        $builder->entry('Mail', [
            'UserName' => 'alice',
            'Password' => 'mail-secret',
            'otp' => 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP',
            'Backup code' => '1111-2222',
        ], ['protect' => ['Backup code'], 'expires' => '2030-05-06T07:08:09Z']),
    ]));

    $result = app(ImportRunner::class)->run($run, []);

    $entry = Entry::query()->sole();
    $vault = app(EntryVault::class);
    expect($result->counters['entries'])->toBe(1)
        ->and($result->failureCount)->toBe(0);
    expect($vault->reveal($admin, $entry->id, 'password'))->toBe('mail-secret')
        ->and($vault->reveal($admin, $entry->id, 'totp_secret'))->toBe('JBSWY3DPEHPK3PXP')
        ->and($vault->revealCustom($admin, $entry->id, 0))->toBe('1111-2222')
        ->and($vault->customFieldsForDisplay($entry)[0])->toMatchArray(['label' => 'Backup code', 'concealed' => true])
        ->and($entry->expires_at?->format('Y-m-d H:i:s'))->toBe('2030-05-06 07:08:09');
});

it('keeps distinct groups with colliding names in separate folders', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $run = vaultigen_planned_keepass_run($admin, $builder->build([
        $builder->group('Servers', [$builder->entry('One')], KeepassXmlBuilder::uuid('first')),
        $builder->group('servers', [$builder->entry('Two')], KeepassXmlBuilder::uuid('second')),
        $builder->group('Servers', [$builder->entry('Three')], KeepassXmlBuilder::uuid('first')),
    ]));

    $result = app(ImportRunner::class)->run($run, []);

    expect($result->counters['folders'])->toBe(2)
        ->and(Folder::query()->orderBy('name')->pluck('name')->all())->toBe(['Servers', 'servers (2)'])
        ->and(Entry::query()->where('title', 'Three')->value('folder_id'))
        ->toBe(Entry::query()->where('title', 'One')->value('folder_id'));
});

it('keeps an entry whose attachment fails and counts attachment failures separately', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = (new KeepassXmlBuilder)->binary('0', 'report bytes')->rawBinary('1', 'not*base64');
    $run = vaultigen_planned_keepass_run($admin, $builder->build([
        $builder->entry('With files', ['Password' => 'p'], ['binaries' => ['report.txt' => '0', 'secret-name.bin' => '1']]),
    ]));

    $result = app(ImportRunner::class)->run($run, []);

    $entry = Entry::query()->sole();
    $attachment = FileAttachment::query()->where('entry_id', $entry->id)->sole();
    expect($result->counters)->toMatchArray(['entries' => 1, 'attachments' => 1, 'attachment_failures' => 1])
        ->and($result->failureCount)->toBe(0)
        ->and($result->failures)->toBe([[
            'kind' => 'attachment',
            'source_id' => KeepassXmlBuilder::uuid('entry-With files'),
            'title' => 'With files',
            'context' => '',
            'error_code' => 'invalid_base64',
            'entry_id' => $entry->id,
            'index' => 2,
            'of' => 2,
        ]])
        ->and(json_encode($run->refresh()->failures))->not->toContain('secret-name');
    expect(app(EntryAttachments::class)->metadata($attachment)['filename'])->toBe('report.txt')
        ->and(vaultigen_decrypt_attachment($attachment))->toBe('report bytes');
});

it('records an attachment rejected by the vault quota without failing the entry', function () {
    config(['vaultigen.attachments.max_bytes_per_vault' => 5]);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = (new KeepassXmlBuilder)->binary('0', 'more than five bytes');
    $run = vaultigen_planned_keepass_run($admin, $builder->build([
        $builder->entry('Over quota', [], ['binaries' => ['big.txt' => '0']]),
    ]));

    $result = app(ImportRunner::class)->run($run, []);

    expect($result->counters)->toMatchArray(['entries' => 1, 'attachments' => 0, 'attachment_failures' => 1])
        ->and($result->failures[0]['error_code'])->toBe('attachment_rejected')
        ->and(FileAttachment::query()->count())->toBe(0);
});

it('fails an entry whose custom fields do not fit an entry', function (array $fields, string $errorCode) {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $run = vaultigen_planned_keepass_run($admin, $builder->build([
        $builder->group('Work', [$builder->entry('Crowded', $fields), $builder->entry('Fine')]),
    ]));

    $result = app(ImportRunner::class)->run($run, []);

    expect($result->counters['entries'])->toBe(1)
        ->and($result->failureCount)->toBe(1)
        ->and($result->failures[0])->toMatchArray(['kind' => 'entry', 'title' => 'Crowded', 'context' => 'Work', 'error_code' => $errorCode]);
})->with([
    'more than fifty fields' => [array_fill_keys(array_map(fn (int $i): string => 'Field '.$i, range(1, 51)), 'value'), 'too_many_custom_fields'],
    'a value above the field limit' => [['Long note' => str_repeat('v', 4097)], 'custom_field_too_long'],
    'a label above the field limit' => [[str_repeat('l', 101) => 'value'], 'custom_field_too_long'],
]);

it('records a staged attachment whose data is missing as a missing attachment', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = (new KeepassXmlBuilder)->binary('0', 'bytes');
    $run = vaultigen_planned_keepass_run($admin, $builder->build([
        $builder->entry('With file', [], ['binaries' => ['a.txt' => '0']]),
    ]));
    $ir = app(ImportStager::class)->open($run);
    $run->forceFill(app(ImportSourceStore::class)->seal($run->id, json_encode([...$ir, 'blobs' => []], JSON_THROW_ON_ERROR)))->save();

    $result = app(ImportRunner::class)->run($run->refresh(), []);

    expect($result->counters)->toMatchArray(['entries' => 1, 'attachments' => 0, 'attachment_failures' => 1])
        ->and($result->failures[0]['error_code'])->toBe('missing_reference');
});

it('keeps the entry and records a failure when an attachment cannot be stored', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = (new KeepassXmlBuilder)->binary('0', 'bytes');
    $run = vaultigen_planned_keepass_run($admin, $builder->build([
        $builder->entry('With file', [], ['binaries' => ['a.txt' => '0']]),
    ]));
    // The staging object stays readable; only the attachment write fails.
    $disk = Mockery::mock(Storage::disk('attachments'))->makePartial();
    $disk->shouldReceive('path')->andReturnUsing(fn (string $path): string => str_ends_with($path, '.part') ? 'vaultigen-failwrite://sink' : $path);
    Storage::set('attachments', $disk);

    $result = app(ImportRunner::class)->run($run, []);

    expect($result->counters)->toMatchArray(['entries' => 1, 'attachments' => 0, 'attachment_failures' => 1])
        ->and($result->failures[0]['error_code'])->toBe('attachment_failed')
        ->and(FileAttachment::query()->count())->toBe(0);
});

it('fails the run without writing when the staged schema version is unknown', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $run = vaultigen_planned_keepass_run($admin, $builder->build([$builder->entry('One')]));
    $ir = app(ImportStager::class)->open($run);
    $sealed = app(ImportSourceStore::class)->seal($run->id, json_encode([...$ir, 'schema' => 2], JSON_THROW_ON_ERROR));
    $run->forceFill($sealed)->save();

    $result = app(ImportRunner::class)->run($run->refresh(), []);

    expect($result->errorCode)->toBe('staging_unavailable')
        ->and($run->refresh()->status)->toBe(ImportRun::STATUS_FAILED)
        ->and(Entry::query()->count())->toBe(0);
});
