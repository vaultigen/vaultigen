<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A tag label.
 *
 * A tag is either installation-wide (`scope = global`, `vault_id = null`) and
 * managed by a Super Admin, or scoped to one vault (`scope = vault`) and
 * managed by that vault's Editor or Manager. A global tag may be attached to an
 * entry in any vault; a vault tag may not leave its vault.
 *
 * @property string $id
 * @property string|null $vault_id
 * @property string $scope
 * @property string|null $global_key
 * @property string $name
 * @property string $normalized_name
 * @property string|null $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['vault_id', 'scope', 'global_key', 'name', 'normalized_name', 'color'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory, HasUuids;

    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_VAULT = 'vault';

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return BelongsToMany<Entry, $this> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'entry_tag')
            ->withPivot('vault_id');
    }

    public function isGlobal(): bool
    {
        return $this->scope === self::SCOPE_GLOBAL;
    }

    /**
     * @param  Builder<Tag>  $query
     * @return Builder<Tag>
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_GLOBAL);
    }
}
