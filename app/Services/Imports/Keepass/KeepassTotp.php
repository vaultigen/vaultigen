<?php

namespace App\Services\Imports\Keepass;

use App\Services\TotpGenerator;
use RuntimeException;

/**
 * Reads the TOTP configuration KeePass and KeePassXC store as entry strings.
 *
 * A login entry only keeps a seed and always generates SHA1 / 6 digits / 30 s
 * codes, so a seed is accepted only for exactly those parameters. Anything
 * else, or anything that cannot be parsed, is reported as unsupported and the
 * caller keeps the original fields, so no imported entry ever generates wrong
 * codes. Supported sources, checked in this order:
 *
 * - KeePassXC `otp`: an `otpauth://totp/...` URI or the `key=…&step=…&size=…`
 *   form.
 * - KeePass 2.47+ `TimeOtp-Secret` (UTF-8), `-Hex`, `-Base32`, or `-Base64`,
 *   with `TimeOtp-Length`, `TimeOtp-Period`, and `TimeOtp-Algorithm`.
 * - The legacy `TOTP Seed` with `TOTP Settings` (`period;digits`).
 */
final class KeepassTotp
{
    public const OTP = 'otp';

    public const LEGACY_SEED = 'TOTP Seed';

    public const LEGACY_SETTINGS = 'TOTP Settings';

    /** @var list<string> */
    public const TIME_OTP_SECRETS = ['TimeOtp-Secret', 'TimeOtp-Secret-Hex', 'TimeOtp-Secret-Base32', 'TimeOtp-Secret-Base64'];

    /** @var list<string> */
    public const TIME_OTP_SETTINGS = ['TimeOtp-Length', 'TimeOtp-Period', 'TimeOtp-Algorithm'];

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const SUPPORTED_ALGORITHMS = ['SHA1', 'HMAC-SHA-1', 'HMACSHA1', 'SHA-1'];

    public function __construct(private readonly TotpGenerator $generator) {}

    /**
     * Whether an entry string belongs to a TOTP configuration.
     */
    public static function isTotpField(string $key): bool
    {
        return in_array($key, [self::OTP, self::LEGACY_SEED, self::LEGACY_SETTINGS, ...self::TIME_OTP_SECRETS, ...self::TIME_OTP_SETTINGS], true);
    }

