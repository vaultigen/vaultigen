<?php

use Tests\Fixtures\LicenseVectorBuilder;

/**
 * @return array{payload: array<string, mixed>, verified: bool}
 */
function decodeTestLicense(string $armored): array
{
    $body = preg_replace('/\s+/', '', str_replace(['-----BEGIN VAULTIGEN LICENSE-----', '-----END VAULTIGEN LICENSE-----'], '', $armored));
    [$payload, $signature] = array_map(fn (string $part): string => base64_decode(strtr($part, '-_', '+/'), true), explode('.', $body));
    $publicKey = base64_decode(LicenseVectorBuilder::keypairFile()['public_key'], true);

    return [
        'payload' => json_decode($payload, true, flags: JSON_THROW_ON_ERROR),
        'verified' => sodium_crypto_sign_verify_detached($signature, $payload, $publicKey),
    ];
}

test('the committed license fixtures match the deterministic generator', function () {
    expect(file_get_contents(base_path(LicenseVectorBuilder::KEYPAIR_PATH)))
        ->toBe(LicenseVectorBuilder::encode(LicenseVectorBuilder::keypairFile()))
        ->and(file_get_contents(base_path(LicenseVectorBuilder::VECTORS_PATH)))
        ->toBe(LicenseVectorBuilder::encode(LicenseVectorBuilder::vectors()));
});

test('the vectors sign valid licenses with the published test key', function () {
    $vectors = LicenseVectorBuilder::vectors();
    $valid = collect($vectors['cases'])->firstWhere('name', 'valid-pro');

    expect($vectors['trusted_keys'])->toBe([LicenseVectorBuilder::TEST_KID => LicenseVectorBuilder::keypairFile()['public_key']])
        ->and(decodeTestLicense($valid['armored']))->toMatchArray(['verified' => true])
        ->and(decodeTestLicense(collect($vectors['cases'])->firstWhere('name', 'bad-signature-wrong-key')['armored'])['verified'])->toBeFalse();
});
