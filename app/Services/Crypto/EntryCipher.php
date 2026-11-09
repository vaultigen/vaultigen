<?php

namespace App\Services\Crypto;

/**
 * Seals and opens the encrypted entry documents and their revision snapshots.
 *
 * Both use the shared entry-payload purpose but distinct record types and the
 * immutable object ID in the associated data, so a payload and a snapshot can
 * never be swapped for one another or for another entry.
 */
final class EntryCipher
{
    public const RECORD_ENTRY = 'entry';

    public const RECORD_REVISION = 'entry_revision';

    public function __construct(
        private readonly VaultKeyManager $keys,
        private readonly EnvelopeCipher $cipher,
    ) {}

    public function sealPayload(string $vaultId, string $entryId, string $vaultKeyVersionId, string $plaintext): SealedEnvelope
    {
        return $this->seal(self::RECORD_ENTRY, $vaultId, $entryId, $vaultKeyVersionId, $plaintext);
    }

    public function openPayload(string $vaultId, string $entryId, string $vaultKeyVersionId, string $envelope): string
    {
        return $this->open(self::RECORD_ENTRY, $vaultId, $entryId, $vaultKeyVersionId, $envelope);
    }

    public function sealSnapshot(string $vaultId, string $revisionId, string $vaultKeyVersionId, string $plaintext): SealedEnvelope
    {
        return $this->seal(self::RECORD_REVISION, $vaultId, $revisionId, $vaultKeyVersionId, $plaintext);
    }

    public function openSnapshot(string $vaultId, string $revisionId, string $vaultKeyVersionId, string $envelope): string
    {
        return $this->open(self::RECORD_REVISION, $vaultId, $revisionId, $vaultKeyVersionId, $envelope);
    }

    private function seal(string $recordType, string $vaultId, string $recordId, string $vaultKeyVersionId, string $plaintext): SealedEnvelope
    {
        return $this->cipher->seal(
            EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD,
            $vaultKeyVersionId,
            $this->keys->unwrap($vaultId, $vaultKeyVersionId),
            $plaintext,
            $this->associatedData($recordType, $vaultId, $recordId, $vaultKeyVersionId),
        );
    }

    private function open(string $recordType, string $vaultId, string $recordId, string $vaultKeyVersionId, string $envelope): string
    {
        return $this->cipher->open(
            EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD,
            $vaultKeyVersionId,
            $this->keys->unwrap($vaultId, $vaultKeyVersionId),
            $envelope,
            $this->associatedData($recordType, $vaultId, $recordId, $vaultKeyVersionId),
        );
    }

    private function associatedData(string $recordType, string $vaultId, string $recordId, string $vaultKeyVersionId): string
    {
        return AssociatedData::encode([
            'installation_id' => (string) config('vaultigen.installation_id'),
            'purpose' => EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'vault_id' => $vaultId,
            'vault_key_version_id' => $vaultKeyVersionId,
        ]);
    }
}
