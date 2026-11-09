<?php

namespace App\Http\Controllers;

use App\Models\SiteIcon;
use App\Models\User;
use App\Services\EntryVault;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SiteIconController
{
    /**
     * Serve the cached icon of an entry's host.
     *
     * The icon is addressed through the entry, not the host, so a user cannot
     * probe whether some vault they cannot see contains a given site. The
     * bytes are served as an inert image: no sniffing, no scripts, no framing.
     */
    public function show(Request $request, string $entry): Response
    {
        /** @var User $user */
        $user = $request->user();
        $record = app(EntryVault::class)->find($user, $entry);

        $icon = $record->url_host === null ? null : SiteIcon::query()->find($record->url_host);
        abort_if($icon === null || $icon->data === null || $icon->mime === null, 404);

        $etag = '"'.$icon->checksum.'"';
        $headers = [
            'Content-Type' => $icon->mime,
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response((string) base64_decode($icon->data, true), 200, $headers);
    }
}
