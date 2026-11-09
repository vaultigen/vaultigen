<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $vault_id
 * @property string $entry_id
 * @property int $revision_number
 * @property string $vault_key_version_id
 * @property string $cipher_suite
 * @property int $payload_format_version
 * @property int $payload_schema_version
 * @property string $payload_nonce
 * @property string $encrypted_snapshot
 * @property string $changed_by_user_id
 * @property string|null $change_reason
 * @property Carbon $created_at
 */
#[Hidden(['encrypted_snapshot', 'payload_nonce', 'vault_key_version_id'])]
class EntryRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'payload_format_version' => 'integer',
            'payload_schema_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
