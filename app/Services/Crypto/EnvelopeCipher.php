<?php

namespace App\Services\Crypto;

use InvalidArgumentException;
use RuntimeException;
use SodiumException;

/**
 * Versioned, base64url-encoded XChaCha20-Poly1305 envelopes.
 *
 * Every envelope records its format version, algorithm, purpose, and
 * encryption-key ID. Plaintext is authenticated with canonical associated
 * data so a moved or reused ciphertext fails closed.
 */
final class EnvelopeCipher
{
    public const FORMAT_VERSION = 1;

    public const ALGORITHM = 'xchacha20-poly1305';

    public const PURPOSE_VAULT_KEY_WRAP = 'vault-key-wrap';

    public const PURPOSE_ENTRY_PAYLOAD = 'entry-payload';

    public const PURPOSE_FILE_KEY_WRAP = 'file-key-wrap';

    public const PURPOSE_IMPORT_SOURCE = 'import-source';

    public const PURPOSE_ATTACHMENT_METADATA = 'attachment-metadata';

    public const PURPOSE_IMPORT_KEY_WRAP = 'import-key-wrap';

    public const PURPOSE_IMPORT_CREDENTIAL = 'import-credential';

    private const ALGORITHM_CODE = 1;

    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private const TAG_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    public function seal(
        string $purpose,
        string $keyId,
        #[\SensitiveParameter] string $key,
        string $plaintext,
        string $associatedData,
    ): SealedEnvelope {
        $this->assertKey($key);
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $key);
        $header = chr(self::FORMAT_VERSION).chr(self::ALGORITHM_CODE).chr($this->purposeCode($purpose)).chr(0)
            .pack('n', strlen($keyId)).$keyId;

        return new SealedEnvelope(
            sodium_bin2base64($header.$nonce.$ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        );
    }

    public function open(
        string $purpose,
        string $keyId,
        #[\SensitiveParameter] string $key,
        string $envelope,
        string $associatedData,
    ): string {
        $this->assertKey($key);
        $binary = $this->decode($envelope);

        if (ord($binary[0]) !== self::FORMAT_VERSION) {
            throw new RuntimeException('The envelope format version is not supported.');
        }
        if (ord($binary[1]) !== self::ALGORITHM_CODE) {
            throw new RuntimeException('The envelope algorithm is not supported.');
        }
        if ($this->purposeFromCode(ord($binary[2])) !== $purpose) {
            throw new RuntimeException('The envelope purpose does not match the requested operation.');
        }
        $lengthBytes = unpack('nlength', substr($binary, 4, 2));
        $keyIdLength = $lengthBytes === false ? 0 : $lengthBytes['length'];
        $offset = 6 + $keyIdLength;
        if (strlen($binary) < $offset + self::NONCE_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('The envelope is malformed.');
        }
        if (! hash_equals($keyId, substr($binary, 6, $keyIdLength))) {
            throw new RuntimeException('The envelope encryption-key ID does not match the available key.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($binary, $offset + self::NONCE_BYTES),
            $associatedData,
            substr($binary, $offset, self::NONCE_BYTES),
            $key,
        );
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt the envelope.');
        }

        return $plaintext;
    }

    private function decode(string $envelope): string
    {
        if ($envelope === '') {
            throw new RuntimeException('The envelope is malformed.');
        }

        try {
            $binary = sodium_base642bin($envelope, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException) {
            throw new RuntimeException('The envelope is malformed.');
        }

        if (strlen($binary) < 6 + self::NONCE_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('The envelope is malformed.');
        }

        return $binary;
    }

    private function assertKey(#[\SensitiveParameter] string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('The envelope encryption key must be 32 bytes.');
        }
    }

    /** @return int<0, 255> */
    private function purposeCode(string $purpose): int
    {
        return match ($purpose) {
            self::PURPOSE_VAULT_KEY_WRAP => 1,
            self::PURPOSE_ENTRY_PAYLOAD => 2,
            self::PURPOSE_FILE_KEY_WRAP => 3,
            self::PURPOSE_IMPORT_SOURCE => 4,
            self::PURPOSE_ATTACHMENT_METADATA => 5,
            self::PURPOSE_IMPORT_KEY_WRAP => 6,
            self::PURPOSE_IMPORT_CREDENTIAL => 7,
            default => throw new InvalidArgumentException("Unknown envelope purpose [{$purpose}]."),
        };
    }

    private function purposeFromCode(int $code): string
    {
        return match ($code) {
            1 => self::PURPOSE_VAULT_KEY_WRAP,
            2 => self::PURPOSE_ENTRY_PAYLOAD,
            3 => self::PURPOSE_FILE_KEY_WRAP,
            4 => self::PURPOSE_IMPORT_SOURCE,
            5 => self::PURPOSE_ATTACHMENT_METADATA,
            6 => self::PURPOSE_IMPORT_KEY_WRAP,
            7 => self::PURPOSE_IMPORT_CREDENTIAL,
            default => throw new RuntimeException('The envelope purpose is not supported.'),
        };
    }
}
