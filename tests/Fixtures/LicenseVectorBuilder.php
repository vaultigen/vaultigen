<?php

namespace Tests\Fixtures;

use App\Services\Licensing\LicenseArmor;

/**
 * Builds the shared license test key pair and verification vectors.
 *
 * Everything is derived from fixed seeds, identifiers and timestamps, and
 * Ed25519 signatures are deterministic, so the output is byte-for-byte
 * reproducible.
 */
final class LicenseVectorBuilder
{
    public const TEST_KID = 'test-1';

    public const KEYPAIR_PATH = 'tests/Fixtures/licensing/test-keypair.json';

    public const VECTORS_PATH = 'tests/Fixtures/licensing/vectors.json';

    public const RELEASE_DATE = '2026-10-01';

    /**
     * @return array{kid: string, note: string, public_key: string, secret_key: string}
     */
    public static function keypairFile(): array
    {
        $keypair = self::keypair('vaultigen-license-test-1');

        return [
            'kid' => self::TEST_KID,
            'note' => 'TEST ONLY. Derived from a public seed. Production code never trusts this key; tests inject it through the container.',
            'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'secret_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
        ];
    }

    /**
     * @return array{format: int, trusted_keys: array<string, string>, cases: list<array{name: string, description: string, release_date: ?string, armored: string, expected: array{state: string, reason: ?string}}>}
     */
    public static function vectors(): array
    {
        $trusted = sodium_crypto_sign_secretkey(self::keypair('vaultigen-license-test-1'));
        $untrusted = sodium_crypto_sign_secretkey(self::keypair('vaultigen-license-untrusted'));
        $pro = self::payload();
        $valid = self::sign(self::payloadBytes($pro), $trusted);

        $cases = [
            self::licensed('valid-pro', 'Pro license with unlimited users, released inside the update window.', $valid),
            self::licensed('valid-pro-capped', 'Pro license limited to 50 users.', self::signed(['max_users' => 50], $trusted)),
            self::licensed('valid-lifetime', 'Lifetime license (no update end) evaluated against a release far in the future.', self::signed(['edition' => 'lifetime', 'updates_until' => null], $trusted), '2040-01-01'),
            self::licensed('boundary-release-on-last-day', 'The release date equals updates_until, which is inclusive.', $valid, '2027-10-01'),
            self::case('missing-release-date', 'A build without a release date is covered by no dated update window.', self::signed(['updates_until' => '2099-12-31'], $trusted), 'updates_expired', null, null),
            self::licensed('unwrapped-body', 'The body on a single line instead of 64-character lines.', self::unwrapped($valid)),
            self::licensed('crlf-line-endings', 'Windows line endings.', str_replace("\n", "\r\n", $valid)),
            self::licensed('surrounding-whitespace', 'Indented lines and blank lines around the block, as pasted from an email.', "\n\n  ".str_replace("\n", "\n  ", $valid)."\n\t\n"),
            self::licensed('unknown-extra-field', 'Unknown additional fields are ignored.', self::signed(['support_tier' => 'priority'], $trusted)),
            self::case('updates-expired', 'The release date is one day after updates_until.', $valid, 'updates_expired', null, '2027-10-02'),
            self::invalid('bad-signature-wrong-key', 'Signed with an untrusted key while claiming the trusted kid.', self::sign(self::payloadBytes($pro), $untrusted), 'bad_signature'),
            self::invalid('bad-signature-tampered-payload', 'The licensee was changed after signing.', self::tampered($pro, $trusted), 'bad_signature'),
            self::invalid('unknown-kid', 'Signed with a key id the verifier does not trust.', self::signed(['kid' => 'unknown-9'], $untrusted), 'unknown_key'),
            self::invalid('missing-kid', 'The payload has no kid.', self::signed([], $trusted, remove: ['kid']), 'unknown_key'),
            self::invalid('unsupported-version', 'Payload version 2.', self::signed(['v' => 2], $trusted), 'unsupported_version'),
            self::invalid('wrong-product', 'A license for a different product.', self::signed(['product' => 'other-product'], $trusted), 'wrong_product'),
            self::invalid('schema-missing-licensee', 'A required field is missing.', self::signed([], $trusted, remove: ['licensee']), 'schema'),
            self::invalid('schema-empty-licensee', 'The licensee is only whitespace.', self::signed(['licensee' => '   '], $trusted), 'schema'),
            self::invalid('schema-impossible-date', 'updates_until is not a real calendar date.', self::signed(['updates_until' => '2027-13-45'], $trusted), 'schema'),
            self::invalid('schema-max-users-below-free', 'max_users below the free edition limit.', self::signed(['max_users' => 5], $trusted), 'schema'),
            self::invalid('schema-max-users-string', 'max_users as a string instead of an integer.', self::signed(['max_users' => '50'], $trusted), 'schema'),
            self::invalid('schema-unknown-edition', 'An edition that does not exist.', self::signed(['edition' => 'enterprise'], $trusted), 'schema'),
            self::invalid('schema-issued-at-not-string', 'issued_at as a Unix timestamp instead of an ISO-8601 string.', self::signed(['issued_at' => 1790856000], $trusted), 'schema'),
            self::invalid('schema-bad-license-id', 'license_id without the lic_ prefix.', self::signed(['license_id' => '01J9ZQ3V6K8M2N4P5R7S9T1W3X'], $trusted), 'schema'),
            self::invalid('malformed-missing-armor', 'The body without BEGIN/END lines.', self::body($valid), 'malformed'),
            self::invalid('malformed-trailing-text', 'Text after the END line.', $valid."Kind regards\n", 'malformed'),
            self::invalid('malformed-base64-padding', 'base64url padding is not allowed.', self::padded($pro, $trusted), 'malformed'),
            self::invalid('malformed-three-parts', 'The body has an extra dot-separated part.', str_replace(LicenseArmor::END, '.AAAA'."\n".LicenseArmor::END, self::unwrapped($valid)), 'malformed'),
            self::invalid('malformed-short-signature', 'The signature does not decode to 64 bytes.', self::wrap(self::payloadBytes($pro), str_repeat("\0", 32)), 'malformed'),
            self::invalid('malformed-payload-not-json', 'The signed payload is not valid JSON.', self::sign('{"v":1,', $trusted), 'malformed'),
            self::invalid('malformed-payload-not-object', 'The signed payload is a JSON array, not an object.', self::sign('["vaultigen"]', $trusted), 'malformed'),
            self::invalid('malformed-oversized', 'The input exceeds 8192 bytes, even though the signature is valid.', self::signed(['padding' => str_repeat('x', 8192)], $trusted), 'malformed'),
        ];

        return [
            'format' => 1,
            'trusted_keys' => [self::TEST_KID => self::keypairFile()['public_key']],
            'cases' => $cases,
        ];
    }

