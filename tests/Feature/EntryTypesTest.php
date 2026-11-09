<?php

use App\Models\AuditEvent;
use App\Services\EntryVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

const VAULTIGEN_SEED = 'JBSWY3DPEHPK3PXP';

test('a secure note stores and reveals its body and custom fields', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'note',
        'title' => 'Recovery codes',
        'body' => 'one two three',
        'custom_fields' => [
            ['label' => 'Visible', 'value' => 'open', 'concealed' => false],
            ['label' => 'PIN', 'value' => '1234', 'concealed' => true],
        ],
    ]);

    expect($entry->type)->toBe('note')
        ->and($entry->url_host)->toBeNull()
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'body'))->toBe('one two three');

    $rows = app(EntryVault::class)->customFieldsForDisplay($entry);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['value'])->toBe('open')
        ->and($rows[1]['value'])->toBeNull()
        ->and($rows[1]['concealed'])->toBeTrue();

    $editRows = app(EntryVault::class)->customFieldRowsForEdit($entry);
    expect($editRows[0]['value'])->toBeNull()
        ->and($editRows[0]['label'])->toBe('Visible');

    expect(app(EntryVault::class)->revealCustom($alice, $entry->id, 1))->toBe('1234')
        ->and(AuditEvent::query()->where('action', 'entry.secret_delivered')->count())->toBe(2);

    expect(fn () => app(EntryVault::class)->revealCustom($alice, $entry->id, 9))
        ->toThrow(NotFoundHttpException::class);
    expect(fn () => app(EntryVault::class)->reveal($alice, $entry->id, 'password'))
        ->toThrow(NotFoundHttpException::class);
});

test('custom fields merge on update and survive a revision restore', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'note',
        'title' => 'Note',
        'body' => 'first',
        'custom_fields' => [
            ['label' => 'Keep', 'value' => 'original', 'concealed' => false],
            ['label' => 'Remove', 'value' => 'gone', 'concealed' => true],
        ],
    ]);

    app(EntryVault::class)->update($alice, $entry->id, [
        'title' => 'Note',
        'owner_user_id' => $alice->id,
        'expires_at' => null,
        'custom_fields' => [
            ['key' => 0, 'label' => 'Keep', 'value' => null, 'concealed' => false],
            ['key' => null, 'label' => 'Added', 'value' => 'new', 'concealed' => true],
        ],
    ], 1);

    $rows = app(EntryVault::class)->customFieldsForDisplay($entry->fresh());
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['value'])->toBe('original')
        ->and($rows[1]['label'])->toBe('Added')
        ->and($rows[1]['value'])->toBeNull();

    $revision = DB::table('entry_revisions')->where('entry_id', $entry->id)->where('revision_number', 1)->sole();
    app(EntryVault::class)->restore($alice, $entry->id, $revision->id);

    $restored = app(EntryVault::class)->customFieldsForDisplay($entry->fresh());
    expect($restored)->toHaveCount(2)
        ->and($restored[0]['label'])->toBe('Keep')
        ->and($restored[0]['value'])->toBe('original')
        ->and($restored[1]['label'])->toBe('Remove');
});

test('an SSH key reveals its public key, private key and passphrase', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'ssh_key',
        'title' => 'Server key',
        'public_key' => 'ssh-ed25519 AAAA-public',
        'private_key' => '-----BEGIN PRIVATE KEY-----',
        'passphrase' => 'key-passphrase',
    ]);

    expect(app(EntryVault::class)->reveal($alice, $entry->id, 'public_key'))->toBe('ssh-ed25519 AAAA-public')
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'private_key'))->toBe('-----BEGIN PRIVATE KEY-----')
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'passphrase'))->toBe('key-passphrase');

    app(EntryVault::class)->update($alice, $entry->id, [
        'title' => 'Server key',
        'owner_user_id' => $alice->id,
        'expires_at' => null,
        'private_key' => null,
        'passphrase' => 'rotated-passphrase',
    ], 1);

    expect(app(EntryVault::class)->reveal($alice, $entry->id, 'private_key'))->toBe('-----BEGIN PRIVATE KEY-----')
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'passphrase'))->toBe('rotated-passphrase');

    expect(fn () => app(EntryVault::class)->reveal($alice, $entry->id, 'password'))
        ->toThrow(NotFoundHttpException::class);
});