    /**
     * The Base32 seed and the fields it was read from, or null when the
     * configuration is unsupported.
     *
     * @param  array<string, string>  $fields  only the entry's TOTP fields
     * @return array{secret: string, consumed: list<string>}|null
     */
    public function parse(#[\SensitiveParameter] array $fields): ?array
    {
        if (array_key_exists(self::OTP, $fields)) {
            return $this->accept($this->fromOtp($fields[self::OTP]), [self::OTP]);
        }

        $timeOtpKeys = array_values(array_intersect([...self::TIME_OTP_SECRETS, ...self::TIME_OTP_SETTINGS], array_keys($fields)));
        if ($timeOtpKeys !== []) {
            return $this->accept($this->fromTimeOtp($fields), $timeOtpKeys);
        }

        if (array_key_exists(self::LEGACY_SEED, $fields)) {
            return $this->accept(
                $this->fromLegacy($fields[self::LEGACY_SEED], $fields[self::LEGACY_SETTINGS] ?? null),
                array_values(array_intersect([self::LEGACY_SEED, self::LEGACY_SETTINGS], array_keys($fields))),
            );
        }

        return null;
    }

    /**
     * @return array{key: string, algorithm: string, digits: int, period: int}|null
     */
    private function fromOtp(#[\SensitiveParameter] string $value): ?array
    {
        $value = trim($value);

        if (preg_match('#^otpauth://#i', $value) === 1) {
            $parts = parse_url($value);
            if (! is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== 'totp') {
                return null;
            }
            parse_str((string) ($parts['query'] ?? ''), $query);
            $query = array_change_key_case($query);
            if (isset($query['encoder'])) {
                return null;
            }

            return $this->settings(
                $this->base32($query['secret'] ?? null),
                $query['algorithm'] ?? 'SHA1',
                $query['digits'] ?? '6',
                $query['period'] ?? '30',
            );
        }

        parse_str($value, $query);
        $query = array_change_key_case($query);
        if (isset($query['encoder']) || (isset($query['type']) && (! is_string($query['type']) || strtolower($query['type']) !== 'totp'))) {
            return null;
        }

        return $this->settings(
            $this->base32($query['key'] ?? null),
            $query['otphashmode'] ?? 'SHA1',
            $query['size'] ?? '6',
            $query['step'] ?? '30',
        );
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{key: string, algorithm: string, digits: int, period: int}|null
     */
    private function fromTimeOtp(#[\SensitiveParameter] array $fields): ?array
    {
        $key = null;
        if (isset($fields['TimeOtp-Secret'])) {
            $key = $fields['TimeOtp-Secret'] === '' ? null : $fields['TimeOtp-Secret'];
        } elseif (isset($fields['TimeOtp-Secret-Hex'])) {
            $hex = preg_replace('/\s+/', '', $fields['TimeOtp-Secret-Hex']) ?? '';
            $key = $hex !== '' && strlen($hex) % 2 === 0 && ctype_xdigit($hex) ? (string) hex2bin($hex) : null;
        } elseif (isset($fields['TimeOtp-Secret-Base32'])) {
            $key = $this->base32($fields['TimeOtp-Secret-Base32']);
        } elseif (isset($fields['TimeOtp-Secret-Base64'])) {
            $decoded = base64_decode(trim($fields['TimeOtp-Secret-Base64']), true);
            $key = is_string($decoded) && $decoded !== '' ? $decoded : null;
        }

        return $this->settings(
            $key,
            $fields['TimeOtp-Algorithm'] ?? 'HMAC-SHA-1',
            $fields['TimeOtp-Length'] ?? '6',
            $fields['TimeOtp-Period'] ?? '30',
        );
    }

    /**
     * @return array{key: string, algorithm: string, digits: int, period: int}|null
     */
    private function fromLegacy(#[\SensitiveParameter] string $seed, ?string $settings): ?array
    {
        $parts = explode(';', trim($settings ?? '30;6'));

        return $this->settings($this->base32($seed), 'SHA1', $parts[1] ?? '6', $parts[0]);
    }

    /**
     * @return array{key: string, algorithm: string, digits: int, period: int}|null
     */
    private function settings(#[\SensitiveParameter] ?string $key, mixed $algorithm, mixed $digits, mixed $period): ?array
    {
        $digits = is_string($digits) ? trim($digits) : '';
        $period = is_string($period) ? trim($period) : '';
        if ($key === null || ! ctype_digit($digits) || ! ctype_digit($period) || ! is_string($algorithm)) {
            return null;
        }

        return ['key' => $key, 'algorithm' => strtoupper(trim($algorithm)), 'digits' => (int) $digits, 'period' => (int) $period];
    }

    /**
     * @param  array{key: string, algorithm: string, digits: int, period: int}|null  $settings
     * @param  list<string>  $consumed
     * @return array{secret: string, consumed: list<string>}|null
     */
    private function accept(#[\SensitiveParameter] ?array $settings, array $consumed): ?array
    {
        if ($settings === null
            || ! in_array($settings['algorithm'], self::SUPPORTED_ALGORITHMS, true)
            || $settings['digits'] !== 6
            || $settings['period'] !== 30) {
            return null;
        }

        $secret = $this->encodeBase32($settings['key']);
        if (! $this->generator->isValidSecret($secret)) {
            return null;
        }

        return ['secret' => $secret, 'consumed' => $consumed];
    }

    private function base32(#[\SensitiveParameter] mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return $this->generator->decodeBase32($value);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Unpadded RFC 4648 Base32.
     */
    private function encodeBase32(#[\SensitiveParameter] string $bytes): string
    {
        $encoded = '';
        $buffer = 0;
        $bufferedBits = 0;
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bufferedBits += 8;
            while ($bufferedBits >= 5) {
                $bufferedBits -= 5;
                $encoded .= self::BASE32_ALPHABET[($buffer >> $bufferedBits) & 31];
            }
            $buffer &= (1 << $bufferedBits) - 1;
        }
        if ($bufferedBits > 0) {
            $encoded .= self::BASE32_ALPHABET[($buffer << (5 - $bufferedBits)) & 31];
        }

        return $encoded;
    }
}
