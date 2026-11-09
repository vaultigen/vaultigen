<?php

namespace App\Services\Crypto;

use App\Contracts\KekProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates, unwraps, rotates, and rewraps vault data-encryption keys.
 *
 * Each vault key version is one random 32-byte key wrapped under an installation
 * KEK. Wrapping associated data binds the installation ID, the vault, the
 * immutable key-version ID, the version number, and the KEK key ID. A version is
 * retired only after a rotation has re-encrypted every live reference.
 */
final class VaultKeyManager
{
    public function __construct(
        private readonly KekProvider $kek,
        private readonly EnvelopeCipher $cipher,
    ) {}

    /**
     * Create the first key version for a vault and make it active.
     *
     * Must be called inside the transaction that creates the vault.
     */
    public function createFirstVersion(string $vaultId, string $createdByUserId): string
    {
        if (DB::table('vault_active_keys')->where('vault_id', $vaultId)->exists()) {
            throw new RuntimeException('The vault already has an active key version.');
        }

        $versionId = $this->createVersion($vaultId, 1, $createdByUserId);
        DB::table('vault_active_keys')->insert([
            'vault_id' => $vaultId,
            'vault_key_version_id' => $versionId,
        ]);

        return $versionId;
    }

    /**
     * Create the next key version for a vault and make it active for new writes.
     *
     * Existing entries keep the old version until a rotation re-encrypts them, so
     * a concurrent edit is never lost. The switch is a single atomic update.
     */
    public function rotateActiveVersion(string $vaultId, string $createdByUserId): string
    {
        $active = DB::table('vault_active_keys')->where('vault_id', $vaultId)->first();
        if ($active === null) {
            throw new RuntimeException('The vault has no active key version.');
        }

        $version = (int) DB::table('vault_key_versions')->where('id', $active->vault_key_version_id)->value('version');
        $versionId = $this->createVersion($vaultId, $version + 1, $createdByUserId);

        DB::table('vault_active_keys')->where('vault_id', $vaultId)->update([
            'vault_key_version_id' => $versionId,
        ]);

        return $versionId;
    }

    /**
     * The active key version new entry payloads and file keys are written under.
     */
    public function activeVersionId(string $vaultId): string
    {
        $versionId = DB::table('vault_active_keys')->where('vault_id', $vaultId)->value('vault_key_version_id');
        if ($versionId === null) {
            throw new RuntimeException('The vault has no active key version.');
        }

        return (string) $versionId;
    }

    /**
     * The version number of a vault key version, used in associated data.
     */
    public function versionNumber(string $vaultId, string $vaultKeyVersionId): int
    {
        $version = DB::table('vault_key_versions')
            ->where('id', $vaultKeyVersionId)
            ->where('vault_id', $vaultId)
            ->value('version');

        return $version === null ? 0 : (int) $version;
    }

    /**
     * Unwrap the data-encryption key for a vault key version.
     */
    public function unwrap(string $vaultId, string $vaultKeyVersionId): string
    {
        $version = DB::table('vault_key_versions')
            ->where('id', $vaultKeyVersionId)
            ->where('vault_id', $vaultId)
            ->first();
        $wrapping = DB::table('vault_key_wrappings')
            ->where('vault_key_version_id', $vaultKeyVersionId)
            ->where('recipient_type', 'server_kek')
            ->whereNull('retired_at')
            ->first();

        if ($version === null || $wrapping === null) {
            throw new RuntimeException('The vault key material is unavailable.');
        }

        $kekKeyId = (string) $wrapping->recipient_key_id;

        return $this->cipher->open(
            EnvelopeCipher::PURPOSE_VAULT_KEY_WRAP,
            $kekKeyId,
            $this->kek->key($kekKeyId),
            (string) $wrapping->wrapped_key,
            $this->associatedData($vaultId, $vaultKeyVersionId, (int) $version->version, $kekKeyId),
        );
    }

