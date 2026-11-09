<?php

namespace App\Models;

use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sysPass (or future) import attempt.
 *
 * There is deliberately no per-item table: the importer does not map source
 * rows to entries and does not resume, so counters, created-vault links, and a
 * capped failure list live on the run. Every column is non-secret metadata.
 *
 * @property string $id
 * @property string $requested_by_user_id
 * @property string $source_format
 * @property string $source_fingerprint
 * @property string|null $original_filename
 * @property string $status
 * @property string|null $error_code
 * @property string|null $random_storage_path
 * @property int|null $envelope_format_version
 * @property string|null $import_key_algorithm
 * @property string|null $import_key_nonce
 * @property string|null $import_key_key_id
 * @property string|null $wrapped_import_key
 * @property string|null $wrapped_credentials
 * @property string|null $credentials_key_id
 * @property string|null $layout
 * @property array<string, mixed>|null $axis_config
 * @property array<string, mixed>|null $plan
 * @property array<string, int>|null $counters
 * @property list<string>|null $created_vault_ids
 * @property list<array{source_id: string|null, title: string, client: string, error_code: string}>|null $failures
 * @property int $failure_count
 * @property int|null $progress_total
 * @property Carbon|null $started_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'requested_by_user_id', 'source_format', 'source_fingerprint', 'original_filename', 'status',
    'error_code', 'random_storage_path', 'envelope_format_version', 'import_key_algorithm', 'import_key_nonce',
    'import_key_key_id', 'wrapped_import_key', 'wrapped_credentials', 'credentials_key_id', 'layout', 'axis_config',
    'plan', 'counters', 'created_vault_ids', 'failures', 'failure_count', 'progress_total', 'started_at',
    'queued_at', 'cancel_requested_at', 'completed_at', 'failed_at',
])]
#[Hidden(['random_storage_path', 'import_key_nonce', 'import_key_key_id', 'wrapped_import_key', 'wrapped_credentials', 'credentials_key_id'])]
class ImportRun extends Model
{
    /** @use HasFactory<ImportRunFactory> */
    use HasFactory, HasUuids;

    public const STATUS_STAGING = 'staging';

    public const STATUS_STAGED = 'staged';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_STAGING,
        self::STATUS_STAGED,
        self::STATUS_QUEUED,
        self::STATUS_IMPORTING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Terminal runs have no further transitions and can be retained or purged
     * by the cleanup command.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    /**
     * A run that is queued for, or executing in, a background worker.
     */
    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_IMPORTING], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'envelope_format_version' => 'integer',
            'axis_config' => 'array',
            'plan' => 'array',
            'counters' => 'array',
            'created_vault_ids' => 'array',
            'failures' => 'array',
            'failure_count' => 'integer',
            'progress_total' => 'integer',
            'started_at' => 'datetime',
            'queued_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
