<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * Accessors for the canonical, versioned import representation.
 *
 * Every driver maps what it staged to this shape on read:
 *
 * - `schema`: the version, currently 1.
 * - `meta`, `warnings`: non-secret source details and warning counts.
 * - `axes`: `{axis: {id: label}}`, keyed by stable source identities.
 * - `root_label`: the vault name for entries without a vault-axis value, or
 *   null for the generic placeholder.
 * - `blobs`: `{ref: base64}` attachment bytes.
 * - `entries`: `source_id`, `title`, `username`, `password`, `url`, `notes`,
 *   `totp` (`{secret}` or null), `custom_fields`, `expires_at`, `tag_names`,
 *   `axis_values`, `attachments` (`{filename, blob_ref}` or
 *   `{filename, invalid}`), a non-secret `context` label for failure reports,
 *   and an optional driver-only `secret_ref`.
 */
final class ImportIr
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $ir
     *
     * @throws RuntimeException
     */
    public static function assertSupported(array $ir): void
    {
        if (($ir['schema'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('The staged import uses an unsupported schema version.');
        }
    }

    /**
     * @param  array<string, mixed>  $ir
     * @return list<array<string, mixed>>
     */
    public static function entries(array $ir): array
    {
        $entries = $ir['entries'] ?? [];

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * An entry's source id on an axis, or an empty string when it has none.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function axisValue(array $entry, string $axis): string
    {
        $value = $entry['axis_values'][$axis] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The display label of an axis value, or null when it is unknown or unnamed.
     *
     * @param  array<string, mixed>  $ir
     */
    public static function axisLabel(array $ir, string $axis, string $id): ?string
    {
        $label = $ir['axes'][$axis][$id] ?? null;
        if (! is_string($label)) {
            return null;
        }

        $label = trim($label);

        return $label === '' ? null : $label;
    }
}
