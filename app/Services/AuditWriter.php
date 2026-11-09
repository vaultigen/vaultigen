<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class AuditWriter
{
    /** @var array<string, mixed>|null */
    private ?array $ambientContext = null;

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public function transaction(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            // The first write acquires SQLite's write lock and a row lock on server databases.
            DB::table('audit_chain_heads')->where('id', 1)->update(['id' => 1]);
            $this->key();

            return $operation();
        }, attempts: 5);
    }

    /**
     * Attach redacted context to every event recorded inside the callback.
     *
     * Used by an import run so all reused domain-service events carry the run
     * id without threading it through every service. Restored in a finally so a
     * failed callback cannot leak context into later writes.
     *
     * @template T
     *
     * @param  array<string, mixed>  $context
     * @param  Closure(): T  $operation
     * @return T
     */
    public function withContext(array $context, Closure $operation): mixed
    {
        $previous = $this->ambientContext;
        $this->ambientContext = array_merge($previous ?? [], $context);

        try {
            return $operation();
        } finally {
            $this->ambientContext = $previous;
        }
    }

    /**
     * Append one hash-chained event.
     *
     * $attributes may carry redacted, hash-covered metadata:
     * vault_id, target_type, target_id, source_channel, sanitized_context,
     * actor_name_snapshot, and (tests only) ip_address/user_agent overrides.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $action, ?string $actorId = null, string $outcome = 'success', array $attributes = []): int
    {
        $vaultId = $this->uuidOrNull($attributes['vault_id'] ?? null);
        $targetType = $this->stringOrNull($attributes['target_type'] ?? null, 60);
        $targetId = $this->uuidOrNull($attributes['target_id'] ?? null);
        $channel = $this->stringOrNull($attributes['source_channel'] ?? null, 20)
            ?? (app()->runningInConsole() ? 'console' : 'web');
        $context = $attributes['sanitized_context'] ?? null;
        $context = is_array($context) ? $context : null;
        if ($this->ambientContext !== null) {
            $context = array_merge($this->ambientContext, $context ?? []);
        }
        $actorName = $this->stringOrNull($attributes['actor_name_snapshot'] ?? null, 255)
            ?? ($actorId === null ? null : $this->stringOrNull(User::query()->whereKey($actorId)->value('name'), 255));
        [$ip, $userAgent] = $this->requestMetadata($attributes);
        $encodedContext = $context === null ? null : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->transaction(function () use ($action, $actorId, $outcome, $vaultId, $targetType, $targetId, $channel, $context, $actorName, $ip, $userAgent, $encodedContext): int {
            $head = DB::table('audit_chain_heads')->where('id', 1)->first();
            if ($head === null) {
                throw new RuntimeException('Audit storage is unavailable.');
            }
            $sequence = (int) $head->sequence + 1;
            $time = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            $event = json_encode([
                'action' => $action,
                'actor_id' => $actorId,
                'actor_name_snapshot' => $actorName,
                'installation_id' => config('vaultigen.installation_id'),
                'occurred_at' => $time,
                'outcome' => $outcome,
                'sanitized_context' => $context,
                'sequence' => $sequence,
                'source_channel' => $channel,
                'target_id' => $targetId,
                'target_type' => $targetType,
                'vault_id' => $vaultId,
                'version' => 2,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $hash = hash('sha256', $head->event_hash."\n".$event);
            DB::table('audit_events')->insert([
                'sequence' => $sequence, 'actor_id' => $actorId, 'actor_name_snapshot' => $actorName,
                'action' => $action, 'outcome' => $outcome, 'occurred_at' => $time,
                'vault_id' => $vaultId, 'target_type' => $targetType, 'target_id' => $targetId,
                'source_channel' => $channel, 'sanitized_context' => $encodedContext,
                'canonical_event' => $event, 'previous_hash' => $head->event_hash, 'event_hash' => $hash,
            ]);
            DB::table('audit_event_metadata')->insert([
                'sequence' => $sequence, 'ip_address' => $ip, 'user_agent' => $userAgent, 'created_at' => now(),
            ]);
            DB::table('audit_checkpoints')->insert([
                'sequence' => $sequence, 'event_hash' => $hash,
                'key_id' => config('vaultigen.audit_key_id'), 'mac' => $this->mac($sequence, $hash),
            ]);
            DB::table('audit_chain_heads')->where('id', 1)->update(['sequence' => $sequence, 'event_hash' => $hash]);

            return $sequence;
        });
    }

    public function verify(): bool
    {
        return $this->transaction(function (): bool {
            return (bool) $this->inspect()['verified'];
        });
    }

    /**
     * The integrity snapshot shown by the audit review UI.
     *
     * @return array{verified: bool, events: int, checkpoints: int, anchor_sequence: int, pruned: int, last_prune: array{first_sequence: int, last_sequence: int, reason: string}|null}
     */
    public function verifyDetail(): array
    {
        return $this->transaction(function (): array {
            return $this->inspect();
        });
    }

    /**
     * The keyed MAC for a pruned event range, exported alongside the prune record.
     */
    public function pruneMac(int $firstSequence, int $lastSequence, string $finalEventHash): string
    {
        return hash_hmac(
            'sha256',
            'vaultigen-audit-prune-v1|'.config('vaultigen.installation_id').'|'.$firstSequence.'|'.$lastSequence.'|'.$finalEventHash,
            $this->key(),
        );
    }

    /**
     * @return array{verified: bool, events: int, checkpoints: int, anchor_sequence: int, pruned: int, last_prune: array{first_sequence: int, last_sequence: int, reason: string}|null}
     */
    private function inspect(): array
    {
        $head = DB::table('audit_chain_heads')->where('id', 1)->first();
        if ($head === null) {
            return ['verified' => false, 'events' => 0, 'checkpoints' => 0, 'anchor_sequence' => 0, 'pruned' => 0, 'last_prune' => null];
        }

        $anchorSequence = (int) ($head->anchor_sequence ?? 0);
        $previous = (string) ($head->anchor_hash ?? str_repeat('0', 64));
        $sequence = $anchorSequence;
        $events = 0;
        $ok = true;
        foreach (DB::table('audit_events')->orderBy('sequence')->cursor() as $event) {
            $sequence++;
            $events++;
            $checkpoint = DB::table('audit_checkpoints')->where('sequence', $sequence)->first();
            $canonical = $this->canonicalFromRow($event);
            if ((int) $event->sequence !== $sequence
                || $event->previous_hash !== $previous
                || $canonical === null
                || $event->canonical_event !== $canonical
                || ! hash_equals(hash('sha256', $previous."\n".$canonical), (string) $event->event_hash)
                || $checkpoint === null
                || $checkpoint->key_id !== config('vaultigen.audit_key_id')
                || $checkpoint->event_hash !== $event->event_hash
                || ! hash_equals($this->mac($sequence, (string) $event->event_hash), (string) $checkpoint->mac)) {
                $ok = false;
                break;
            }
            $previous = (string) $event->event_hash;
        }

        $checkpoints = (int) DB::table('audit_checkpoints')->count();
        $lastPrune = DB::table('audit_prune_checkpoints')->orderByDesc('last_sequence')->first();

        return [
            'verified' => $ok
                && (int) $head->sequence === $sequence
                && $head->event_hash === $previous
                && $checkpoints === $events
                && $this->pruneCheckpointsValid(),
            'events' => $events,
            'checkpoints' => $checkpoints,
            'anchor_sequence' => $anchorSequence,
            'pruned' => (int) DB::table('audit_prune_checkpoints')->count(),
            'last_prune' => $lastPrune === null ? null : [
                'first_sequence' => (int) $lastPrune->first_sequence,
                'last_sequence' => (int) $lastPrune->last_sequence,
                'reason' => (string) $lastPrune->reason,
            ],
        ];
    }

    /**
     * Rebuild the canonical event from the stored columns, honouring the format
     * version recorded in the row. A null result means the row is unreadable.
     */
    private function canonicalFromRow(\stdClass $event): ?string
    {
        try {
            $stored = json_decode((string) $event->canonical_event, true, 16, JSON_THROW_ON_ERROR);
            $version = is_array($stored) && (int) ($stored['version'] ?? 1) === 1 ? 1 : 2;

            if ($version === 1) {
                return json_encode([
                    'action' => $event->action, 'actor_id' => $event->actor_id,
                    'installation_id' => config('vaultigen.installation_id'), 'occurred_at' => $event->occurred_at,
                    'outcome' => $event->outcome, 'sequence' => (int) $event->sequence, 'version' => 1,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $context = $event->sanitized_context === null
                ? null
                : json_decode((string) $event->sanitized_context, true, 512, JSON_THROW_ON_ERROR);

            return json_encode([
                'action' => $event->action,
                'actor_id' => $event->actor_id,
                'actor_name_snapshot' => $event->actor_name_snapshot,
                'installation_id' => config('vaultigen.installation_id'),
                'occurred_at' => $event->occurred_at,
                'outcome' => $event->outcome,
                'sanitized_context' => $context,
                'sequence' => (int) $event->sequence,
                'source_channel' => $event->source_channel,
                'target_id' => $event->target_id,
                'target_type' => $event->target_type,
                'vault_id' => $event->vault_id,
                'version' => 2,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return null;
        }
    }

    private function pruneCheckpointsValid(): bool
    {
        foreach (DB::table('audit_prune_checkpoints')->cursor() as $row) {
            if ($row->key_id !== config('vaultigen.audit_key_id')
                || ! hash_equals($this->pruneMac((int) $row->first_sequence, (int) $row->last_sequence, (string) $row->final_event_hash), (string) $row->checkpoint_mac)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: string|null, 1: string|null}
     */
    private function requestMetadata(array $attributes): array
    {
        if (array_key_exists('ip_address', $attributes) || array_key_exists('user_agent', $attributes)) {
            return [
                $this->stringOrNull($attributes['ip_address'] ?? null, 45),
                $this->stringOrNull($attributes['user_agent'] ?? null, 1000),
            ];
        }

        $request = request();

        return [
            $this->stringOrNull($request->ip(), 45),
            $this->stringOrNull($request->userAgent(), 1000),
        ];
    }

    private function stringOrNull(mixed $value, int $length): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : mb_substr($string, 0, $length);
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function mac(int $sequence, string $hash): string
    {
        return hash_hmac('sha256', 'vaultigen-audit-v1|'.config('vaultigen.installation_id').'|'.$sequence.'|'.$hash, $this->key());
    }

    private function key(): string
    {
        $key = base64_decode((string) config('vaultigen.audit_key'), true);
        $passkeySecret = base64_decode((string) config('vaultigen.passkey_secret'), true);
        $boundId = DB::table('installation_settings')->where('id', 1)->value('installation_id');
        if ($key === false || strlen($key) !== 32 || $passkeySecret === false || strlen($passkeySecret) !== 32
            || $key === $passkeySecret || config('vaultigen.audit_key') === Str::after((string) config('app.key'), 'base64:')
            || config('vaultigen.passkey_secret') === Str::after((string) config('app.key'), 'base64:')
            || ! Str::isUuid(config('vaultigen.installation_id'))
            || ($boundId !== null && $boundId !== config('vaultigen.installation_id'))) {
            throw new RuntimeException('Installation security configuration is missing or invalid. Run vaultigen:initialize before bootstrap.');
        }

        return $key;
    }
}
