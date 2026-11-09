<?php

use App\Services\Imports\Keepass\KeepassImportException;
use App\Services\Imports\Keepass\KeepassXmlParser;
use Tests\Fixtures\KeepassXmlBuilder;

/**
 * @return array<string, mixed>
 */
function keepass_parse(string $xml, int $maxBlobBytes = 1024 * 1024, int $maxTotalBytes = 4 * 1024 * 1024): array
{
    return app(KeepassXmlParser::class)->parse($xml, $maxBlobBytes, $maxTotalBytes);
}

function keepass_parse_reason(string $xml): ?string
{
    try {
        keepass_parse($xml);
    } catch (KeepassImportException $exception) {
        return $exception->reason;
    }

    return null;
}

it('maps the standard strings to entry fields and other strings to custom fields', function () {
    $builder = new KeepassXmlBuilder;
    $xml = $builder->build([
        $builder->entry('GitHub', [
            'UserName' => 'alice',
            'Password' => 'hunter2',
            'URL' => 'https://github.com',
            'Notes' => "line one\nline two",
            'Recovery code' => 'abc-123',
            'PIN' => '4711',
        ], ['protect' => ['Password', 'PIN'], 'uuid' => KeepassXmlBuilder::uuid('github')]),
    ]);

    $entry = keepass_parse($xml)['entries'][0];

    expect($entry)->toMatchArray([
        'source_id' => KeepassXmlBuilder::uuid('github'),
        'title' => 'GitHub',
        'username' => 'alice',
        'password' => 'hunter2',
        'url' => 'https://github.com',
        'notes' => "line one\nline two",
        'totp' => null,
        'expires_at' => null,
        'axis_values' => [],
        'context' => '',
    ])->and($entry['custom_fields'])->toBe([
        ['label' => 'Recovery code', 'value' => 'abc-123', 'concealed' => false],
        ['label' => 'PIN', 'value' => '4711', 'concealed' => true],
    ]);
});

it('rejects files that are not plain KeePass XML exports', function (string $xml, string $reason) {
    expect(keepass_parse_reason($xml))->toBe($reason);
})->with([
    'malformed xml' => ['<KeePassFile><Root>', KeepassImportException::NOT_KEEPASS],
    'another xml format' => ['<Root><Encrypted/></Root>', KeepassImportException::NOT_KEEPASS],
    'no root group' => ['<KeePassFile><Meta/><Root/></KeePassFile>', KeepassImportException::NOT_KEEPASS],
    'external entity' => [
        '<?xml version="1.0"?><!DOCTYPE KeePassFile [<!ENTITY x SYSTEM "file:///etc/passwd">]><KeePassFile><Root><Group><Name>&x;</Name></Group></Root></KeePassFile>',
        KeepassImportException::NOT_KEEPASS,
    ],
    'nested too deeply' => [
        '<KeePassFile><Root>'.str_repeat('<Group><Name>g</Name>', 70).str_repeat('</Group>', 70).'</Root></KeePassFile>',
        KeepassImportException::NOT_KEEPASS,
    ],
    'stream-protected values' => [
        (fn (KeepassXmlBuilder $builder): string => $builder->build([
            $builder->entry('Raw', ['Password' => 'c2VjcmV0'], ['stream_protected' => ['Password']]),
        ]))(new KeepassXmlBuilder),
        KeepassImportException::PROTECTED_VALUES,
    ],
]);

it('parses an indented export that omits optional elements', function () {
    $xml = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <KeePassFile>
            <Meta>
                <Generator>KeePass</Generator>
            </Meta>
            <Root>
                <Group>
                    <Name>Root</Name>
                    <Group>
                        <Name>Work</Name>
                        <Entry>
                            <String>
                                <Key>Title</Key>
                                <Value>Mail</Value>
                            </String>
                        </Entry>
                    </Group>
                </Group>
            </Root>
        </KeePassFile>
        XML;

    $ir = keepass_parse($xml);

    expect($ir['root_label'])->toBe('Root')
        ->and($ir['axes']['group_path'])->toBe(['group-1' => 'Work'])
        ->and($ir['entries'])->toHaveCount(1)
        ->and($ir['entries'][0])->toMatchArray([
            'source_id' => null,
            'title' => 'Mail',
            'username' => null,
            'tag_names' => [],
            'expires_at' => null,
            'axis_values' => ['top_group' => 'group-1', 'group_path' => 'group-1'],
            'attachments' => [],
        ]);
});

