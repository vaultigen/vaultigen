<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $vault_id
 * @property string $entry_id
 * @property string $vault_key_version_id
 * @property string $encrypted_metadata
 * @property int $byte_size
 * @property string $random_storage_path
 * @property int $stream_format_version
 * @property string $wrapped_file_key_algorithm
 * @property string $wrapped_file_key_nonce
 * @property string $wrapped_file_key
 * @property string $ciphertext_checksum
 * @property string|null $keyed_plaintext_fingerprint
 * @property string $state
 * @property string $created_by_user_id
 * @property Carbon|null $deleted_at
 * @property Carbon|null $purge_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['vault_id', 'entry_id', 'vault_key_version_id', 'encrypted_metadata', 'byte_size', 'random_storage_path', 'stream_format_version', 'wrapped_file_key_algorithm', 'wrapped_file_key_nonce', 'wrapped_file_key', 'ciphertext_checksum', 'keyed_plaintext_fingerprint', 'state', 'created_by_user_id', 'deleted_at', 'purge_after'])]
#[Hidden(['encrypted_metadata', 'random_storage_path', 'wrapped_file_key', 'wrapped_file_key_nonce', 'vault_key_version_id', 'ciphertext_checksum'])]
class FileAttachment extends Model
{
    use HasUuids;

    public const STATE_STAGING = 'staging';

    public const STATE_AVAILABLE = 'available';

    public const STATE_QUARANTINED = 'quarantined';

    public const STATE_DELETING = 'deleting';

    public const STATE_DELETED = 'deleted';

    public const STATE_FAILED = 'failed';

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'stream_format_version' => 'integer',
            'deleted_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }
}
