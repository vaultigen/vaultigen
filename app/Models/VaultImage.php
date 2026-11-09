<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The uploaded avatar image of one vault.
 *
 * Uploads are never stored as given: they are re-encoded to a small square
 * image first, so `data` only ever holds a few kilobytes of trusted output.
 *
 * @property string $vault_id
 * @property string $mime
 * @property string $data
 * @property string $checksum
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['vault_id', 'mime', 'data', 'checksum'])]
#[Hidden(['data'])]
class VaultImage extends Model
{
    protected $primaryKey = 'vault_id';

    protected $keyType = 'string';

    public $incrementing = false;
}