test('a TOTP entry generates codes and keeps its parameters across an update', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'totp',
        'title' => 'Authenticator',
        'issuer' => 'Example',
        'account' => 'alice@example.com',
        'secret' => VAULTIGEN_SEED,
        'algorithm' => 'SHA256',
        'digits' => '8',
        'period' => '60',
    ]);

    $parameters = app(EntryVault::class)->totpParametersForEdit($entry);
    expect($parameters)->toBe(['algorithm' => 'SHA256', 'digits' => 8, 'period' => 60])
        ->and(app(EntryVault::class)->hasTotpSeed($entry))->toBeTrue()
        ->and(app(EntryVault::class)->totpAvailable($alice, $entry))->toBeTrue();

    $code = app(EntryVault::class)->totpCode($alice, $entry->id);
    expect(strlen($code['code']))->toBe(8)
        ->and($code['period'])->toBe(60)
        ->and($code['algorithm'])->toBe('SHA256')
        ->and($code['seconds_remaining'])->toBeGreaterThan(0)
        ->and($code['seconds_remaining'])->toBeLessThanOrEqual(60);

    app(EntryVault::class)->update($alice, $entry->id, [
        'title' => 'Authenticator',
        'owner_user_id' => $alice->id,
        'expires_at' => null,
        'secret' => null,
        'notes' => 'kept',
    ], 1);

    expect(app(EntryVault::class)->reveal($alice, $entry->id, 'secret'))->toBe(VAULTIGEN_SEED)
        ->and(app(EntryVault::class)->totpParametersForEdit($entry->fresh()))->toBe(['algorithm' => 'SHA256', 'digits' => 8, 'period' => 60]);

    expect(fn () => app(EntryVault::class)->reveal($alice, $entry->id, 'algorithm'))
        ->toThrow(NotFoundHttpException::class);
});

test('a login may hold an optional seed and report TOTP availability', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    $withoutSeed = vaultigen_make_entry($alice, $vault, ['title' => 'No seed']);
    expect(app(EntryVault::class)->totpAvailable($alice, $withoutSeed))->toBeFalse()
        ->and(app(EntryVault::class)->totpParametersForEdit($withoutSeed))->toBe(['algorithm' => 'SHA1', 'digits' => 6, 'period' => 30]);
    expect(fn () => app(EntryVault::class)->totpCode($alice, $withoutSeed->id))
        ->toThrow(NotFoundHttpException::class);

    $withSeed = vaultigen_make_entry($alice, $vault, ['title' => 'With seed', 'totp_secret' => VAULTIGEN_SEED]);
    expect(app(EntryVault::class)->totpAvailable($alice, $withSeed))->toBeTrue()
        ->and(strlen(app(EntryVault::class)->totpCode($alice, $withSeed->id)['code']))->toBe(6);
});

test('TOTP availability is false for unrelated types and invalid stored seeds', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $note = vaultigen_make_entry($alice, $vault, ['type' => 'note', 'title' => 'Note', 'body' => 'x']);

    expect(app(EntryVault::class)->totpAvailable($alice, $note))->toBeFalse();

    $totp = vaultigen_make_entry($alice, $vault, ['type' => 'totp', 'title' => 'TOTP', 'secret' => VAULTIGEN_SEED]);
    DB::table('entries')->where('id', $totp->id)->update(['encrypted_payload' => 'tampered']);
    expect(fn () => app(EntryVault::class)->hasTotpSeed($totp->fresh()))->toThrow(RuntimeException::class);
});

test('creating an entry with an unsupported type is rejected', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    expect(fn () => vaultigen_make_entry($alice, $vault, ['type' => 'bogus']))
        ->toThrow(ValidationException::class);
});

test('custom-field delivery is denied beyond the effective entry authority', function () {
    ['alice' => $alice, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, [
        'type' => 'note',
        'title' => 'Note',
        'body' => 'x',
        'custom_fields' => [['label' => 'PIN', 'value' => '1234', 'concealed' => true]],
    ]);

    expect(fn () => app(EntryVault::class)->revealCustom($carol, $entry->id, 0))
        ->toThrow(NotFoundHttpException::class);
});
