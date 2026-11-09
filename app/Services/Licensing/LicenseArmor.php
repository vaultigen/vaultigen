<?php

namespace App\Services\Licensing;

/**
 * The armored v1 license wire format.
 */
final class LicenseArmor
{
    public const BEGIN = '-----BEGIN VAULTIGEN LICENSE-----';

    public const END = '-----END VAULTIGEN LICENSE-----';

    public const MAX_BYTES = 8192;

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
