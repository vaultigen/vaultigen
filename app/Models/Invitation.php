<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Read-only access to the invitations table. Invitations must only ever be
 * written through Invitations, which manages token digests, expiry, delivery
 * state, and audit records; this model exists so Filament tables can query,
 * search, filter, and sort invitations.
 *
 * @property string $id
 * @property string $intended_normalized_email
 * @property string $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $failure_code
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $invitedBy
 */
class Invitation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'invitations';

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
