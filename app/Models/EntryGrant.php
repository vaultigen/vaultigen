<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An additive grant on a single entry. Entry grants give a direct path to that
 * entry without exposing unrelated vault metadata.
 *
 * @property string $id
 * @property string $principal_id
 * @property string $entry_id
 * @property string $permission
 * @property string $source
 * @property string|null $reason
 * @property string $granted_by_user_id
 * @property Carbon|null $expires_at
 * @property int|null $active_slot
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by_user_id
 * @property string|null $revocation_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EntryGrant extends Model
{
    use HasUuids;

    /** @return BelongsTo<Principal, $this> */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'active_slot' => 'integer',
        ];
    }
}
