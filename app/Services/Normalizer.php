<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * One documented normalization and LIKE-escaping algorithm for all vault-scoped
 * names and metadata search, so behavior is identical on every database.
 *
 * Names are trimmed, internal whitespace is collapsed, and the result is
 * case-folded with PHP's Unicode-aware lowercasing to match the stored
 * `normalized_*` columns. LIKE patterns escape the SQL wildcards and the
 * selected escape character (`!`) so user input is matched literally.
 */
final class Normalizer
{
    public const MAX_SEARCH_TERM = 100;

    public function normalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return Str::lower($collapsed ?? '');
    }

    /**
     * Escape `!`, `%`, and `_` for a `LIKE ? ESCAPE '!'` comparison.
     */
    public function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * A bounded, normalized, wildcard-escaped `%term%` pattern.
     */
    public function containsPattern(string $term): string
    {
        $bounded = Str::substr(trim($term), 0, self::MAX_SEARCH_TERM);

        return '%'.$this->escapeLike($this->normalize($bounded)).'%';
    }
}
