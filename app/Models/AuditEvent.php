<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only access to the hash-chained audit log. Events must only ever be
 * written through AuditWriter, which maintains the verification chain; this
 * model exists so Filament tables can query, search, and filter the log.
 *
 * @property int $sequence
 * @property string|null $actor_id
 * @property string|null $actor_name_snapshot
 * @property string $action
 * @property string $outcome
 * @property string $occurred_at
 * @property string|null $vault_id
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $source_channel
 * @property string|null $sanitized_context
 * @property string $canonical_event
 * @property string $previous_hash
 * @property string $event_hash
 * @property-read User|null $actor
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'sequence';

    protected $table = 'audit_events';

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