it('skips the recycle bin and entry history and counts both', function () {
    $builder = (new KeepassXmlBuilder)->recycleBin(KeepassXmlBuilder::uuid('bin'));
    $xml = $builder->build([
        $builder->entry('Kept', [], ['history' => 2]),
        $builder->group('Recycle Bin', [
            $builder->entry('Deleted one'),
            $builder->group('Deleted group', [$builder->entry('Deleted two')]),
        ], KeepassXmlBuilder::uuid('bin')),
    ]);

    $ir = keepass_parse($xml);

    expect(array_column($ir['entries'], 'title'))->toBe(['Kept'])
        ->and($ir['warnings'])->toBe(['recycle_bin' => 2, 'history' => 2])
        ->and($ir['meta']['groups'])->toBe(0);
});

it('identifies groups by uuid on every axis and keeps entries at the root unassigned', function () {
    $builder = new KeepassXmlBuilder;
    $xml = $builder->build([
        $builder->entry('At root'),
        $builder->group('Work', [
            $builder->entry('Direct'),
            $builder->group('Servers', [$builder->entry('Deep')], KeepassXmlBuilder::uuid('servers')),
        ], KeepassXmlBuilder::uuid('work')),
    ], 'My Database');

    $ir = keepass_parse($xml);
    [$root, $direct, $deep] = $ir['entries'];

    expect($ir['root_label'])->toBe('My Database')
        ->and($root['axis_values'])->toBe([])
        ->and($direct['axis_values'])->toBe([
            'top_group' => KeepassXmlBuilder::uuid('work'),
            'group_path' => KeepassXmlBuilder::uuid('work'),
        ])
        ->and($deep['axis_values'])->toBe([
            'top_group' => KeepassXmlBuilder::uuid('work'),
            'group_path' => KeepassXmlBuilder::uuid('servers'),
            'subgroup' => KeepassXmlBuilder::uuid('servers'),
        ])
        ->and($deep['context'])->toBe('Work / Servers')
        ->and($ir['axes'])->toBe([
            'top_group' => [KeepassXmlBuilder::uuid('work') => 'Work'],
            'subgroup' => [KeepassXmlBuilder::uuid('servers') => 'Servers'],
            'group_path' => [
                KeepassXmlBuilder::uuid('work') => 'Work',
                KeepassXmlBuilder::uuid('servers') => 'Work / Servers',
            ],
        ]);
});

it('suffixes colliding labels of distinct groups and bounds long paths', function () {
    $builder = new KeepassXmlBuilder;
    $long = str_repeat('x', 80);
    $xml = $builder->build([
        $builder->group('A/B', [$builder->entry('Slash in name')], KeepassXmlBuilder::uuid('slash')),
        $builder->group('A', [$builder->group('B', [$builder->entry('Real path')], KeepassXmlBuilder::uuid('b'))], KeepassXmlBuilder::uuid('a')),
        $builder->group('a/b', [$builder->entry('Same, other case')], KeepassXmlBuilder::uuid('lower')),
        $builder->group($long, [$builder->group($long, [$builder->entry('Long')], KeepassXmlBuilder::uuid('long-child'))], KeepassXmlBuilder::uuid('long')),
    ]);

    $paths = keepass_parse($xml)['axes']['group_path'];

    expect($paths[KeepassXmlBuilder::uuid('slash')])->toBe('A/B')
        ->and($paths[KeepassXmlBuilder::uuid('b')])->toBe('A / B')
        ->and($paths[KeepassXmlBuilder::uuid('lower')])->toBe('a/b (2)')
        ->and(mb_strlen($paths[KeepassXmlBuilder::uuid('long-child')]))->toBe(100);
});

