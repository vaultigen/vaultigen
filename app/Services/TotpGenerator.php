<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Dependency-free RFC 6238 TOTP generation for stored authenticator seeds.
 *
 * Seeds are Base32 (RFC 4648) as produced by authenticator applications. The
 * generator never persists or logs a seed or a derived code; callers deliver a
 * single code after their own authorization and audit.
 */
final class TotpGenerator
{
    /** @var array<string, string> */
    public const ALGORITHMS = [
        'SHA1' => 'sha1',
        'SHA256' => 'sha256',
        'SHA512' => 'sha512',
    ];

    public const MIN_SECRET_BYTES = 10;

    public const MAX_SECRET_BYTES = 64;

    /**
     * Generate the current code for a Base32 seed.
     */
    public function code(string $secret, string $algorithm = 'SHA1', int $digits = 6, int $period = 30, ?int $timestamp = null): string
    {
        return $this->codeFromKey($this->decodeBase32($secret), $algorithm, $digits, $period, $timestamp);
    }

    /**
     * Generate the code from a raw binary key. Kept separate so tests can use
     * the published RFC 6238 vectors directly.
     */
    public function codeFromKey(string $key, string $algorithm = 'SHA1', int $digits = 6, int $period = 30, ?int $timestamp = null): string
    {
        $hashAlgorithm = self::ALGORITHMS[$algorithm] ?? null;
        if ($hashAlgorithm === null) {
            throw new InvalidArgumentException('Unsupported TOTP algorithm.');
        }

        $counter = intdiv($timestamp ?? time(), $period);
        $binary = hash_hmac($hashAlgorithm, pack('J', $counter), $key, true);

        $offset = ord($binary[strlen($binary) - 1]) & 0x0F;
        $value = ((ord($binary[$offset]) & 0x7F) << 24)
            | ((ord($binary[$offset + 1]) & 0xFF) << 16)
            | ((ord($binary[$offset + 2]) & 0xFF) << 8)
            | (ord($binary[$offset + 3]) & 0xFF);

        $modulo = 10 ** $digits;

        return str_pad((string) ($value % $modulo), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Seconds until the current code rolls over, always in 1..period.
     */
    public function secondsRemaining(int $period, ?int $timestamp = null): int
    {
        return $period - (($timestamp ?? time()) % $period);
    }

    /**
     * Whether a stored value is a usable Base32 seed of a sane size. The
     * minimum of 80 bits matches the common authenticator-application floor.
     */
    public function isValidSecret(string $secret): bool
    {
        try {
            $decoded = $this->decodeBase32($secret);
        } catch (RuntimeException) {
            return false;
        }

        return strlen($decoded) >= self::MIN_SECRET_BYTES && strlen($decoded) <= self::MAX_SECRET_BYTES;
    }

    /**
     * Decode a Base32 (RFC 4648) seed. Spaces, hyphens, and case are ignored;
     * padding is optional. Any other character is rejected.
     */
    public function decodeBase32(string $secret): string
    {
        $normalized = strtoupper(preg_replace('/[\s\-]+/', '', $secret) ?? '');
        $normalized = rtrim($normalized, '=');

        if ($normalized === '') {
            throw new RuntimeException('The authenticator secret is empty.');
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $length = strlen($normalized);
        for ($index = 0; $index < $length; $index++) {
            $position = strpos($alphabet, $normalized[$index]);
            if ($position === false) {
                throw new RuntimeException('The authenticator secret is not valid Base32.');
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        for ($offset = 0; $offset + 8 <= strlen($bits); $offset += 8) {
            $decoded .= pack('C', (int) bindec(substr($bits, $offset, 8)));
        }

        if ($decoded === '') {
            throw new RuntimeException('The authenticator secret is too short.');
        }

        return $decoded;
    }
}
