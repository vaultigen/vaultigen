<?php

namespace App\Services;

use App\Models\Entry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Permission-scoped metadata search over the deliberately exposed fields only:
 * entry title, sanitized URL host, entry type, and folder/tag names.
 *
 * Usernames, complete URLs, notes, filenames, and custom values stay encrypted
 * and are therefore never searchable. The term is normalized and its LIKE
 * wildcards are escaped with the shared `!` escape character, and its length is
 * bounded before it reaches the database.
 */
final class EntrySearch
{
    public function __construct(private readonly Normalizer $normalizer) {}

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    public function apply(Builder $query, string $term): Builder
    {
        if (trim($term) === '') {
            return $query;
        }

        $pattern = $this->normalizer->containsPattern($term);

        return $query->where(function (Builder $query) use ($pattern): void {
            $query->whereRaw("entries.normalized_title like ? escape '!'", [$pattern])
                ->orWhereRaw("entries.url_host like ? escape '!'", [$pattern])
                ->orWhereRaw("entries.type like ? escape '!'", [$pattern])
                ->orWhereHas('folder', fn (Builder $folder): Builder => $folder->whereRaw("folders.normalized_name like ? escape '!'", [$pattern]))
                ->orWhereHas('tags', fn (Builder $tag): Builder => $tag->whereRaw("tags.normalized_name like ? escape '!'", [$pattern]));
        });
    }
}
