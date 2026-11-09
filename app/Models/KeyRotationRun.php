<?php

namespace App\Models;

use Database\Factories\KeyRotationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Non-secret progress of one rewrapping or vault-key rotation attempt.
 *
 * A run is restartable: `cursor` is an opaque position in the work list and the
 * counters describe how far it got. It never holds a key or a plaintext value.
 *
 * @property string $id
 * @property string $operation
 * @property string|null $vault_id
 * @property string $source_key_id
 * @property string $target_key_id
 * @property string $status
 * @property string|null $cursor
 * @property int $processed_count
 * @property int $failed_count
 * @property string|null $started_by_user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property string|null $error_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'operation', 'vault_id', 'source_key_id', 'target_key_id', 'status', 'cursor',
    'processed_count', 'failed_count', 'started_by_user_id', 'started_at',
    'completed_at', 'failed_at', 'error_code',
])]
class KeyRotationRun extends Model
{
    /** @use HasFactory<KeyRotationRunFactory> */
    use HasFactory, HasUuids;

    public const OPERATION_KEK_REWRAP = 'installation_kek_rewrap';

    public const OPERATION_VAULT_ROTATE = 'vault_key_rotate';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
