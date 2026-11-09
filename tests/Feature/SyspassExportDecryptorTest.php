<?php

use App\Services\Imports\Syspass\ExportDecryptor;
use Tests\Fixtures\SyspassExportBuilder;

function syspass_decryptor(): ExportDecryptor
{
    return app(ExportDecryptor::class);
}

function syspass_defuse_payload(string $binary): string
{
    return base64_encode(bin2hex($binary));
}

/**
 * A `<Data key>` value crafted for one malformed branch.
 */
function syspass_malformed_key(SyspassExportBuilder $builder, string $mode): string
{
    if ($mode === 'short') {
        return '00';
    }
    if ($mode === 'bad-header') {
        return bin2hex('xxxx'.random_bytes(40));
    }
    if ($mode === 'bad-checksum') {
        return bin2hex("\xDE\xF1\x00\x00".random_bytes(40).str_repeat("\x00", 32));
    }
    if ($mode === 'short-inner') {
        $payload = random_bytes(40);
        $binary = "\xDE\xF1\x00\x00".$payload;

        return bin2hex($binary.hash('sha256', $binary, true));
    }

    return $builder->protectedAccountKey($builder->newAccountKey(), 'export-pass');
}

it('decrypts an encrypted export into plaintext fragments', function () {
    $builder = new SyspassExportBuilder('export-pass', 'master-pass');
    $account = $builder->account('1', 'Example', '1', '2', 'alice', 'https://example.com', 'note', 'secret');
    $xml = $builder->build([
        $builder->simpleFragment('Categories', 'Category', [2 => 'Databases']),
        $builder->simpleFragment('Clients', 'Client', [1 => 'Acme']),
        $builder->simpleFragment('Tags', 'Tag', [7 => 'ssh']),
        '  <Accounts>'.PHP_EOL.$account.'  </Accounts>'.PHP_EOL,
    ], ['Generator' => 'sysPass', 'Version' => '322']);

    $plain = syspass_decryptor()->decryptExport($xml, 'export-pass');

    expect($plain)->toContain('Acme')
        ->toContain('Databases')
        ->toContain('Example')
        ->toContain('def10000')
        ->toContain('<Generator>sysPass</Generator>');
});

it('rejects a wrong export password', function () {
    $builder = new SyspassExportBuilder('export-pass');
    $xml = $builder->build(['<Categories/>']);

    expect(fn () => syspass_decryptor()->decryptExport($xml, 'wrong-pass'))
        ->toThrow(RuntimeException::class);
});

it('rejects an export with no encrypted node', function () {
    expect(fn () => syspass_decryptor()->decryptExport('<Root><Meta/></Root>', 'x'))
        ->toThrow(RuntimeException::class);
});

it('rejects an encrypted export with no data nodes', function () {
    $builder = new SyspassExportBuilder('export-pass');

    expect(fn () => syspass_decryptor()->decryptExport($builder->build([]), 'export-pass'))
        ->toThrow(RuntimeException::class);
});

it('rejects an export when the password cannot decrypt the data', function () {
    $builder = new SyspassExportBuilder('export-pass');
    $xml = $builder->build(['<Categories/>'], withHash: false);

    expect(fn () => syspass_decryptor()->decryptExport($xml, 'wrong-pass'))
        ->toThrow(RuntimeException::class);
});

it('rejects malformed data nodes', function (string $payload, string $keyMode) {
    $builder = new SyspassExportBuilder('export-pass');
    $xml = $builder->raw([[
        'key' => syspass_malformed_key($builder, $keyMode),
        'payload' => $payload,
    ]]);

    expect(fn () => syspass_decryptor()->decryptExport($xml, 'export-pass'))
        ->toThrow(RuntimeException::class);
})->with([
    'invalid base64' => ['not base64!!', 'valid'],
    'invalid hex payload' => [base64_encode('zz'), 'valid'],
    'short binary' => [base64_encode('00'), 'valid'],
    'bad version' => [syspass_defuse_payload(random_bytes(85)), 'valid'],
    'integrity failure' => [syspass_defuse_payload("\xDE\xF5\x02\x00".random_bytes(81)), 'valid'],
    'short key' => [syspass_defuse_payload("\xDE\xF5\x02\x00".random_bytes(81)), 'short'],
    'bad key header' => [syspass_defuse_payload("\xDE\xF5\x02\x00".random_bytes(81)), 'bad-header'],
    'bad key checksum' => [syspass_defuse_payload("\xDE\xF5\x02\x00".random_bytes(81)), 'bad-checksum'],
    'short protected key' => [syspass_defuse_payload("\xDE\xF5\x02\x00".random_bytes(81)), 'short-inner'],
]);

it('reports whether account values are encrypted', function () {
    expect(syspass_decryptor()->isEncryptedAccountKey('def10000abc'))->toBeTrue()
        ->and(syspass_decryptor()->isEncryptedAccountKey('plain'))->toBeFalse()
        ->and(syspass_decryptor()->isEncryptedPass('def50200abc'))->toBeTrue()
        ->and(syspass_decryptor()->isEncryptedPass('plain'))->toBeFalse();
});

it('decrypts an account password with the master password', function () {
    $builder = new SyspassExportBuilder('export-pass', 'master-pass');
    $accountKey = $builder->newAccountKey();
    $protected = $builder->protectedAccountKey($accountKey);
    $pass = $builder->encryptedPass('hunter2', $accountKey);

    expect(syspass_decryptor()->decryptAccountPassword($protected, $pass, 'master-pass'))->toBe('hunter2');
});

it('rejects an invalid account password payload', function () {
    $builder = new SyspassExportBuilder('export-pass', 'master-pass');

    expect(fn () => syspass_decryptor()->decryptAccountPassword($builder->protectedAccountKey($builder->newAccountKey()), 'zz', 'master-pass'))
        ->toThrow(RuntimeException::class);
});