it('converts supported totp configurations to a base32 seed', function (array $fields, string $secret) {
    $builder = new KeepassXmlBuilder;
    $xml = $builder->build([$builder->entry('Totp', $fields)]);

    $ir = keepass_parse($xml);

    expect($ir['entries'][0]['totp'])->toBe(['secret' => $secret])
        ->and($ir['entries'][0]['custom_fields'])->toBe([])
        ->and($ir['warnings'])->toBe([]);
})->with([
    'keepassxc otpauth uri' => [['otp' => 'otpauth://totp/Example:alice?secret=JBSWY3DPEHPK3PXP&period=30&digits=6&issuer=Example'], 'JBSWY3DPEHPK3PXP'],
    'keepassxc key form' => [['otp' => 'key=jbswy3dpehpk3pxp&step=30&size=6'], 'JBSWY3DPEHPK3PXP'],
    'keepass utf-8 secret' => [['TimeOtp-Secret' => '12345678901234567890'], 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'],
    'keepass hex secret' => [['TimeOtp-Secret-Hex' => '48656c6c6f21deadbeef', 'TimeOtp-Algorithm' => 'HMAC-SHA-1'], 'JBSWY3DPEHPK3PXP'],
    'keepass base32 secret' => [['TimeOtp-Secret-Base32' => 'JBSW Y3DP EHPK 3PXP', 'TimeOtp-Length' => '6', 'TimeOtp-Period' => '30'], 'JBSWY3DPEHPK3PXP'],
    'keepass base64 secret' => [['TimeOtp-Secret-Base64' => 'SGVsbG8h3q2+7w=='], 'JBSWY3DPEHPK3PXP'],
    'secret not a multiple of five bytes' => [['TimeOtp-Secret' => 'abcdefghijklmnop'], 'MFRGGZDFMZTWQ2LKNNWG23TPOA'],
    'legacy seed and settings' => [['TOTP Seed' => 'JBSWY3DPEHPK3PXP', 'TOTP Settings' => '30;6'], 'JBSWY3DPEHPK3PXP'],
]);

it('keeps unsupported totp configurations as concealed custom fields', function (array $fields) {
    $builder = new KeepassXmlBuilder;
    $xml = $builder->build([$builder->entry('Totp', $fields)]);

    $ir = keepass_parse($xml);
    $expected = [];
    foreach ($fields as $label => $value) {
        $expected[] = ['label' => $label, 'value' => $value, 'concealed' => true];
    }

    expect($ir['entries'][0]['totp'])->toBeNull()
        ->and($ir['entries'][0]['custom_fields'])->toBe($expected)
        ->and($ir['warnings'])->toBe(['totp_unsupported' => 1]);
})->with([
    'eight digits' => [['otp' => 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP&digits=8']],
    'sha256' => [['otp' => 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP&algorithm=SHA256']],
    'sixty second period' => [['TimeOtp-Secret-Base32' => 'JBSWY3DPEHPK3PXP', 'TimeOtp-Period' => '60']],
    'hotp' => [['otp' => 'otpauth://hotp/x?secret=JBSWY3DPEHPK3PXP&counter=1']],
    'steam encoder' => [['otp' => 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP&encoder=steam']],
    'steam legacy settings' => [['TOTP Seed' => 'JBSWY3DPEHPK3PXP', 'TOTP Settings' => '30;S']],
    'invalid base32' => [['otp' => 'key=not*base32']],
    'too short a seed' => [['TimeOtp-Secret' => 'short']],
    'uri without a secret' => [['otp' => 'otpauth://totp/x?period=30']],
    'keeotp hotp key form' => [['otp' => 'key=JBSWY3DPEHPK3PXP&type=Hotp']],
    'legacy settings without a seed' => [['TOTP Settings' => '30;6']],
]);

it('reads expiry from iso and kdbx 4 timestamps only when the entry expires', function (?string $expires, ?string $expected) {
    $builder = new KeepassXmlBuilder;
    $xml = $builder->build([$builder->entry('Expiring', [], $expires === null ? [] : ['expires' => $expires])]);

    expect(keepass_parse($xml)['entries'][0]['expires_at'])->toBe($expected);
})->with([
    'iso date' => ['2030-05-06T07:08:09Z', '2030-05-06 07:08:09'],
    'kdbx 4 base64 seconds' => [base64_encode(pack('P', 62135596800 + 1893456000)), '2030-01-01 00:00:00'],
    'never expires' => [null, null],
    'beyond the timestamp range' => ['9999-12-31T23:59:59Z', null],
    'unreadable' => ['tomorrow-ish', null],
    'impossible calendar date' => ['2030-13-45T00:00:00Z', null],
    'expires without a time' => ['', null],
]);

it('splits tags on semicolons and commas and bounds their length', function () {
    $builder = new KeepassXmlBuilder;
    $xml = $builder->build([$builder->entry('Tagged', [], ['tags' => 'ssh; prod,Prod;;'.str_repeat('t', 70)])]);

    expect(keepass_parse($xml)['entries'][0]['tag_names'])->toBe(['ssh', 'prod', str_repeat('t', 60)]);
});

it('counts entries with field references', function () {
    $builder = new KeepassXmlBuilder;
    $xml = $builder->build([
        $builder->entry('Reference', ['Password' => '{REF:P@I:46C9B1FFBD4ABC4BBB260C6190BAD20C}']),
        $builder->entry('Plain', ['Password' => 'secret']),
    ]);

    $ir = keepass_parse($xml);

    expect($ir['warnings'])->toBe(['field_references' => 1])
        ->and($ir['entries'][0]['password'])->toBe('{REF:P@I:46C9B1FFBD4ABC4BBB260C6190BAD20C}');
});

it('decodes plain and gzip attachments and records unreadable ones per file', function () {
    $builder = (new KeepassXmlBuilder)
        ->binary('0', 'plain bytes')
        ->binary('1', str_repeat('compressed ', 50), compressed: true)
        ->rawBinary('2', 'not*base64')
        ->rawBinary('3', base64_encode('not gzip'), compressed: true)
        ->binary('4', str_repeat('a', 2048))
        ->binary('5', str_repeat("\0", 5 * 1024 * 1024), compressed: true)
        ->rawBinary('6', '', compressed: true)
        ->rawBinary('7', base64_encode(substr((string) gzencode(str_repeat('truncated ', 50)), 0, 20)), compressed: true);
    $xml = $builder->build([
        $builder->entry('Files', [], ['binaries' => [
            'plain.txt' => '0',
            'zipped.txt' => '1',
            'bad.bin' => '2',
            'broken.gz' => '3',
            'big.bin' => '4',
            'bomb.bin' => '5',
            'ghost.bin' => '9',
            'empty.gz' => '6',
            'truncated.gz' => '7',
            'plain-again.txt' => '0',
            'bad-again.bin' => '2',
        ], 'inline_binaries' => ['inline.txt' => 'inline bytes']]),
    ]);

    $ir = keepass_parse($xml, maxBlobBytes: 1024);

    expect($ir['entries'][0]['attachments'])->toBe([
        ['filename' => 'plain.txt', 'blob_ref' => '0'],
        ['filename' => 'zipped.txt', 'blob_ref' => '1'],
        ['filename' => 'bad.bin', 'invalid' => 'invalid_base64'],
        ['filename' => 'broken.gz', 'invalid' => 'decompression_failed'],
        ['filename' => 'big.bin', 'invalid' => 'too_large'],
        ['filename' => 'bomb.bin', 'invalid' => 'too_large'],
        ['filename' => 'ghost.bin', 'invalid' => 'missing_reference'],
        ['filename' => 'empty.gz', 'invalid' => 'decompression_failed'],
        ['filename' => 'truncated.gz', 'invalid' => 'decompression_failed'],
        ['filename' => 'plain-again.txt', 'blob_ref' => '0'],
        ['filename' => 'bad-again.bin', 'invalid' => 'invalid_base64'],
        ['filename' => 'inline.txt', 'blob_ref' => 'inline-1'],
    ])
        ->and($ir['blobs'])->toBe([
            '0' => base64_encode('plain bytes'),
            '1' => base64_encode(str_repeat('compressed ', 50)),
            'inline-1' => base64_encode('inline bytes'),
        ])
        ->and($ir['warnings'])->toBe(['invalid_attachments' => 8]);
});

it('fails when the decoded attachments exceed the import budget', function () {
    $builder = (new KeepassXmlBuilder)->binary('0', str_repeat('a', 600))->binary('1', str_repeat('b', 600));
    $xml = $builder->build([
        $builder->entry('One', [], ['binaries' => ['a.bin' => '0']]),
        $builder->entry('Two', [], ['binaries' => ['b.bin' => '1', 'a-again.bin' => '0']]),
    ]);

    expect(fn () => keepass_parse($xml, maxBlobBytes: 1000, maxTotalBytes: 1000))
        ->toThrow(fn (KeepassImportException $exception) => expect($exception->reason)->toBe(KeepassImportException::ATTACHMENTS_TOO_LARGE));
});
