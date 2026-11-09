<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $vault_id
 * @property int $version
 * @property string $created_by_user_id
 * @property Carbon $created_at
 * @property Carbon|null $retired_at
 * @property Carbon|null $compromised_at
 */
class VaultKeyVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'datetime',
            'retired_at' => 'datetime',
            'compromised_at' => 'datetime',
        ];
    }
}