    /**
     * Rewrap one vault key version from a source KEK to a target KEK.
     *
     * The vault key itself never changes, so no entry data is re-encrypted. The
     * existing wrapping row is updated in place, which keeps the recipient
     * uniqueness intact.
     */
    public function rewrapTo(string $vaultId, string $vaultKeyVersionId, string $sourceKekKeyId, string $targetKekKeyId): void
    {
        $version = DB::table('vault_key_versions')
            ->where('id', $vaultKeyVersionId)
            ->where('vault_id', $vaultId)
            ->first();
        $wrapping = DB::table('vault_key_wrappings')
            ->where('vault_key_version_id', $vaultKeyVersionId)
            ->where('recipient_type', 'server_kek')
            ->where('recipient_key_id', $sourceKekKeyId)
            ->whereNull('retired_at')
            ->first();

        if ($version === null || $wrapping === null) {
            throw new RuntimeException('The vault key material is unavailable.');
        }

        $key = $this->cipher->open(
            EnvelopeCipher::PURPOSE_VAULT_KEY_WRAP,
            $sourceKekKeyId,
            $this->kek->key($sourceKekKeyId),
            (string) $wrapping->wrapped_key,
            $this->associatedData($vaultId, $vaultKeyVersionId, (int) $version->version, $sourceKekKeyId),
        );
        $sealed = $this->cipher->seal(
            EnvelopeCipher::PURPOSE_VAULT_KEY_WRAP,
            $targetKekKeyId,
            $this->kek->key($targetKekKeyId),
            $key,
            $this->associatedData($vaultId, $vaultKeyVersionId, (int) $version->version, $targetKekKeyId),
        );

        DB::table('vault_key_wrappings')->where('id', $wrapping->id)->update([
            'recipient_key_id' => $targetKekKeyId,
            'nonce' => $sealed->nonce,
            'wrapped_key' => $sealed->envelope,
        ]);
    }

    /**
     * Mark a version retired. Callers must first prove there are no live
     * references and that backups permit retirement.
     */
    public function retireVersion(string $vaultId, string $vaultKeyVersionId): void
    {
        DB::table('vault_key_versions')
            ->where('id', $vaultKeyVersionId)
            ->where('vault_id', $vaultId)
            ->update(['retired_at' => now()]);
    }

    private function createVersion(string $vaultId, int $version, string $createdByUserId): string
    {
        $versionId = (string) Str::uuid();
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $kekKeyId = $this->kek->activeKeyId();
        $sealed = $this->cipher->seal(
            EnvelopeCipher::PURPOSE_VAULT_KEY_WRAP,
            $kekKeyId,
            $this->kek->key($kekKeyId),
            $key,
            $this->associatedData($vaultId, $versionId, $version, $kekKeyId),
        );

        DB::table('vault_key_versions')->insert([
            'id' => $versionId,
            'vault_id' => $vaultId,
            'version' => $version,
            'created_by_user_id' => $createdByUserId,
            'created_at' => now(),
        ]);
        DB::table('vault_key_wrappings')->insert([
            'id' => (string) Str::uuid(),
            'vault_key_version_id' => $versionId,
            'recipient_type' => 'server_kek',
            'recipient_key_id' => $kekKeyId,
            'algorithm' => EnvelopeCipher::ALGORITHM,
            'nonce' => $sealed->nonce,
            'wrapped_key' => $sealed->envelope,
            'created_at' => now(),
        ]);

        return $versionId;
    }

    private function associatedData(string $vaultId, string $vaultKeyVersionId, int $version, string $kekKeyId): string
    {
        return AssociatedData::encode([
            'installation_id' => (string) config('vaultigen.installation_id'),
            'purpose' => EnvelopeCipher::PURPOSE_VAULT_KEY_WRAP,
            'vault_id' => $vaultId,
            'vault_key_version_id' => $vaultKeyVersionId,
            'version' => $version,
            'kek_key_id' => $kekKeyId,
        ]);
    }
}
