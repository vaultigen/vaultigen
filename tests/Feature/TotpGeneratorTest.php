<?php

use App\Services\TotpGenerator;

const VAULTIGEN_RFC_SHA1_KEY = '12345678901234567890';

const VAULTIGEN_RFC_SHA256_KEY = '12345678901234567890123456789012';

const VAULTIGEN_RFC_SHA512_KEY = '1234567890123456789012345678901234567890123456789012345678901234';

test('generates the published RFC 6238 test vectors', function () {
    $totp = app(TotpGenerator::class);

    $vectors = [
        [59, '94287082', '46119246', '90693936'],
        [1111111109, '07081804', '68084774', '25091201'],
        [1111111111, '14050471', '67062674', '99943326'],
        [1234567890, '89005924', '91819424', '93441116'],
        [2000000000, '69279037', '90698825', '38618901'],
        [20000000000, '65353130', '77737706', '47863826'],
    ];

    foreach ($vectors as [$timestamp, $sha1, $sha256, $sha512]) {
        expect($totp->codeFromKey(VAULTIGEN_RFC_SHA1_KEY, 'SHA1', 8, 30, $timestamp))->toBe($sha1)
            ->and($totp->codeFromKey(VAULTIGEN_RFC_SHA256_KEY, 'SHA256', 8, 30, $timestamp))->toBe($sha256)
            ->and($totp->codeFromKey(VAULTIGEN_RFC_SHA512_KEY, 'SHA512', 8, 30, $timestamp))->toBe($sha512);
    }
});

test('rejects an unsupported hashing algorithm', function () {
    expect(fn () => app(TotpGenerator::class)->codeFromKey('key', 'MD5', 6, 30, 0))
        ->toThrow(InvalidArgumentException::class);
});

test('decodes base32 seeds with separators, case and padding', function () {
    $totp = app(TotpGenerator::class);

    expect(bin2hex($totp->decodeBase32('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ')))->toBe(bin2hex(VAULTIGEN_RFC_SHA1_KEY))
        ->and($totp->decodeBase32('gezd gnbv-gy3t qojq gezd gnbv gy3t qojq'))->toBe(VAULTIGEN_RFC_SHA1_KEY)
        ->and($totp->decodeBase32('MFRGG==='))->toBe('abc');
});

test('generates a code from a base32 seed and reports the rollover window', function () {
    $totp = app(TotpGenerator::class);

    expect($totp->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'SHA1', 8, 30, 59))->toBe('94287082')
        ->and($totp->secondsRemaining(30, 0))->toBe(30)
        ->and($totp->secondsRemaining(30, 29))->toBe(1)
        ->and($totp->secondsRemaining(30, 30))->toBe(30)
        ->and($totp->secondsRemaining(60, 59))->toBe(1);
});

test('validates seeds for length and alphabet', function () {
    $totp = app(TotpGenerator::class);

    expect($totp->isValidSecret('JBSWY3DPEHPK3PXP'))->toBeTrue()
        ->and($totp->isValidSecret('not-base-32!'))->toBeFalse()
        ->and($totp->isValidSecret('ABCD'))->toBeFalse()
        ->and($totp->isValidSecret(str_repeat('A', 110)))->toBeFalse();
});

test('rejects empty, invalid and too-short base32 seeds', function () {
    $totp = app(TotpGenerator::class);

    expect(fn () => $totp->decodeBase32('   '))->toThrow(RuntimeException::class)
        ->and(fn () => $totp->decodeBase32('!!!!'))->toThrow(RuntimeException::class)
        ->and(fn () => $totp->decodeBase32('A'))->toThrow(RuntimeException::class);
});
