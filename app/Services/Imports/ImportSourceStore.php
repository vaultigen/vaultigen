<?php

namespace App\Services\Imports;

use App\Contracts\KekProvider;
use App\Models\ImportRun;
use App\Services\Crypto\AssociatedData;
use App\Services\Crypto\EnvelopeCipher;
use App\Services\EntryAttachments;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encrypted staging for an import source.
 *
 * The parsed intermediate representation contains plaintext account fields, so
 * it is only ever stored inside a `import-source` envelope under a random
 * per-run import key. That import key is itself wrapped under the installation
 * KEK, because one run may create several vaults and therefore has no single
 * vault key. The staged object lives on the private attachments disk.
 */
final class ImportSourceStore
{
    public const DISK = EntryAttachments::DISK;

    private const PREFIX = 'imports/';

    public function __construct(
        private readonly KekProvider $kek,
        private readonly EnvelopeCipher $cipher,
    ) {}

    /**
     * Seal a plaintext intermediate representation and return the run columns
     * that describe where and how it was stored.
     *
     * @return array{random_storage_path: string, envelope_format_version: int, import_key_algorithm: string, import_key_nonce: string, import_key_key_id: string, wrapped_import_key: string}
     */
    public function seal(string $runId, string $plaintext): array
    {
        $importKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $kekKeyId = $this->kek->activeKeyId();
        $wrapped = $this->cipher->seal(
            EnvelopeCipher::PURPOSE_IMPORT_KEY_WRAP,
            $kekKeyId,
            $this->kek->key($kekKeyId),
            $importKey,
            $this->keyWrapAssociatedData($runId, $kekKeyId),
        );
        $sealed = $this->cipher->seal(
            EnvelopeCipher::PURPOSE_IMPORT_SOURCE,
            $kekKeyId,
            $importKey,
            $plaintext,
            $this->sourceAssociatedData($runId),
        );

        $path = self::PREFIX.(string) Str::uuid().'.bin';
        Storage::disk(self::DISK)->put($path, $sealed->envelope);

        return [
            'random_storage_path' => $path,
            'envelope_format_version' => EnvelopeCipher::FORMAT_VERSION,
            'import_key_algorithm' => EnvelopeCipher::ALGORITHM,
            'import_key_nonce' => $wrapped->nonce,
            'import_key_key_id' => $kekKeyId,
            'wrapped_import_key' => $wrapped->envelope,
        ];
    }

    /**
     * Unseal a staged intermediate representation.
     */
    public function open(ImportRun $run): string
    {
        if ($run->random_storage_path === null || $run->wrapped_import_key === null || $run->import_key_key_id === null) {
            throw new RuntimeException('The import staging object is missing.');
        }

        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($run->random_storage_path)) {
            throw new RuntimeException('The import staging object is missing.');
        }

        $importKey = $this->cipher->open(
            EnvelopeCipher::PURPOSE_IMPORT_KEY_WRAP,
            $run->import_key_key_id,
            $this->kek->key($run->import_key_key_id),
            $run->wrapped_import_key,
            $this->keyWrapAssociatedData($run->id, $run->import_key_key_id),
        );

        return $this->cipher->open(
            EnvelopeCipher::PURPOSE_IMPORT_SOURCE,
            $run->import_key_key_id,
            $importKey,
            (string) $disk->get($run->random_storage_path),
            $this->sourceAssociatedData($run->id),
        );
    }

    /**
     * Remove the staged object. Idempotent: a run with no object is a no-op.
     */
    public function purge(ImportRun $run): void
    {
        if ($run->random_storage_path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($run->random_storage_path);
        $run->forceFill(['random_storage_path' => null])->save();
    }

    private function keyWrapAssociatedData(string $runId, string $kekKeyId): string
    {
        return AssociatedData::encode([
            'installation_id' => (string) config('vaultigen.installation_id'),
            'purpose' => EnvelopeCipher::PURPOSE_IMPORT_KEY_WRAP,
            'import_run_id' => $runId,
            'kek_key_id' => $kekKeyId,
        ]);
    }

    private function sourceAssociatedData(string $runId): string
    {
        return AssociatedData::encode([
            'installation_id' => (string) config('vaultigen.installation_id'),
            'purpose' => EnvelopeCipher::PURPOSE_IMPORT_SOURCE,
            'import_run_id' => $runId,
        ]);
    }
}
