<?php

use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\Keepass\KeepassXmlDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\KeepassXmlBuilder;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

function keepass_driver_validation_message(UploadedFile $file, User $admin): string
{
    try {
        app(KeepassXmlDriver::class)->stage($file, [], $admin);
    } catch (ValidationException $exception) {
        return (string) collect($exception->errors()['file'] ?? [])->first();
    }

    return '';
}

it('stages a plain export without credentials and previews its structure', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = (new KeepassXmlBuilder)->binary('0', 'file bytes')->recycleBin(KeepassXmlBuilder::uuid('bin'));
    $xml = $builder->build([
        $builder->group('Work', [
            $builder->entry('One', ['Password' => 'secret-one', 'otp' => 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP'], ['binaries' => ['a.txt' => '0'], 'history' => 3]),
            $builder->entry('Two', ['otp' => 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP&digits=8', 'Ref' => '{REF:U@I:00}'], ['binaries' => ['gone.txt' => '7']]),
        ]),
        $builder->group('Recycle Bin', [$builder->entry('Deleted')], KeepassXmlBuilder::uuid('bin')),
    ]);

    $run = vaultigen_staged_keepass_run($admin, $xml);
    $preview = app(KeepassXmlDriver::class)->preview($run->refresh());

    expect($run->status)->toBe(ImportRun::STATUS_STAGED)
        ->and($run->source_format)->toBe('keepass_xml')
        ->and($run->original_filename)->toBe('keepass.xml')
        ->and($run->source_fingerprint)->toBe(hash('sha256', $xml))
        ->and($run->random_storage_path)->toStartWith('imports/')
        ->and(DB::table('import_runs')->where('id', $run->id)->value('plan'))->toBeNull();
    expect($preview['structure'])->toMatchArray([
        'entries' => 2,
        'groups' => 1,
        'attachments' => 2,
        'totp' => 1,
        'recycle_bin' => 1,
        'history' => 3,
        'totp_unsupported' => 1,
        'field_references' => 1,
        'invalid_attachments' => 1,
    ]);
});

it('rejects an export with stream-protected values without creating a run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = new KeepassXmlBuilder;
    $xml = $builder->build([$builder->entry('Raw', ['Password' => 'c2VjcmV0'], ['stream_protected' => ['Password']])]);

    $message = keepass_driver_validation_message(UploadedFile::fake()->createWithContent('raw.xml', $xml), $admin);

    expect($message)->toBe('The file contains encrypted (protected) values. Export it from KeePass or KeePassXC as plain XML instead.')
        ->and(ImportRun::query()->count())->toBe(0);
});

it('rejects a file that is not a keepass export without creating a run', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $message = keepass_driver_validation_message(UploadedFile::fake()->createWithContent('other.xml', '<Root/>'), $admin);

    expect($message)->toBe('The file is not a KeePass 2.x or KeePassXC XML export.')
        ->and(ImportRun::query()->count())->toBe(0);
});

it('rejects an export whose attachments exceed the configured import budget', function () {
    config(['vaultigen.imports.max_attachment_bytes' => 100]);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = (new KeepassXmlBuilder)->binary('0', str_repeat('a', 60))->binary('1', str_repeat('b', 60));
    $xml = $builder->build([$builder->entry('Files', [], ['binaries' => ['a.bin' => '0', 'b.bin' => '1']])]);

    $message = keepass_driver_validation_message(UploadedFile::fake()->createWithContent('big.xml', $xml), $admin);

    expect($message)->toBe('The attachments in this export exceed the import limit. Nothing was staged.')
        ->and(ImportRun::query()->count())->toBe(0);
});

it('marks an attachment above the per-file limit as too large instead of failing staging', function () {
    config(['vaultigen.attachments.max_bytes_per_file' => 10]);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $builder = (new KeepassXmlBuilder)->binary('0', str_repeat('a', 11));
    $xml = $builder->build([$builder->entry('Files', [], ['binaries' => ['a.bin' => '0']])]);

    $run = vaultigen_staged_keepass_run($admin, $xml);

    expect(app(KeepassXmlDriver::class)->openIr($run)['entries'][0]['attachments'])
        ->toBe([['filename' => 'a.bin', 'invalid' => 'too_large']]);
});
