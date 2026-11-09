<?php

use App\Models\User;
use App\Services\EntryPayload;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function vaultigen_owner(): User
{
    return User::factory()->withTwoFactor()->create();
}

function vaultigen_login_input(User $owner, array $overrides = []): array
{
    return array_merge([
        'title' => 'Example account',
        'owner_user_id' => $owner->id,
        'generated' => false,
        'username' => 'alice',
        'password' => 'secret-password',
        'url' => 'https://example.com/login',
        'notes' => 'a note',
    ], $overrides);
}

test('validate trims the title and normalizes optional login fields', function () {
    $owner = vaultigen_owner();

    $validated = app(EntryPayload::class)->validate(vaultigen_login_input($owner, [
        'title' => '  Example account  ',
        'generated' => '1',
    ]), EntryPayload::TYPE_LOGIN, isCreate: true);

    expect($validated['type'])->toBe('login')
        ->and($validated['title'])->toBe('Example account')
        ->and($validated['owner_user_id'])->toBe($owner->id)
        ->and($validated['generated'])->toBeTrue()
        ->and($validated['expires_at'])->toBeNull()
        ->and($validated['username'])->toBe('alice')
        ->and($validated['password'])->toBe('secret-password')
        ->and($validated['url'])->toBe('https://example.com/login')
        ->and($validated['notes'])->toBe('a note')
        ->and($validated['totp_secret'])->toBeNull()
        ->and($validated['custom_fields'])->toBe([]);
});

test('validate requires a password on login create and enforces field limits', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    expect(fn () => $payloads->validate(['title' => 'Example', 'owner_user_id' => $owner->id], EntryPayload::TYPE_LOGIN, isCreate: true))
        ->toThrow(ValidationException::class);

    expect(fn () => $payloads->validate(vaultigen_login_input($owner, ['notes' => str_repeat('n', EntryPayload::MAX_NOTES + 1)]), EntryPayload::TYPE_LOGIN, isCreate: true))
        ->toThrow(ValidationException::class);

    $update = $payloads->validate([
        'title' => 'Example',
        'owner_user_id' => $owner->id,
        'username' => null,
        'password' => null,
        'url' => null,
        'notes' => null,
    ], EntryPayload::TYPE_LOGIN, isCreate: false);

    expect($update['password'])->toBeNull();
});

test('validate allows an empty password on import only', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    $validated = $payloads->validate([
        'title' => 'Imported login',
        'owner_user_id' => $owner->id,
        'password' => '',
    ], EntryPayload::TYPE_LOGIN, isCreate: true, allowEmptySecrets: true);

    expect($validated['password'])->toBeNull();

    $ssh = $payloads->validate([
        'title' => 'Imported key',
        'owner_user_id' => $owner->id,
    ], EntryPayload::TYPE_SSH_KEY, isCreate: true, allowEmptySecrets: true);

    expect($ssh['type'])->toBe(EntryPayload::TYPE_SSH_KEY);
});

test('validate rejects an unknown entry type', function () {
    $owner = vaultigen_owner();

    expect(fn () => app(EntryPayload::class)->validate(['title' => 'x', 'owner_user_id' => $owner->id], 'bogus', isCreate: true))
        ->toThrow(ValidationException::class);
});

test('validate builds a secure note payload', function () {
    $owner = vaultigen_owner();

    $validated = app(EntryPayload::class)->validate([
        'title' => 'Recovery codes',
        'owner_user_id' => $owner->id,
        'body' => 'one two three',
    ], EntryPayload::TYPE_NOTE, isCreate: true);

    expect($validated['type'])->toBe('note')
        ->and($validated['body'])->toBe('one two three');

    expect(fn () => app(EntryPayload::class)->validate(['title' => 'x', 'owner_user_id' => $owner->id], EntryPayload::TYPE_NOTE, isCreate: true))
        ->toThrow(ValidationException::class);
});

