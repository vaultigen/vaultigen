<?php

namespace App\Services;

use App\Models\Vault;
use App\Models\VaultImage;
use Illuminate\Validation\ValidationException;

/**
 * Vault avatars: uploaded images, picked icons, or a letter fallback.
 *
 * An upload is decoded and redrawn as a small square image, so the stored
 * bytes are always our own encoder's output: no metadata, no embedded
 * payloads, and only a few kilobytes per vault. SVG is never accepted.
 */
final class VaultImages
{
    public const TARGET_SIZE = 64;

    public const MAX_UPLOAD_KILOBYTES = 2048;

    public const MAX_DIMENSION = 4096;

    public const ACCEPTED_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const ALLOWED_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    public function __construct(private readonly Avatars $avatars) {}

    /**
     * The image source for a vault: its uploaded image, its icon, or a letter
     * avatar. Eager-load `image:vault_id,checksum` when rendering many vaults.
     */
    public function avatarUrl(Vault $vault): string
    {
        $image = $vault->relationLoaded('image') ? $vault->image : $vault->image()->first(['vault_id', 'checksum']);
        if ($image !== null) {
            return route('vaults.image', ['vault' => $vault->id, 'v' => substr($image->checksum, 0, 12)]);
        }

        if ($vault->icon !== null && isset(VaultAdministration::ICONS[$vault->icon])) {
            return $this->avatars->heroicon($vault->icon, $vault->id);
        }

        return $this->avatars->letter($vault->name, $vault->id);
    }

    /**
     * Re-encode an upload as a square avatar, centre-cropped.
     *
     * @return array{mime: string, bytes: string}
     *
     * @throws ValidationException when the bytes are not an acceptable image
     */
    public function normalize(string $bytes): array
    {
        $info = strlen($bytes) <= self::MAX_UPLOAD_KILOBYTES * 1024 ? @getimagesizefromstring($bytes) : false;
        if (! is_array($info) || ! in_array($info[2], self::ALLOWED_TYPES, true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            $this->reject();
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            $this->reject();
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $side = min($width, $height);
        $target = imagecreatetruecolor(self::TARGET_SIZE, self::TARGET_SIZE);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::TARGET_SIZE, self::TARGET_SIZE, $side, $side);

        ob_start();
        imagewebp($target, null, 85);

        return ['mime' => 'image/webp', 'bytes' => (string) ob_get_clean()];
    }

    /**
     * Store an already normalized image as the vault's avatar.
     *
     * @param  array{mime: string, bytes: string}  $image
     */
    public function store(string $vaultId, array $image): void
    {
        VaultImage::query()->updateOrCreate(['vault_id' => $vaultId], [
            'mime' => $image['mime'],
            'data' => base64_encode($image['bytes']),
            'checksum' => hash('sha256', $image['bytes']),
        ]);
    }

    public function remove(string $vaultId): bool
    {
        return VaultImage::query()->whereKey($vaultId)->delete() > 0;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['image' => __('vaults.errors.invalid_image')]);
    }
}
