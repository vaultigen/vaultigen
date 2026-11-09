<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Controlled audit retention.
 *
 * Pruning removes an aged prefix of the audit chain after preserving an
 * external-keyed checkpoint for the deleted range and recording the surviving
 * chain anchor. IP addresses and user agents are held outside the hash chain so
 * their shorter privacy retention can redact them without breaking integrity.
 */
final class AuditRetention
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly InstallationSettings $settings,
    ) {}

    /**
     * @return array{redacted: int, pruned: int, first_sequence: int|null, last_sequence: int|null, checkpoint_id: string|null, verified: bool}
     */
    public function prune(?User $actor = null): array
    {
        $values = $this->settings->values();

        $result = $this->audit->transaction(function () use ($values, $actor): array {
            $redacted = $this->redactMetadata($values['audit_ip_retention_days']);
            $pruned = $this->pruneEvents($values['audit_retention_days'], $actor);

            return ['redacted' => $redacted, ...$pruned];
        });

        $result['verified'] = $this->audit->verify();

        return $result;
    }

    /**
     * Redact IP addresses and user agents older than the privacy retention.
     * These columns are not part of the hash chain.
     */
    private function redactMetadata(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return DB::table('audit_event_metadata')
            ->where('created_at', '<', now()->subDays($days))
            ->where(fn ($query) => $query->whereNotNull('ip_address')->orWhereNotNull('user_agent'))
            ->update(['ip_address' => null, 'user_agent' => null]);
    }

    /**
     * @return array{pruned: int, first_sequence: int|null, last_sequence: int|null, checkpoint_id: string|null}
     */
    private function pruneEvents(int $days, ?User $actor): array
    {
        $cutoff = now()->utc()->subDays($days)->format('Y-m-d\TH:i:s.u\Z');
        $range = DB::table('audit_events')
            ->where('occurred_at', '<', $cutoff)
            ->selectRaw('MIN(sequence) as first_sequence, MAX(sequence) as last_sequence, COUNT(*) as total')
            ->first();

        if ($range === null || (int) $range->total === 0) {
            return ['pruned' => 0, 'first_sequence' => null, 'last_sequence' => null, 'checkpoint_id' => null];
        }

        $first = (int) $range->first_sequence;
        $last = (int) $range->last_sequence;
        $finalHash = (string) DB::table('audit_events')->where('sequence', $last)->value('event_hash');
        $checkpointId = (string) Str::uuid();

        DB::table('audit_prune_checkpoints')->insert([
            'id' => $checkpointId,
            'first_sequence' => $first,
            'last_sequence' => $last,
            'final_event_hash' => $finalHash,
            'key_id' => config('vaultigen.audit_key_id'),
            'checkpoint_mac' => $this->audit->pruneMac($first, $last, $finalHash),
            'reason' => 'periodic',
            'created_at' => now(),
        ]);

        // occurred_at is monotonic with sequence, so the aged rows are a prefix.
        DB::table('audit_checkpoints')->where('sequence', '<=', $last)->delete();
        DB::table('audit_event_metadata')->where('sequence', '<=', $last)->delete();
        DB::table('audit_events')->where('sequence', '<=', $last)->delete();
        DB::table('audit_chain_heads')->where('id', 1)->update(['anchor_sequence' => $last, 'anchor_hash' => $finalHash]);

        $this->audit->record('audit.pruned', $actor?->id, 'success', [
            'source_channel' => $actor === null ? 'scheduler' : 'web',
            'sanitized_context' => ['first_sequence' => $first, 'last_sequence' => $last, 'reason' => 'periodic'],
        ]);

        return ['pruned' => (int) $range->total, 'first_sequence' => $first, 'last_sequence' => $last, 'checkpoint_id' => $checkpointId];
    }
}
