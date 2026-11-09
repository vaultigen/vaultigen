<?php

namespace App\Services\Crypto;

/**
 * Encrypts attachment metadata and file keys, and streams the encrypted bytes.
 *
 * Filename and detected MIME are sealed in an attachment-metadata envelope under
 * the vault's key version. The per-file stream key is a random 32-byte value
 * wrapped under the same vault key. The ciphertext itself is bound only to the
 * immutable attachment ID, so a cross-vault move re-wraps the file key rather
 * than re-encrypting every byte.
 */
final class AttachmentCipher
{
    public const PURPOSE_FILE_STREAM = 'file-stream';

    public function __construct(
        private readonly VaultKeyManager $keys,
        private readonly EnvelopeCipher $cipher,
        private readonly FileStreamCipher $streams,
    ) {}

    public function sealMetadata(string $vaultId, string $attachmentId, string $vaultKeyVersionId, string $plaintext): SealedEnvelope
    {
        return $this->cipher->seal(
            EnvelopeCipher::PURPOSE_ATTACHMENT_METADATA,
            $vaultKeyVersionId,
            $this->keys->unwrap($vaultId, $vaultKeyVersionId),
            $plaintext,
            $this->metadataAssociatedData($vaultId, $attachmentId, $vaultKeyVersionId),
        );
    }

    public function openMetadata(string $vaultId, string $attachmentId, string $vaultKeyVersionId, string $envelope): string
    {
        return $this->cipher->open(
            EnvelopeCipher::PURPOSE_ATTACHMENT_METADATA,
            $vaultKeyVersionId,
            $this->keys->unwrap($vaultId, $vaultKeyVersionId),
            $envelope,
            $this->metadataAssociatedData($vaultId, $attachmentId, $vaultKeyVersionId),
        );
    }

    public function newFileKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    public function wrapFileKey(string $vaultId, string $attachmentId, string $vaultKeyVersionId, #[\SensitiveParameter] string $fileKey): SealedEnvelope
    {
        return $this->cipher->seal(
            EnvelopeCipher::PURPOSE_FILE_KEY_WRAP,
            $vaultKeyVersionId,
            $this->keys->unwrap($vaultId, $vaultKeyVersionId),
            $fileKey,
            $this->fileKeyAssociatedData($vaultId, $attachmentId, $vaultKeyVersionId),
        );
    }

    public function unwrapFileKey(string $vaultId, string $attachmentId, string $vaultKeyVersionId, string $envelope): string
    {
        return $this->cipher->open(
            EnvelopeCipher::PURPOSE_FILE_KEY_WRAP,
            $vaultKeyVersionId,
            $this->keys->unwrap($vaultId, $vaultKeyVersionId),
            $envelope,
            $this->fileKeyAssociatedData($vaultId, $attachmentId, $vaultKeyVersionId),
        );
    }

    /**
     * Encrypt a plaintext attachment into the ciphertext destination stream.
     *
     * @param  resource  $source
     * @param  resource  $destination
     * @return array{checksum: string, ciphertext_bytes: int, plaintext_bytes: int}
     */
    public function encryptStream($source, $destination, #[\SensitiveParameter] string $fileKey, string $attachmentId): array
    {
        return $this->streams->encryptStream($source, $destination, $fileKey, $this->streamAssociatedData($attachmentId));
    }

    /**
     * Decrypt a ciphertext attachment into the plaintext destination stream.
     *
     * @param  resource  $source
     * @param  resource  $destination
     */
    public function decryptStream($source, $destination, #[\SensitiveParameter] string $fileKey, string $attachmentId): int
    {
        return $this->streams->decryptStream($source, $destination, $fileKey, $this->streamAssociatedData($attachmentId));
    }

    /**
     * @param  resource  $source
     */
    public function verifyChecksum($source, string $expected): bool
    {
        return $this->streams->verifyChecksum($source, $expected);
    }

    private function metadataAssociatedData(string $vaultId, string $attachmentId, string $vaultKeyVersionId): string
    {
        return AssociatedData::encode([
            'installation_id' => (string) config('vaultigen.installation_id'),
            'purpose' => EnvelopeCipher::PURPOSE_ATTACHMENT_METADATA,
            'vault_id' => $vaultId,
            'attachment_id' => $attachmentId,
            'vault_key_version_id' => $vaultKeyVersionId,
        ]);
    }

    private function fileKeyAssociatedData(string $vaultId, string $attachmentId, string $vaultKeyVersionId): string
    {
        return AssociatedData::encode([
            'installation_id' => (string) config('vaultigen.installation_id'),
            'purpose' => EnvelopeCipher::PURPOSE_FILE_KEY_WRAP,
            'vault_id' => $vaultId,
            'attachment_id' => $attachmentId,
            'vault_key_version_id' => $vaultKeyVersionId,
        ]);
    }

    /**
     * The ciphertext is deliberately vault-independent so it can be moved
     * between vaults by re-wrapping only the file key and metadata.
     */
    private function streamAssociatedData(string $attachmentId): string
    {
        return AssociatedData::encode([
            'installation_id' => (string) config('vaultigen.installation_id'),
            'purpose' => self::PURPOSE_FILE_STREAM,
            'attachment_id' => $attachmentId,
            'stream_format_version' => FileStreamCipher::FORMAT_VERSION,
        ]);
    }
}