    /**
     * Sign a license with the test key, starting from the valid Pro payload.
     *
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $remove
     */
    public static function testLicense(array $overrides = [], array $remove = []): string
    {
        return self::signed($overrides, sodium_crypto_sign_secretkey(self::keypair('vaultigen-license-test-1')), $remove);
    }

    public static function encode(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private static function keypair(string $seedLabel): string
    {
        return sodium_crypto_sign_seed_keypair(hash('sha256', $seedLabel, true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'v' => 1,
            'product' => 'vaultigen',
            'kid' => self::TEST_KID,
            'license_id' => 'lic_01J9ZQ3V6K8M2N4P5R7S9T1W3X',
            'licensee' => 'ACME GmbH',
            'email' => 'it@acme.example',
            'edition' => 'pro',
            'max_users' => null,
            'updates_until' => '2027-10-01',
            'issued_at' => '2026-10-01T12:00:00Z',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $remove
     */
    private static function signed(array $overrides, string $secretKey, array $remove = []): string
    {
        $payload = array_diff_key(array_merge(self::payload(), $overrides), array_flip($remove));

        return self::sign(self::payloadBytes($payload), $secretKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function tampered(array $payload, string $secretKey): string
    {
        $signature = sodium_crypto_sign_detached(self::payloadBytes($payload), $secretKey);

        return self::wrap(self::payloadBytes(array_merge($payload, ['licensee' => 'EVIL GmbH'])), $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function padded(array $payload, string $secretKey): string
    {
        $bytes = self::payloadBytes($payload);
        $body = strtr(base64_encode($bytes), '+/', '-_').'.'.strtr(base64_encode(sodium_crypto_sign_detached($bytes, $secretKey)), '+/', '-_');

        return LicenseArmor::BEGIN."\n".$body."\n".LicenseArmor::END."\n";
    }

    private static function body(string $armored): string
    {
        return trim(str_replace([LicenseArmor::BEGIN, LicenseArmor::END], '', $armored))."\n";
    }

    private static function unwrapped(string $armored): string
    {
        return LicenseArmor::BEGIN."\n".str_replace("\n", '', self::body($armored))."\n".LicenseArmor::END."\n";
    }

    /**
     * @return array{name: string, description: string, release_date: ?string, armored: string, expected: array{state: string, reason: ?string}}
     */
    private static function licensed(string $name, string $description, string $armored, ?string $releaseDate = self::RELEASE_DATE): array
    {
        return self::case($name, $description, $armored, 'licensed', null, $releaseDate);
    }

    /**
     * @return array{name: string, description: string, release_date: ?string, armored: string, expected: array{state: string, reason: ?string}}
     */
    private static function invalid(string $name, string $description, string $armored, string $reason): array
    {
        return self::case($name, $description, $armored, 'invalid', $reason, self::RELEASE_DATE);
    }

    /**
     * @return array{name: string, description: string, release_date: ?string, armored: string, expected: array{state: string, reason: ?string}}
     */
    private static function case(string $name, string $description, string $armored, string $state, ?string $reason, ?string $releaseDate): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'release_date' => $releaseDate,
            'armored' => $armored,
            'expected' => ['state' => $state, 'reason' => $reason],
        ];
    }

    /**
     * Encode a payload as JSON bytes, the exact bytes that get signed.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function payloadBytes(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sign the payload bytes with an Ed25519 secret key and armor the result.
     *
     * @param  non-empty-string  $secretKey
     */
    private static function sign(string $payloadBytes, #[\SensitiveParameter] string $secretKey): string
    {
        return self::wrap($payloadBytes, sodium_crypto_sign_detached($payloadBytes, $secretKey));
    }

    /**
     * Armor payload bytes and a signature without checking that they belong together.
     */
    private static function wrap(string $payloadBytes, string $signature): string
    {
        $body = LicenseArmor::base64UrlEncode($payloadBytes).'.'.LicenseArmor::base64UrlEncode($signature);

        return LicenseArmor::BEGIN."\n".implode("\n", str_split($body, 64))."\n".LicenseArmor::END."\n";
    }
}
