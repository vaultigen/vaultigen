<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $principal_id
 * @property string $name
 * @property string $normalized_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_memberships', 'team_id', 'user_id')
            ->withPivot('created_by_user_id', 'created_at');
    }
}
