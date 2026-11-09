<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kind
 * @property Carbon $created_at
 */
class Principal extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** @return HasOne<Team, $this> */
    public function team(): HasOne
    {
        return $this->hasOne(Team::class);
    }
}
