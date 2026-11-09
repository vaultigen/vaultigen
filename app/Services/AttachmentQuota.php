<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the per-file and per-vault attachment byte limits.
 *
 * A reservation is written before a single byte is encrypted and is reconciled
 * inside the writer-serializing audit transaction, so two concurrent uploads
 * cannot both consume the same remaining vault quota. Reservations expire, and
 * expired or abandoned reservations are released idempotently.
 */
final class AttachmentQuota
{
    public const DEFAULT_MAX_BYTES_PER_FILE = 25 * 1024 * 1024;

    public const DEFAULT_MAX_BYTES_PER_VAULT = 500 * 1024 * 1024;

    public const RESERVATION_TTL_MINUTES = 15;

    public function __construct(private readonly AuditWriter $audit) {}

    public function maxBytesPerFile(): int
    {
        return max(1, (int) config('vaultigen.attachments.max_bytes_per_file', self::DEFAULT_MAX_BYTES_PER_FILE));
    }

    public function maxBytesPerVault(): int
    {
        return max(1, (int) config('vaultigen.attachments.max_bytes_per_vault', self::DEFAULT_MAX_BYTES_PER_VAULT));
    }

    /**
     * Reserve quota for an incoming upload, or fail with a validation error.
     */
    public function reserve(User $actor, string $vaultId, int $bytes): string
    {
        if ($bytes < 1 || $bytes > $this->maxBytesPerFile()) {
            throw ValidationException::withMessages(['attachment' => __('attachments.validation.too_large')]);
        }

        return $this->audit->transaction(function () use ($actor, $vaultId, $bytes): string {
            $this->releaseExpired();

            if ($this->usedBytes($vaultId) + $bytes > $this->maxBytesPerVault()) {
                throw ValidationException::withMessages(['attachment' => __('attachments.validation.vault_quota')]);
            }

            $id = (string) Str::uuid();
            DB::table('attachment_reservations')->insert([
                'id' => $id,
                'vault_id' => $vaultId,
                'requested_by_user_id' => $actor->id,
                'reserved_bytes' => $bytes,
                'expires_at' => now()->addMinutes(self::RESERVATION_TTL_MINUTES),
                'state' => 'reserved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });
    }

    public function commit(string $reservationId, string $attachmentId): void
    {
        DB::table('attachment_reservations')
            ->where('id', $reservationId)
            ->where('state', 'reserved')
            ->update([
                'state' => 'committed',
                'attachment_id' => $attachmentId,
                'updated_at' => now(),
            ]);
    }

    public function release(string $reservationId): void
    {
        DB::table('attachment_reservations')
            ->where('id', $reservationId)
            ->where('state', 'reserved')
            ->update([
                'state' => 'released',
                'updated_at' => now(),
            ]);
    }

    /**
     * Release every reservation whose upload did not complete in time.
     */
    public function releaseExpired(): int
    {
        return DB::table('attachment_reservations')
            ->where('state', 'reserved')
            ->where('expires_at', '<=', now())
            ->update([
                'state' => 'released',
                'updated_at' => now(),
            ]);
    }

    /**
     * The vault's committed live bytes plus its outstanding reservations.
     */
    public function usedBytes(string $vaultId): int
    {
        $stored = (int) DB::table('file_attachments')
            ->where('vault_id', $vaultId)
            ->whereNull('deleted_at')
            ->whereIn('state', ['staging', 'available', 'quarantined', 'failed'])
            ->sum('byte_size');

        $reserved = (int) DB::table('attachment_reservations')
            ->where('vault_id', $vaultId)
            ->where('state', 'reserved')
            ->sum('reserved_bytes');

        return $stored + $reserved;
    }
}
