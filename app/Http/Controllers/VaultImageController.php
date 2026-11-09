<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VaultImage;
use App\Services\VaultAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VaultImageController
{
    /**
     * Serve a vault's uploaded image to anyone who may see or administer the vault.
     *
     * Everyone else gets the same 404 as for a vault without an image, so the
     * route reveals nothing about vaults the user cannot reach.
     */
    public function show(Request $request, string $vault): Response
    {
        /** @var User $user */
        $user = $request->user();
        $access = app(VaultAccess::class);
        $reachable = $access->visibleVaults($user)->whereKey($vault)->exists()
            || $access->administeredVaults($user)->whereKey($vault)->exists();

        $image = $reachable ? VaultImage::query()->find($vault) : null;
        abort_if($image === null, 404);

        $etag = '"'.$image->checksum.'"';
        $headers = [
            'Content-Type' => $image->mime,
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response((string) base64_decode($image->data, true), 200, $headers);
    }
}
