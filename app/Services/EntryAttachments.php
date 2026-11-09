<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\FileAttachment;
use App\Models\User;
use App\Models\Vault;
use App\Services\Crypto\AttachmentCipher;
use App\Services\Crypto\EnvelopeCipher;
use App\Services\Crypto\FileStreamCipher;
use App\Services\Crypto\VaultKeyManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Authorized attachment reads and writes for an entry.
 *
 * Filename and MIME never leave their encrypted envelope, ciphertext lives on a
 * private disk under a random path, and every byte is encrypted as it streams
 * from PHP's upload temporary file (or, for an import, from a memory stream). Adding or removing a file requires
 * `entry.update` (Editor); downloading one requires `secret.reveal` (Viewer)
 * and is audited before any bytes are returned.
 */
final class EntryAttachments
{
    public const DISK = 'attachments';

    /**
     * How many leading bytes MIME detection reads.
     */
    private const MIME_SNIFF_BYTES = 1024 * 1024;

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly VaultAccess $access,
        private readonly VaultKeyManager $keys,
        private readonly AttachmentCipher $cipher,
        private readonly AttachmentQuota $quota,
        private readonly InstallationSettings $settings,
        private readonly SecretReveals $reveals,
    ) {}

    public function upload(User $actor, string $entryId, UploadedFile $file): string
    {
        $entry = $this->writableEntry($actor, $entryId);
        $realPath = $this->assertValidUpload($actor, $file);
        $source = fopen($realPath, 'rb') ?: throw new RuntimeException('The uploaded file could not be read.');

        try {
            return $this->write($actor, $entry, $source, (int) $file->getSize(), $file->getClientOriginalName());
        } finally {
            fclose($source);
        }
    }

    /**
     * Store an attachment from a readable, seekable stream of exactly `$size`
     * bytes. It enforces the same authorization, quota, MIME detection,
     * encryption, audit, and cleanup as {@see upload()}, so callers that hold
     * the plaintext in memory (such as an import) never write it to disk.
     *
     * @param  resource  $stream
     */
    public function store(User $actor, string $entryId, $stream, int $size, string $filename): string
    {
        $entry = $this->writableEntry($actor, $entryId);

        $stat = fstat($stream);
        if ($size < 1) {
            $this->reject($actor, (string) __('attachments.validation.empty'));
        }
        if (! is_array($stat) || $stat['size'] !== $size || ! (bool) stream_get_meta_data($stream)['seekable']) {
            $this->reject($actor, (string) __('attachments.validation.unreadable'));
        }

        return $this->write($actor, $entry, $stream, $size, $filename);
    }

    /**
     * @param  resource  $source
     */
    private function write(User $actor, Entry $entry, $source, int $size, string $originalName): string
    {
        try {
            $reservationId = $this->quota->reserve($actor, (string) $entry->vault_id, $size);
        } catch (ValidationException $exception) {
            $this->audit->record('attachment.validation_failed', $actor->id, 'denied');

            throw $exception;
        }

        $attachmentId = (string) Str::uuid();
        $path = $this->randomPath();
        $tempPath = $path.'.part';
        $versionId = $this->keys->activeVersionId((string) $entry->vault_id);
        $fileKey = $this->cipher->newFileKey();
        $destination = null;

        try {
            $mime = $this->detectMime($source);

            $disk = Storage::disk(self::DISK);
            $disk->makeDirectory(dirname($path));
            $destination = fopen($disk->path($tempPath), 'wb') ?: throw new RuntimeException('The attachment could not be written.');

            $sealed = $this->cipher->encryptStream($source, $destination, $fileKey, $attachmentId);
            fclose($destination);
            $destination = null;

            $disk->move($tempPath, $path);

            $filename = $this->sanitizeFilename($originalName);
            $metadata = json_encode(['filename' => $filename, 'mime' => $mime], JSON_THROW_ON_ERROR);
            $sealedMetadata = $this->cipher->sealMetadata((string) $entry->vault_id, $attachmentId, $versionId, $metadata);
            $wrapped = $this->cipher->wrapFileKey((string) $entry->vault_id, $attachmentId, $versionId, $fileKey);

            $this->audit->transaction(function () use ($actor, $entry, $attachmentId, $versionId, $sealedMetadata, $wrapped, $sealed, $path, $size, $reservationId): void {
                DB::table('file_attachments')->insert([
                    'id' => $attachmentId,
                    'vault_id' => $entry->vault_id,
                    'entry_id' => $entry->id,
                    'vault_key_version_id' => $versionId,
                    'encrypted_metadata' => $sealedMetadata->envelope,
                    'byte_size' => $size,
                    'random_storage_path' => $path,
                    'stream_format_version' => FileStreamCipher::FORMAT_VERSION,
                    'wrapped_file_key_algorithm' => EnvelopeCipher::ALGORITHM,
                    'wrapped_file_key_nonce' => $wrapped->nonce,
                    'wrapped_file_key' => $wrapped->envelope,
                    'ciphertext_checksum' => $sealed['checksum'],
                    'state' => FileAttachment::STATE_AVAILABLE,
                    'created_by_user_id' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->quota->commit($reservationId, $attachmentId);
                $this->audit->record('attachment.uploaded', $actor->id);
            });

            return $attachmentId;
        } catch (Throwable $exception) {
            $this->cleanupFailedUpload($destination, $path, $tempPath, $reservationId);
            if (! $exception instanceof ValidationException) {
                $this->audit->record('attachment.validation_failed', $actor->id, 'failed');
            }

            throw $exception;
        }
    }

    public function delete(User $actor, string $attachmentId): void
    {
        $attachment = $this->findLiveAttachment($attachmentId);
        $entry = $this->writableEntry($actor, (string) $attachment->entry_id);

        $this->audit->transaction(function () use ($actor, $attachment, $entry): void {
            DB::table('file_attachments')
                ->where('id', $attachment->id)
                ->where('vault_id', $entry->vault_id)
                ->update([
                    'state' => FileAttachment::STATE_DELETED,
                    'deleted_at' => now(),
                    'purge_after' => now()->addDays($this->settings->trashRetentionDays()),
                    'updated_at' => now(),
                ]);
            $this->audit->record('attachment.deleted', $actor->id);
        });
    }

    public function authorizeDownload(User $actor, string $attachmentId): FileAttachment
    {
        $attachment = $this->findLiveAttachment($attachmentId);
        $entry = Entry::query()->whereKey($attachment->entry_id)->first();
        abort_if($entry === null || $entry->deleted_at !== null, 404);
        abort_unless($this->access->allowsOnEntry($actor, $entry, 'secret.reveal'), 404);

        return $attachment;
    }

    /**
     * @return array{filename: string, mime: string}
     */
    public function metadata(FileAttachment $attachment): array
    {
        $decoded = json_decode(
            $this->cipher->openMetadata(
                (string) $attachment->vault_id,
                (string) $attachment->id,
                (string) $attachment->vault_key_version_id,
                (string) $attachment->encrypted_metadata,
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return [
            'filename' => is_string($decoded['filename'] ?? null) && $decoded['filename'] !== '' ? $decoded['filename'] : 'attachment.bin',
            'mime' => is_string($decoded['mime'] ?? null) && $decoded['mime'] !== '' ? $decoded['mime'] : 'application/octet-stream',
        ];
    }

    /**
     * Stream the decrypted bytes to a destination stream, verifying the stored
     * ciphertext checksum first so a corrupted object is never delivered.
     *
     * @param  resource  $destination
     */
    public function streamTo(FileAttachment $attachment, $destination): void
    {
        $disk = Storage::disk(self::DISK);
        $source = @fopen($disk->path((string) $attachment->random_storage_path), 'rb')
            ?: throw new RuntimeException('The attachment object is missing.');

        try {
            if (! $this->cipher->verifyChecksum($source, (string) $attachment->ciphertext_checksum)) {
                throw new RuntimeException('The attachment object failed its integrity check.');
            }
            rewind($source);
            $fileKey = $this->cipher->unwrapFileKey(
                (string) $attachment->vault_id,
                (string) $attachment->id,
                (string) $attachment->vault_key_version_id,
                (string) $attachment->wrapped_file_key,
            );
            $this->cipher->decryptStream($source, $destination, $fileKey, (string) $attachment->id);
        } finally {
            fclose($source);
        }
    }

    public function recordDownloadStart(User $actor, FileAttachment $attachment): void
    {
        $this->audit->transaction(function () use ($actor, $attachment): void {
            $this->audit->record('attachment.download_started', $actor->id);
            $this->reveals->record($actor, (string) $attachment->vault_id, (string) $attachment->entry_id);
        });
    }

    public function recordDownloadCompleted(User $actor, FileAttachment $attachment): void
    {
        $this->audit->record('attachment.download_completed', $actor->id);
    }

    public function recordDownloadFailed(User $actor, FileAttachment $attachment): void
    {
        $this->audit->record('attachment.download_completed', $actor->id, 'failed');
    }

    /**
     * @return Collection<int, FileAttachment>
     */
    public function forEntry(Entry $entry): Collection
    {
        return FileAttachment::query()
            ->where('entry_id', $entry->id)
            ->whereNull('deleted_at')
            ->with('createdBy')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The live attachment membership captured in an entry revision.
     *
     * @return list<string>
     */
    public function idsForEntry(string $entryId): array
    {
        return array_values(DB::table('file_attachments')
            ->where('entry_id', $entryId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * Reinstate the attachment membership recorded in a restored revision.
     * Attachments whose object was already purged are simply unavailable.
     *
     * @param  list<string>  $attachmentIds
     */
    public function restoreMembership(string $vaultId, string $entryId, array $attachmentIds): void
    {
        DB::table('file_attachments')
            ->where('entry_id', $entryId)
            ->where('vault_id', $vaultId)
            ->whereNull('deleted_at')
            ->whereNotIn('id', $attachmentIds)
            ->update([
                'state' => FileAttachment::STATE_DELETED,
                'deleted_at' => now(),
                'purge_after' => now()->addDays($this->settings->trashRetentionDays()),
                'updated_at' => now(),
            ]);

        if ($attachmentIds !== []) {
            DB::table('file_attachments')
                ->where('entry_id', $entryId)
                ->where('vault_id', $vaultId)
                ->whereIn('id', $attachmentIds)
                ->update([
                    'state' => FileAttachment::STATE_AVAILABLE,
                    'deleted_at' => null,
                    'purge_after' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return array{used: int, limit: int, per_file: int}
     */
    public function usage(string $vaultId): array
    {
        return [
            'used' => $this->quota->usedBytes($vaultId),
            'limit' => $this->quota->maxBytesPerVault(),
            'per_file' => $this->quota->maxBytesPerFile(),
        ];
    }

    private function writableEntry(User $actor, string $entryId): Entry
    {
        $entry = Entry::query()->whereKey($entryId)->first();
        abort_if($entry === null || $entry->deleted_at !== null, 404);
        abort_unless($this->access->allowsOnEntry($actor, $entry, 'metadata.view'), 404);

        if (! $this->access->allowsOnEntry($actor, $entry, 'entry.update')) {
            throw new AuthorizationException((string) __('entries.errors.update_not_allowed'));
        }

        $vault = Vault::query()->findOrFail($entry->vault_id);
        if ($vault->state !== 'active') {
            throw ValidationException::withMessages(['attachment' => __('entries.validation.vault_not_active')]);
        }

        return $entry;
    }

    private function findLiveAttachment(string $attachmentId): FileAttachment
    {
        $attachment = FileAttachment::query()->whereKey($attachmentId)->first();
        abort_if(
            $attachment === null
            || $attachment->deleted_at !== null
            || $attachment->state !== FileAttachment::STATE_AVAILABLE,
            404,
        );

        return $attachment;
    }

    private function assertValidUpload(User $actor, UploadedFile $file): string
    {
        if (! $file->isValid()) {
            $this->reject($actor, (string) __('attachments.validation.upload_failed'));
        }

        $realPath = $file->getRealPath();
        if ($realPath === false || ! is_file($realPath)) {
            $this->reject($actor, (string) __('attachments.validation.unreadable'));
        }

        if ($file->getSize() < 1) {
            $this->reject($actor, (string) __('attachments.validation.empty'));
        }

        return $realPath;
    }

    private function reject(User $actor, string $message): never
    {
        $this->audit->record('attachment.validation_failed', $actor->id, 'denied');

        throw ValidationException::withMessages(['attachment' => $message]);
    }

    /**
     * Detect the MIME type from the first bytes of a seekable, non-empty
     * stream, then rewind it for encryption.
     *
     * @param  resource  $source
     */
    private function detectMime($source): string
    {
        $head = (string) fread($source, self::MIME_SNIFF_BYTES);
        rewind($source);

        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: 'application/octet-stream';
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment.bin';
        }

        return mb_substr($name, 0, 200);
    }

    private function randomPath(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 2).'/'.substr($hex, 2, 2).'/'.$hex.'.att';
    }

    /**
     * @param  resource|false|null  $destination
     */
    private function cleanupFailedUpload($destination, string $path, string $tempPath, string $reservationId): void
    {
        if (is_resource($destination)) {
            fclose($destination);
        }

        $disk = Storage::disk(self::DISK);
        $disk->delete([$path, $tempPath]);
        $this->quota->release($reservationId);
    }
}
