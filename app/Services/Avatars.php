<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Generated square avatars, returned as inline SVG data URIs.
 *
 * The colour is derived from a stable seed, so the same entry or vault keeps
 * the same colour everywhere it is shown.
 */
final class Avatars
{
    /**
     * A coloured square with the first letter of the label.
     */
    public function letter(string $label, ?string $seed = null): string
    {
        $letter = e(Str::upper(Str::substr(trim($label), 0, 1)) ?: '?');

        return $this->dataUri($seed ?? $label, '<text x="32" y="43" font-family="system-ui,sans-serif" font-size="32" font-weight="600" fill="#fff" text-anchor="middle">'.$letter.'</text>');
    }

    /**
     * A coloured square with a white outline Heroicon, such as `key`.
     */
    public function heroicon(string $name, string $seed): string
    {
        $contents = svg('heroicon-o-'.$name)->contents();
        $paths = (string) preg_replace('/^.*?<svg[^>]*>|<\/svg>\s*$/s', '', $contents);

        return $this->dataUri($seed, '<g transform="translate(14 14) scale(1.5)" fill="none" stroke="#fff" stroke-width="1.5">'.$paths.'</g>');
    }

    private function dataUri(string $seed, string $content): string
    {
        $hue = crc32($seed) % 360;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="12" fill="hsl('.$hue.',55%,45%)"/>'
            .$content
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
