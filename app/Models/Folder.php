<?php

namespace App\Models;

use Database\Factories\FolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A flat, vault-scoped folder.
 *
 * MVP folders are a single level per vault: `parent_id` is always null and
 * `depth` is always 0. They are retained as extension points for future
 * nesting and are enforced by a database check constraint as well.
 *
 * @property string $id
 * @property string $vault_id
 * @property string|null $parent_id
 * @property string $name
 * @property string $normalized_name
 * @property int $depth
 * @property int $lock_version
 * @property Carbon|null $deleted_at
 * @property string|null $deleted_by_user_id
 * @property Carbon|null $purge_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['vault_id', 'name', 'normalized_name', 'depth', 'lock_version'])]
class Folder extends Model
{
    /** @use HasFactory<FolderFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'lock_version' => 'integer',
            'deleted_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }
}
