<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-user reveal tracking for offboarding review.
 *
 * Every successful secret delivery records that the user saw that entry. Only
 * metadata is stored; the delivered value itself is never persisted here. Rows
 * are removed with their entry so a purged credential leaves no stale report.
 */
final class SecretReveals
{
    public function record(User $user, string $vaultId, string $entryId): void
    {
        $updated = DB::table('secret_reveals')
            ->where('user_id', $user->id)
            ->where('entry_id', $entryId)
            ->update([
                'reveal_count' => DB::raw('reveal_count + 1'),
                'last_revealed_at' => now(),
            ]);

        if ($updated > 0) {
            return;
        }

        DB::table('secret_reveals')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'vault_id' => $vaultId,
            'entry_id' => $entryId,
            'reveal_count' => 1,
            'first_revealed_at' => now(),
            'last_revealed_at' => now(),
        ]);
    }

    /**
     * Entries the user has revealed, as metadata only. Used by the offboarding
     * report to name credentials that may need rotation without showing them.
     *
     * @return list<array{entry_id: string, title: string, vault_id: string, vault_name: string, type: string, last_revealed_at: Carbon|null, reveal_count: int}>
     */
    public function forUser(User $user): array
    {
        return array_values(DB::table('secret_reveals')
            ->join('entries', 'entries.id', '=', 'secret_reveals.entry_id')
            ->join('vaults', 'vaults.id', '=', 'secret_reveals.vault_id')
            ->where('secret_reveals.user_id', $user->id)
            ->orderByDesc('secret_reveals.last_revealed_at')
            ->get([
                'secret_reveals.entry_id',
                'secret_reveals.vault_id',
                'secret_reveals.reveal_count',
                'secret_reveals.last_revealed_at',
                'entries.title',
                'entries.type',
                'vaults.name as vault_name',
            ])
            ->map(fn (object $row): array => [
                'entry_id' => (string) $row->entry_id,
                'title' => (string) $row->title,
                'vault_id' => (string) $row->vault_id,
                'vault_name' => (string) $row->vault_name,
                'type' => (string) $row->type,
                'last_revealed_at' => $row->last_revealed_at === null ? null : Carbon::parse((string) $row->last_revealed_at),
                'reveal_count' => (int) $row->reveal_count,
            ])
            ->all());
    }
}