test('validate requires an SSH key to have at least a public or private key', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    expect(fn () => $payloads->validate(['title' => 'Key', 'owner_user_id' => $owner->id], EntryPayload::TYPE_SSH_KEY, isCreate: true))
        ->toThrow(ValidationException::class);

    $publicOnly = $payloads->validate([
        'title' => 'Key',
        'owner_user_id' => $owner->id,
        'public_key' => 'ssh-ed25519 AAAA',
    ], EntryPayload::TYPE_SSH_KEY, isCreate: true);
    expect($publicOnly['public_key'])->toBe('ssh-ed25519 AAAA')
        ->and($publicOnly['private_key'])->toBeNull();

    $onUpdate = $payloads->validate([
        'title' => 'Key',
        'owner_user_id' => $owner->id,
    ], EntryPayload::TYPE_SSH_KEY, isCreate: false);
    expect($onUpdate['private_key'])->toBeNull();
});

test('validate accepts TOTP parameters and rejects unsupported ones', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    $validated = $payloads->validate([
        'title' => 'Authenticator',
        'owner_user_id' => $owner->id,
        'issuer' => 'Example',
        'account' => 'alice@example.com',
        'secret' => 'JBSWY3DPEHPK3PXP',
        'algorithm' => 'SHA256',
        'digits' => '8',
        'period' => '60',
    ], EntryPayload::TYPE_TOTP, isCreate: true);

    expect($validated['algorithm'])->toBe('SHA256')
        ->and($validated['digits'])->toBe('8')
        ->and($validated['period'])->toBe('60');

    expect(fn () => $payloads->validate([
        'title' => 'Authenticator',
        'owner_user_id' => $owner->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'algorithm' => 'MD5',
    ], EntryPayload::TYPE_TOTP, isCreate: true))->toThrow(ValidationException::class);

    expect(fn () => $payloads->validate([
        'title' => 'Authenticator',
        'owner_user_id' => $owner->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'digits' => '7',
    ], EntryPayload::TYPE_TOTP, isCreate: true))->toThrow(ValidationException::class);

    expect(fn () => $payloads->validate([
        'title' => 'Authenticator',
        'owner_user_id' => $owner->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
        'period' => '45',
    ], EntryPayload::TYPE_TOTP, isCreate: true))->toThrow(ValidationException::class);

    expect(fn () => $payloads->validate([
        'title' => 'Authenticator',
        'owner_user_id' => $owner->id,
        'secret' => 'not-base-32!',
    ], EntryPayload::TYPE_TOTP, isCreate: true))->toThrow(ValidationException::class);
});

test('validate rejects an invalid optional login TOTP seed', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    expect(fn () => $payloads->validate(vaultigen_login_input($owner, ['totp_secret' => 'not-base-32!']), EntryPayload::TYPE_LOGIN, isCreate: true))
        ->toThrow(ValidationException::class);

    $validated = $payloads->validate(vaultigen_login_input($owner, ['totp_secret' => 'JBSWY3DPEHPK3PXP']), EntryPayload::TYPE_LOGIN, isCreate: true);
    expect($validated['totp_secret'])->toBe('JBSWY3DPEHPK3PXP');
});

