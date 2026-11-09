<?php

namespace App\Services\Licensing;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use stdClass;

/**
 * Verifies armored v1 license files offline.
 *
 * Steps run in the specified order and stop at the first failure. Only the
 * key id is read from the payload before its signature has been verified.
 */
final class LicenseVerifier
{
    private const FIELDS = ['v', 'product', 'kid', 'license_id', 'licensee', 'email', 'edition', 'max_users', 'updates_until', 'issued_at'];

    private const BASE64URL = '/^[A-Za-z0-9_-]+$/';

    public function __construct(private readonly TrustedKeys $keys) {}

    /**
     * @throws InvalidLicense
     */
    public function verify(#[\SensitiveParameter] string $armored): VerifiedLicense
    {
        [$payloadBytes, $signature] = $this->unarmor($armored);
        $payload = $this->decodePayload($payloadBytes);

        $kid = $payload['kid'] ?? null;
        $publicKey = is_string($kid) ? $this->keys->publicKey($kid) : null;
        if (! is_string($kid) || $publicKey === null) {
            throw new InvalidLicense('unknown_key');
        }
        if (! sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey)) {
            throw new InvalidLicense('bad_signature');
        }

        if (($payload['v'] ?? null) !== 1) {
            throw new InvalidLicense('unsupported_version');
        }
        if (($payload['product'] ?? null) !== 'vaultigen') {
            throw new InvalidLicense('wrong_product');
        }

        return $this->validated($payload, $kid);
    }

    /**
     * @return array{0: string, 1: non-empty-string}
     */
    private function unarmor(string $armored): array
    {
        if (strlen($armored) > LicenseArmor::MAX_BYTES) {
            throw new InvalidLicense('malformed');
        }

        $armored = trim($armored, " \t\r\n");
        if (! str_starts_with($armored, LicenseArmor::BEGIN) || ! str_ends_with($armored, LicenseArmor::END)
            || strlen($armored) < strlen(LicenseArmor::BEGIN) + strlen(LicenseArmor::END)) {
            throw new InvalidLicense('malformed');
        }

        $body = (string) preg_replace('/[ \t\r\n]+/', '', substr($armored, strlen(LicenseArmor::BEGIN), -strlen(LicenseArmor::END)));
        $parts = explode('.', $body);
        if (count($parts) !== 2) {
            throw new InvalidLicense('malformed');
        }

        $payloadBytes = $this->base64UrlDecode($parts[0]);
        $signature = $this->base64UrlDecode($parts[1]);
        if ($payloadBytes === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidLicense('malformed');
        }

        return [$payloadBytes, $signature];
    }

    /**
     * Decode unpadded base64url, accepting only its canonical encoding.
     *
     * @return non-empty-string|null
     */
    private function base64UrlDecode(string $encoded): ?string
    {
        if (preg_match(self::BASE64URL, $encoded) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return is_string($decoded) && $decoded !== '' && LicenseArmor::base64UrlEncode($decoded) === $encoded ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $payloadBytes): array
    {
        try {
            $payload = json_decode($payloadBytes, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidLicense('malformed');
        }
        if (! $payload instanceof stdClass) {
            throw new InvalidLicense('malformed');
        }

        return get_object_vars($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validated(array $payload, string $kid): VerifiedLicense
    {
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidLicense('schema');
            }
        }

        $licenseId = $payload['license_id'];
        $licensee = is_string($payload['licensee']) ? trim($payload['licensee']) : null;
        $email = $payload['email'];
        $edition = $payload['edition'];
        $maxUsers = $payload['max_users'];
        $updatesUntil = $payload['updates_until'];
        $issuedAt = $payload['issued_at'];

        $valid = is_string($licenseId) && preg_match('/^lic_[0-9A-HJKMNP-TV-Z]{26}$/', $licenseId) === 1
            && $licensee !== null && $licensee !== '' && mb_strlen($licensee) <= 120
            && is_string($email) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && in_array($edition, ['pro', 'lifetime'], true)
            && ($maxUsers === null || (is_int($maxUsers) && $maxUsers > LicenseManager::FREE_SEATS))
            && ($updatesUntil === null || $this->isExactDateTime($updatesUntil, '!Y-m-d'))
            && $this->isExactDateTime($issuedAt, '!Y-m-d\TH:i:s\Z');

        if (! $valid) {
            throw new InvalidLicense('schema');
        }

        return new VerifiedLicense($kid, $licenseId, $licensee, $email, $edition, $maxUsers, $updatesUntil, $issuedAt);
    }

    /**
     * Whether the value is a real calendar date/time in exactly this format.
     *
     * @phpstan-assert-if-true string $value
     */
    private function isExactDateTime(mixed $value, string $format): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format(ltrim($format, '!')) === $value;
    }
}
