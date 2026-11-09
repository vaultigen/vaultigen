<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The cached favicon of one login URL host.
 *
 * Icons are keyed by the already-plaintext `entries.url_host`, so one fetch
 * serves every entry that points at the same site and no new data about an
 * entry is stored in the clear. `state` drives the fetch queue: `pending` rows
 * wait for the worker and `fetching` rows are claimed. `fetched` and `failed`
 * rows are re-queued once `next_check_at` passes (30 days after a success, a
 * growing back-off after a failure). A failed refresh keeps the earlier image.
 *
 * @property string $host
 * @property string $state
 * @property string|null $mime
 * @property string|null $data
 * @property string|null $checksum
 * @property int $failures
 * @property Carbon|null $claimed_at
 * @property Carbon|null $checked_at
 * @property Carbon|null $next_check_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['host', 'state', 'mime', 'data', 'checksum', 'failures', 'claimed_at', 'checked_at', 'next_check_at'])]
#[Hidden(['data'])]
class SiteIcon extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_FETCHING = 'fetching';

    public const STATE_FETCHED = 'fetched';

    public const STATE_FAILED = 'failed';

    protected $primaryKey = 'host';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failures' => 'integer',
            'claimed_at' => 'datetime',
            'checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }
}
