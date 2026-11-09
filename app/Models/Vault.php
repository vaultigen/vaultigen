<?php

namespace App\Models;

use Database\Factories\VaultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $normalized_name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property string $kind
 * @property string|null $private_workspace_owner_user_id
 * @property string $primary_custodian_user_id
 * @property string $state
 * @property Carbon|null $purge_after
 * @property string|null $purge_previous_state
 * @property string $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'normalized_name', 'slug', 'description', 'icon', 'kind', 'private_workspace_owner_user_id', 'primary_custodian_user_id', 'state', 'created_by_user_id'])]
class Vault extends Model
{
    /** @use HasFactory<VaultFactory> */
    use HasFactory, HasUuids;

    public const KIND_SHARED = 'shared';

    public const KIND_PRIVATE = 'private_workspace';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['purge_after' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'private_workspace_owner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_custodian_user_id');
    }

    /** @return HasOne<VaultImage, $this> */
    public function image(): HasOne
    {
        return $this->hasOne(VaultImage::class);
    }

    /** @return HasMany<VaultGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(VaultGrant::class);
    }

    /** @return HasMany<Folder, $this> */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }
}