test('validate normalizes custom-field rows and enforces their limits', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    $validated = $payloads->validate(vaultigen_login_input($owner, [
        'custom_fields' => [
            ['key' => '', 'label' => '  PIN  ', 'value' => '1234', 'concealed' => '1'],
            ['key' => '', 'label' => '', 'value' => '', 'concealed' => '0'],
            ['key' => '2', 'label' => 'Visible', 'value' => '', 'concealed' => '0'],
            'not-an-array',
        ],
    ]), EntryPayload::TYPE_LOGIN, isCreate: true);

    expect($validated['custom_fields'])->toHaveCount(2)
        ->and($validated['custom_fields'][0])->toBe(['key' => null, 'label' => 'PIN', 'value' => '1234', 'concealed' => true])
        ->and($validated['custom_fields'][1])->toBe(['key' => 2, 'label' => 'Visible', 'value' => null, 'concealed' => false]);

    expect(fn () => $payloads->validate(vaultigen_login_input($owner, [
        'custom_fields' => [['label' => str_repeat('l', EntryPayload::MAX_CUSTOM_LABEL + 1), 'value' => 'x']],
    ]), EntryPayload::TYPE_LOGIN, isCreate: true))->toThrow(ValidationException::class);

    expect(fn () => $payloads->validate(vaultigen_login_input($owner, [
        'custom_fields' => [['label' => 'Big', 'value' => str_repeat('v', EntryPayload::MAX_CUSTOM_VALUE + 1)]],
    ]), EntryPayload::TYPE_LOGIN, isCreate: true))->toThrow(ValidationException::class);

    $tooMany = [];
    for ($index = 0; $index <= EntryPayload::MAX_CUSTOM_FIELDS; $index++) {
        $tooMany[] = ['label' => 'Field '.$index, 'value' => 'x'];
    }

    expect(fn () => $payloads->validate(vaultigen_login_input($owner, ['custom_fields' => $tooMany]), EntryPayload::TYPE_LOGIN, isCreate: true))
        ->toThrow(ValidationException::class);

    $tagId = (string) Str::uuid();
    $tags = $payloads->validate(vaultigen_login_input($owner, ['tags' => [$tagId]]), EntryPayload::TYPE_LOGIN, isCreate: true);
    expect($tags['tag_ids'])->toBe([$tagId]);
});

test('compose builds a login document and merges without clearing blank secrets', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    $validated = $payloads->validate(vaultigen_login_input($owner, ['generated' => true]), EntryPayload::TYPE_LOGIN, isCreate: true);
    $payload = $payloads->compose(null, $validated);

    expect($payload['schema_version'])->toBe(1)
        ->and($payload['type'])->toBe('login')
        ->and($payload['fields']['username'])->toBe('alice')
        ->and($payload['fields']['password'])->toBe('secret-password')
        ->and($payload['fields']['totp_secret'])->toBeNull()
        ->and($payload['custom_fields'])->toBe([])
        ->and($payload['metadata']['generated_password'])->toBeTrue();

    $merged = $payloads->compose($payload, $payloads->validate([
        'title' => 'Example account',
        'owner_user_id' => $owner->id,
        'expires_at' => null,
        'generated' => false,
        'username' => null,
        'password' => 'rotated',
        'url' => null,
        'notes' => null,
    ], EntryPayload::TYPE_LOGIN, isCreate: false));

    expect($merged['fields']['password'])->toBe('rotated')
        ->and($merged['fields']['username'])->toBe('alice')
        ->and($merged['metadata']['generated_password'])->toBeTrue();
});

test('compose builds the other payload types and applies TOTP defaults', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    $note = $payloads->compose(null, $payloads->validate([
        'title' => 'Note',
        'owner_user_id' => $owner->id,
        'body' => 'body',
    ], EntryPayload::TYPE_NOTE, isCreate: true));
    expect($note['type'])->toBe('note')
        ->and($note['fields'])->toBe(['body' => 'body'])
        ->and(array_key_exists('metadata', $note))->toBeFalse();

    $ssh = $payloads->compose(null, $payloads->validate([
        'title' => 'Key',
        'owner_user_id' => $owner->id,
        'private_key' => 'PRIVATE',
    ], EntryPayload::TYPE_SSH_KEY, isCreate: true));
    expect($ssh['type'])->toBe('ssh_key')
        ->and($ssh['fields']['public_key'])->toBeNull()
        ->and($ssh['fields']['private_key'])->toBe('PRIVATE');

    $totp = $payloads->compose(null, $payloads->validate([
        'title' => 'TOTP',
        'owner_user_id' => $owner->id,
        'secret' => 'JBSWY3DPEHPK3PXP',
    ], EntryPayload::TYPE_TOTP, isCreate: true));
    expect($totp['fields']['algorithm'])->toBe('SHA1')
        ->and($totp['fields']['digits'])->toBe(6)
        ->and($totp['fields']['period'])->toBe(30);

    $kept = $payloads->compose($totp, $payloads->validate([
        'title' => 'TOTP',
        'owner_user_id' => $owner->id,
        'secret' => null,
        'algorithm' => null,
        'digits' => null,
        'period' => null,
    ], EntryPayload::TYPE_TOTP, isCreate: false));
    expect($kept['fields']['secret'])->toBe('JBSWY3DPEHPK3PXP')
        ->and($kept['fields']['algorithm'])->toBe('SHA1')
        ->and($kept['fields']['digits'])->toBe(6)
        ->and($kept['fields']['period'])->toBe(30);
});

