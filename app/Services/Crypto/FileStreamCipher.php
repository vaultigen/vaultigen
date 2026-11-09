<?php

namespace App\Services\Crypto;

use InvalidArgumentException;
use RuntimeException;

/**
 * Authenticated streaming encryption for attachment bytes.
 *
 * Uses libsodium's secretstream XChaCha20-Poly1305 construction with a per-file
 * key. Every chunk carries associated data that binds the installation, the
 * immutable attachment ID, the stream format version, and the chunk index, so a
 * reordered chunk, a truncated stream, or a stream replayed under another
 * attachment fails closed. The final chunk is marked with the FINAL tag, so a
 * missing end marker is rejected as truncation.
 *
 * Plaintext is never written to a file: the caller streams directly between
 * PHP's upload temporary file and the encrypted destination.
 */
final class FileStreamCipher
{
    public const FORMAT_VERSION = 1;

    public const CHUNK_BYTES = 65536;

    /**
     * Encrypt a plaintext source stream into a ciphertext destination stream.
     *
     * @param  resource  $source
     * @param  resource  $destination
     * @return array{checksum: string, ciphertext_bytes: int, plaintext_bytes: int}
     */
    public function encryptStream($source, $destination, #[\SensitiveParameter] string $fileKey, string $associatedData): array
    {
        $this->assertKey($fileKey);

        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);
        $this->writeAll($destination, $header);

        $checksum = hash_init('sha256');
        hash_update($checksum, $header);
        $ciphertextBytes = strlen($header);
        $plaintextBytes = 0;
        $index = 0;
        $pending = null;

        while (true) {
            $chunk = fread($source, self::CHUNK_BYTES);
            if ($chunk === false) {
                throw new RuntimeException('The source file could not be read.');
            }
            if ($chunk === '') {
                break;
            }

            if ($pending !== null) {
                $sealed = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $pending,
                    $this->chunkAssociatedData($associatedData, $index),
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                $this->writeAll($destination, $sealed);
                hash_update($checksum, $sealed);
                $ciphertextBytes += strlen($sealed);
                $index++;
            }

            $pending = $chunk;
            $plaintextBytes += strlen($chunk);
        }

        $final = sodium_crypto_secretstream_xchacha20poly1305_push(
            $state,
            $pending ?? '',
            $this->chunkAssociatedData($associatedData, $index),
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
        );
        $this->writeAll($destination, $final);
        hash_update($checksum, $final);
        $ciphertextBytes += strlen($final);

        return [
            'checksum' => hash_final($checksum),
            'ciphertext_bytes' => $ciphertextBytes,
            'plaintext_bytes' => $plaintextBytes,
        ];
    }

    /**
     * Decrypt a ciphertext source stream into a plaintext destination stream.
     *
     * @param  resource  $source
     * @param  resource  $destination
     * @return int The number of plaintext bytes written.
     */
    public function decryptStream($source, $destination, #[\SensitiveParameter] string $fileKey, string $associatedData): int
    {
        $this->assertKey($fileKey);

        $header = $this->readExact($source, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $fileKey);

        $frameBytes = self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $plaintextBytes = 0;
        $index = 0;
        $finalReached = false;

        while (($chunk = $this->readChunk($source, $frameBytes)) !== null) {
            if ($finalReached) {
                throw new RuntimeException('The encrypted file has trailing data.');
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,
                $chunk,
                $this->chunkAssociatedData($associatedData, $index),
            );
            if ($result === false) {
                throw new RuntimeException('The encrypted file failed authentication.');
            }

            [$plaintext, $tag] = $result;
            $index++;

            if ($plaintext !== '') {
                $this->writeAll($destination, $plaintext);
                $plaintextBytes += strlen($plaintext);
            }

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $finalReached = true;
            }
        }

        if (! $finalReached) {
            throw new RuntimeException('The encrypted file is truncated.');
        }

        return $plaintextBytes;
    }

    /**
     * Verify a ciphertext file against its recorded checksum without decrypting.
     *
     * @param  resource  $source
     */
    public function verifyChecksum($source, string $expected): bool
    {
        $checksum = hash_init('sha256');
        while (true) {
            $chunk = fread($source, self::CHUNK_BYTES);
            if ($chunk === false) {
                throw new RuntimeException('The encrypted file could not be read.');
            }
            if ($chunk === '') {
                break;
            }
            hash_update($checksum, $chunk);
        }

        return hash_equals($expected, hash_final($checksum));
    }

    /**
     * The per-file bytes are encrypted with a key independent of the vault: the
     * stream binds the attachment identity, not the vault, so a cross-vault
     * move re-wraps the file key instead of re-encrypting the ciphertext.
     */
    private function chunkAssociatedData(string $associatedData, int $index): string
    {
        return $associatedData.pack('N', $index);
    }

    /**
     * @param  resource  $stream
     */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The encrypted file could not be written.');
            }
            $offset += $written;
        }
    }

    /**
     * @param  resource  $stream
     */
    private function readExact($stream, int $length): string
    {
        $data = $this->readChunk($stream, $length);
        if ($data === null || strlen($data) !== $length) {
            throw new RuntimeException('The encrypted file header is malformed.');
        }

        return $data;
    }

    /**
     * @param  resource  $stream
     */
    private function readChunk($stream, int $length): ?string
    {
        $data = '';

        while (strlen($data) < $length) {
            $part = fread($stream, max(1, $length - strlen($data)));
            if ($part === false) {
                throw new RuntimeException('The encrypted file could not be read.');
            }
            if ($part === '') {
                break;
            }
            $data .= $part;
        }

        return $data === '' ? null : $data;
    }

    private function assertKey(#[\SensitiveParameter] string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new InvalidArgumentException('The file stream key must be 32 bytes.');
        }
    }
}
