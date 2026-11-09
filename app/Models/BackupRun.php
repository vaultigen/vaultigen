<?php

namespace App\Models;

use Database\Factories\BackupRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Non-secret status of one backup attempt.
 *
 * The backup set itself lives on disk under {@see $path}; this row only records
 * state, timing, counters, and a sanitized failure code. It never holds a key,
 * a plaintext value, or the manifest contents.
 *
 * @property string $id
 * @property string $state
 * @property string $trigger
 * @property string|null $requested_by_user_id
 * @property string|null $path
 * @property string|null $manifest_checksum
 * @property string|null $database_driver
 * @property int $object_count
 * @property int $total_bytes
 * @property string|null $app_version
 * @property string|null $schema_version
 * @property string|null $error_code
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'state', 'trigger', 'requested_by_user_id', 'path', 'manifest_checksum', 'database_driver',
    'object_count', 'total_bytes', 'app_version', 'schema_version', 'error_code',
    'started_at', 'completed_at', 'failed_at',
])]
#[Hidden(['path'])]
class BackupRun extends Model
{
    /** @use HasFactory<BackupRunFactory> */
    use HasFactory, HasUuids;

    public const STATE_PENDING = 'pending';

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    /** @var list<string> */
    public const STATES = [
        self::STATE_PENDING,
        self::STATE_RUNNING,
        self::STATE_COMPLETED,
        self::STATE_FAILED,
    ];

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_COMMAND = 'command';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MIGRATION = 'migration';

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::STATE_COMPLETED, self::STATE_FAILED], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'object_count' => 'integer',
            'total_bytes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