test('compose merges custom fields by position and keeps blank values', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    $existing = [
        'type' => 'login',
        'fields' => [],
        'custom_fields' => [
            ['label' => 'PIN', 'value' => '1234', 'concealed' => true],
            ['label' => 'Visible', 'value' => 'open', 'concealed' => false],
            'not-an-array',
        ],
    ];

    $composed = $payloads->compose($existing, [
        'type' => 'login',
        'generated' => false,
        'custom_fields' => [
            ['key' => 0, 'label' => '', 'value' => null, 'concealed' => false],
            ['key' => 1, 'label' => 'Renamed', 'value' => 'changed', 'concealed' => true],
            ['key' => null, 'label' => 'New', 'value' => 'x', 'concealed' => false],
            'not-an-array',
        ],
    ]);

    expect($composed['custom_fields'])->toBe([
        ['label' => 'PIN', 'value' => '1234', 'concealed' => false],
        ['label' => 'Renamed', 'value' => 'changed', 'concealed' => true],
        ['label' => 'New', 'value' => 'x', 'concealed' => false],
    ]);
});

test('compose rejects an unsupported type and ignores non-string existing values', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    expect(fn () => $payloads->compose(null, ['type' => 'bogus']))
        ->toThrow(RuntimeException::class);

    $payload = $payloads->compose(['fields' => ['password' => 12345]], [
        'type' => 'login',
        'generated' => false,
        'username' => null,
        'password' => null,
        'url' => null,
        'notes' => null,
    ]);

    expect($payload['fields']['password'])->toBeNull();
});

test('projection exposes the host for logins and null for other types', function () {
    $owner = vaultigen_owner();
    $payloads = app(EntryPayload::class);

    $login = $payloads->projection(
        ['type' => 'login', 'fields' => ['url' => 'HTTPS://Example.COM./deep/path?token=secret']],
        '  Example Account  ',
        $owner->id,
        null,
    );

    expect($login['title'])->toBe('Example Account')
        ->and($login['normalized_title'])->toBe('example account')
        ->and($login['url_host'])->toBe('example.com')
        ->and($login['type'])->toBe('login')
        ->and($login['owner_user_id'])->toBe($owner->id)
        ->and($login['expires_at'])->toBeNull();

    $note = $payloads->projection(['type' => 'note', 'fields' => ['body' => 'x']], 'Note', $owner->id, null);
    expect($note['url_host'])->toBeNull()
        ->and($note['type'])->toBe('note');
});

test('revealable fields exclude TOTP configuration and unknown types are empty', function () {
    $payloads = app(EntryPayload::class);

    expect($payloads->revealableFields('totp'))->toBe(['issuer', 'account', 'secret', 'notes'])
        ->and($payloads->revealableFields('login'))->toBe(['username', 'password', 'url', 'notes', 'totp_secret'])
        ->and($payloads->revealableFields('unknown'))->toBe([])
        ->and($payloads->fields('unknown'))->toBe([])
        ->and($payloads->isRevealable(['type' => 'bogus'], 'email'))->toBeFalse()
        ->and($payloads->isRevealable(['type' => 'totp'], 'algorithm'))->toBeFalse();
});

