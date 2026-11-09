<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $vault_id
 * @property string|null $folder_id
 * @property string $owner_user_id
 * @property string $type
 * @property string $title
 * @property string $normalized_title
 * @property string|null $url_host
 * @property string $vault_key_version_id
 * @property string $cipher_suite
 * @property int $payload_format_version
 * @property int $payload_schema_version
 * @property string $payload_nonce
 * @property string $encrypted_payload
 * @property int $lock_version
 * @property Carbon|null $expires_at
 * @property Carbon|null $deleted_at
 * @property string|null $deleted_by_user_id
 * @property Carbon|null $purge_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['vault_id', 'folder_id', 'owner_user_id', 'type', 'title', 'normalized_title', 'url_host', 'expires_at'])]
#[Hidden(['encrypted_payload', 'payload_nonce', 'vault_key_version_id'])]
class Entry extends Model
{
    use HasUuids;

    public const TYPE_LOGIN = 'login';

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'entry_tag')
            ->withPivot('vault_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return BelongsTo<SiteIcon, $this> */
    public function siteIcon(): BelongsTo
    {
        return $this->belongsTo(SiteIcon::class, 'url_host', 'host');
    }

    /** @return HasMany<EntryRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(EntryRevision::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_format_version' => 'integer',
            'payload_schema_version' => 'integer',
            'lock_version' => 'integer',
            'expires_at' => 'datetime',
            'deleted_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }
}