test('field and customField deliver only known values', function () {
    $payloads = app(EntryPayload::class);

    $payload = [
        'type' => 'login',
        'fields' => ['password' => 'secret', 'digits' => 6],
        'custom_fields' => [
            ['label' => 'PIN', 'value' => '1234'],
            'not-an-array',
        ],
    ];

    expect($payloads->field($payload, 'password'))->toBe('secret')
        ->and($payloads->field($payload, 'username'))->toBe('')
        ->and(fn () => $payloads->field($payload, 'algorithm'))->toThrow(RuntimeException::class)
        ->and($payloads->hasCustomField($payload, 0))->toBeTrue()
        ->and($payloads->hasCustomField($payload, 1))->toBeFalse()
        ->and($payloads->hasCustomField($payload, 9))->toBeFalse()
        ->and($payloads->hasCustomField(['type' => 'login'], 0))->toBeFalse()
        ->and($payloads->customField($payload, 0))->toBe('1234')
        ->and(fn () => $payloads->customField($payload, 9))->toThrow(RuntimeException::class);
});

test('totpSeed and totpParameters resolve per type with safe fallbacks', function () {
    $payloads = app(EntryPayload::class);

    expect($payloads->totpSeed(['type' => 'login', 'fields' => ['totp_secret' => 'SEED']]))->toBe('SEED')
        ->and($payloads->totpSeed(['type' => 'totp', 'fields' => ['secret' => 'SEED']]))->toBe('SEED')
        ->and($payloads->totpSeed(['type' => 'note', 'fields' => ['secret' => 'SEED']]))->toBeNull();

    expect($payloads->totpParameters(['type' => 'login']))->toBe(['algorithm' => 'SHA1', 'digits' => 6, 'period' => 30])
        ->and($payloads->totpParameters([
            'type' => 'totp',
            'fields' => ['algorithm' => 'SHA512', 'digits' => 8, 'period' => 60],
        ]))->toBe(['algorithm' => 'SHA512', 'digits' => 8, 'period' => 60])
        ->and($payloads->totpParameters([
            'type' => 'totp',
            'fields' => ['algorithm' => 'MD5', 'digits' => 7, 'period' => 45],
        ]))->toBe(['algorithm' => 'SHA1', 'digits' => 6, 'period' => 30])
        ->and($payloads->totpParameters(['type' => 'totp', 'fields' => 'not-an-array']))
        ->toBe(['algorithm' => 'SHA1', 'digits' => 6, 'period' => 30]);
});

test('host sanitization handles schemes missing hosts and blank values', function () {
    $payloads = app(EntryPayload::class);

    expect($payloads->host(null))->toBeNull()
        ->and($payloads->host('   '))->toBeNull()
        ->and($payloads->host('example.com'))->toBe('example.com')
        ->and($payloads->host('https://example.com.'))->toBe('example.com')
        ->and($payloads->host('https://'))->toBeNull()
        ->and($payloads->host('...'))->toBeNull();
});

test('encode enforces the documented plaintext size limit', function () {
    expect(fn () => app(EntryPayload::class)->encode(['notes' => str_repeat('a', EntryPayload::MAX_PLAINTEXT_BYTES + 1)]))
        ->toThrow(ValidationException::class);
});

test('encodeDocument rejects values that cannot be encoded as JSON', function () {
    expect(fn () => app(EntryPayload::class)->encodeDocument(['value' => "\xB1\x31"]))
        ->toThrow(RuntimeException::class);
});

test('decode rejects malformed and unsupported documents', function () {
    $payloads = app(EntryPayload::class);

    expect(fn () => $payloads->decode('not-json'))->toThrow(RuntimeException::class);
    expect(fn () => $payloads->decode('123'))->toThrow(RuntimeException::class);
    expect(fn () => $payloads->decode('"a string"'))->toThrow(RuntimeException::class);
    expect(fn () => $payloads->decode(json_encode(['schema_version' => 2, 'type' => 'login', 'fields' => []], JSON_THROW_ON_ERROR)))
        ->toThrow(RuntimeException::class);
    expect(fn () => $payloads->decode(json_encode(['schema_version' => 1, 'type' => 'bogus', 'fields' => []], JSON_THROW_ON_ERROR)))
        ->toThrow(RuntimeException::class);
});
