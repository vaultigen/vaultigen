<?php

namespace App\Services\Imports\Keepass;

use App\Services\Imports\ImportIr;
use App\Services\Imports\ImportPlanner;
use App\Services\Imports\SafeXml;
use Carbon\CarbonImmutable;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Parses a KeePass 2.x / KeePassXC XML export into the canonical import
 * representation.
 *
 * Groups are walked recursively below the root group and mapped to three axes,
 * each identified by group UUID: `top_group` (the first level), `subgroup` (the
 * rest of the path below it), and `group_path` (the full path). Labels join the
 * path segments with ` / `, are bounded to the folder name limit, and are
 * suffixed when two different groups would otherwise share a normalized label,
 * so distinct groups never merge. The recycle bin and entry history are
 * skipped and counted. Files with `Protected="True"` values are stream
 * encrypted rather than a plain export and are refused.
 */
final class KeepassXmlParser
{
    public const AXIS_TOP_GROUP = 'top_group';

    public const AXIS_SUBGROUP = 'subgroup';

    public const AXIS_GROUP_PATH = 'group_path';

    public const WARNING_TOTP_UNSUPPORTED = 'totp_unsupported';

    public const WARNING_FIELD_REFERENCES = 'field_references';

    public const WARNING_INVALID_ATTACHMENTS = 'invalid_attachments';

    public const WARNING_RECYCLE_BIN = 'recycle_bin';

    public const WARNING_HISTORY = 'history';

    private const PATH_SEPARATOR = ' / ';

    private const MAX_TAG_NAME = 60;

    /**
     * Seconds between 0001-01-01 (the KDBX 4 epoch) and 1970-01-01.
     */
    private const KDBX_EPOCH_OFFSET = 62135596800;

    /**
     * The earliest and latest expiry a `timestamp` column holds on every
     * supported database. Anything outside is treated as never expiring.
     */
    private const MIN_EXPIRY = 86400;

    private const MAX_EXPIRY = 2147385600;

    private const STANDARD_FIELDS = [
        'Title' => 'title',
        'UserName' => 'username',
        'Password' => 'password',
        'URL' => 'url',
        'Notes' => 'notes',
    ];

    /** @var array<string, array<string, string>> */
    private array $axes = [];

    /** @var array<string, array<string, bool>> */
    private array $usedLabels = [];

    /** @var array<string, array{data: string, compressed: bool}> */
    private array $pool = [];

    /** @var array<string, string> */
    private array $blobs = [];

    /** @var array<string, string> */
    private array $blobErrors = [];

    /** @var array<string, int> */
    private array $warnings = [];

    private int $decodedBytes = 0;

    private int $inlineBlobs = 0;

    private int $groups = 0;

    private int $maxBlobBytes = 0;

    private int $maxTotalBytes = 0;

    public function __construct(
        private readonly ImportPlanner $planner,
        private readonly KeepassTotp $totp,
        private readonly KeepassBinaryDecoder $decoder,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws KeepassImportException
     */
    public function parse(#[\SensitiveParameter] string $xml, int $maxBlobBytes, int $maxTotalBytes): array
    {
        $this->reset($maxBlobBytes, $maxTotalBytes);

        try {
            $document = SafeXml::load($xml);
        } catch (Throwable) {
            throw new KeepassImportException(KeepassImportException::NOT_KEEPASS);
        }

        $root = $document->documentElement;
        $rootGroup = $root instanceof DOMElement && $root->nodeName === 'KeePassFile'
            ? $this->child($this->child($root, 'Root'), 'Group')
            : null;
        if ($rootGroup === null) {
            throw new KeepassImportException(KeepassImportException::NOT_KEEPASS);
        }

        $protected = (new DOMXPath($document))->query('//Value[@Protected="True" or @Protected="true"]');
        if ($protected === false || $protected->length > 0) {
            throw new KeepassImportException(KeepassImportException::PROTECTED_VALUES);
        }

        $meta = $this->child($root, 'Meta');
        $this->loadPool($meta);
        $recycleBin = $this->recycleBinUuid($meta);

        $entries = [];
        $this->walk($rootGroup, [], $recycleBin, $entries);

        $rootLabel = $this->text($rootGroup, 'Name') ?? $this->text($meta, 'DatabaseName');

        return [
            'schema' => ImportIr::SCHEMA_VERSION,
            'meta' => [
                'generator' => $this->text($meta, 'Generator'),
                'database_name' => $this->text($meta, 'DatabaseName'),
                'groups' => $this->groups,
            ],
            'warnings' => array_filter($this->warnings),
            'axes' => $this->axes,
            'root_label' => $rootLabel,
            'blobs' => array_map('base64_encode', $this->blobs),
            'entries' => $entries,
        ];
    }

    private function reset(int $maxBlobBytes, int $maxTotalBytes): void
    {
        $this->axes = [self::AXIS_TOP_GROUP => [], self::AXIS_SUBGROUP => [], self::AXIS_GROUP_PATH => []];
        $this->usedLabels = [self::AXIS_TOP_GROUP => [], self::AXIS_SUBGROUP => [], self::AXIS_GROUP_PATH => []];
        $this->pool = [];
        $this->blobs = [];
        $this->blobErrors = [];
        $this->warnings = [
            self::WARNING_TOTP_UNSUPPORTED => 0,
            self::WARNING_FIELD_REFERENCES => 0,
            self::WARNING_INVALID_ATTACHMENTS => 0,
            self::WARNING_RECYCLE_BIN => 0,
            self::WARNING_HISTORY => 0,
        ];
        $this->decodedBytes = 0;
        $this->inlineBlobs = 0;
        $this->groups = 0;
        $this->maxBlobBytes = $maxBlobBytes;
        $this->maxTotalBytes = $maxTotalBytes;
    }

    /**
     * @param  list<array{uuid: string, name: string}>  $path
     * @param  list<array<string, mixed>>  $entries
     */
    private function walk(DOMElement $group, array $path, ?string $recycleBin, array &$entries): void
    {
        foreach ($group->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->nodeName === 'Entry') {
                $entries[] = $this->entry($node, $path);

                continue;
            }

            if ($node->nodeName !== 'Group') {
                continue;
            }

            $uuid = $this->text($node, 'UUID') ?? 'group-'.($this->groups + 1);
            if ($recycleBin !== null && $uuid === $recycleBin) {
                $this->warnings[self::WARNING_RECYCLE_BIN] += $this->countEntries($node);

                continue;
            }

            $this->groups++;
            $name = $this->text($node, 'Name') ?? (string) __('imports.placeholders.untitled');
            $this->walk($node, [...$path, ['uuid' => $uuid, 'name' => $name]], $recycleBin, $entries);
        }
    }

    /**
     * @param  list<array{uuid: string, name: string}>  $path
     * @return array<string, mixed>
     */
    private function entry(DOMElement $element, array $path): array
    {
        $this->warnings[self::WARNING_HISTORY] += $this->countHistory($element);

        $standard = array_fill_keys(array_values(self::STANDARD_FIELDS), '');
        $others = [];
        $totpFields = [];
        foreach ($this->children($element, 'String') as $string) {
            $key = $this->text($string, 'Key', trim: false) ?? '';
            $valueElement = $this->child($string, 'Value');
            $value = $valueElement->textContent ?? '';

            if (isset(self::STANDARD_FIELDS[$key])) {
                $standard[self::STANDARD_FIELDS[$key]] = $value;

                continue;
            }

            if (KeepassTotp::isTotpField($key)) {
                $totpFields[$key] = $value;
            }

            $others[] = [
                'label' => $key,
                'value' => $value,
                'concealed' => strcasecmp($valueElement?->getAttribute('ProtectInMemory') ?? '', 'True') === 0,
            ];
        }

        $totp = null;
        if ($totpFields !== []) {
            $parsed = $this->totp->parse($totpFields);
            if ($parsed !== null) {
                $totp = ['secret' => $parsed['secret']];
                $others = array_values(array_filter($others, fn (array $field): bool => ! in_array($field['label'], $parsed['consumed'], true)));
            } else {
                $this->warnings[self::WARNING_TOTP_UNSUPPORTED]++;
                // Keep the original configuration, hidden, until login entries
                // can store their own TOTP parameters.
                $others = array_map(fn (array $field): array => KeepassTotp::isTotpField($field['label'])
                    ? [...$field, 'concealed' => true]
                    : $field, $others);
            }
        }

        foreach ([...array_values($standard), ...array_column($others, 'value')] as $value) {
            if (str_contains($value, '{REF:')) {
                $this->warnings[self::WARNING_FIELD_REFERENCES]++;

                break;
            }
        }

        $username = trim($standard['username']);

        return [
            'source_id' => $this->text($element, 'UUID'),
            'title' => trim($standard['title']),
            'username' => $username === '' ? null : $username,
            'password' => $standard['password'],
            'url' => trim($standard['url']),
            'notes' => $standard['notes'],
            'totp' => $totp,
            'custom_fields' => $others,
            'expires_at' => $this->expiry($element),
            'tag_names' => $this->tags($this->text($element, 'Tags') ?? ''),
            'axis_values' => $this->axisValues($path),
            'attachments' => $this->attachments($element),
            'context' => $path === [] ? '' : $this->axes[self::AXIS_GROUP_PATH][$path[array_key_last($path)]['uuid']],
        ];
    }

    /**
     * Register the entry's groups on every axis and return its axis values.
     * Entries directly under the root group have none.
     *
     * @param  list<array{uuid: string, name: string}>  $path
     * @return array<string, string>
     */
    private function axisValues(array $path): array
    {
        if ($path === []) {
            return [];
        }

        $names = array_column($path, 'name');
        $leaf = $path[array_key_last($path)]['uuid'];

        $values = [
            self::AXIS_TOP_GROUP => $this->register(self::AXIS_TOP_GROUP, $path[0]['uuid'], $names[0]),
            self::AXIS_GROUP_PATH => $this->register(self::AXIS_GROUP_PATH, $leaf, implode(self::PATH_SEPARATOR, $names)),
        ];
        if (count($path) > 1) {
            $values[self::AXIS_SUBGROUP] = $this->register(self::AXIS_SUBGROUP, $leaf, implode(self::PATH_SEPARATOR, array_slice($names, 1)));
        }

        return $values;
    }

    /**
     * Give a group a stable, unique label on an axis the first time it is seen.
     */
    private function register(string $axis, string $uuid, string $label): string
    {
        if (! isset($this->axes[$axis][$uuid])) {
            $this->axes[$axis][$uuid] = $this->planner->uniqueName($label, $this->usedLabels[$axis]);
        }

        return $uuid;
    }

    /**
     * @return list<array{filename: string, blob_ref: string}|array{filename: string, invalid: string}>
     */
    private function attachments(DOMElement $entry): array
    {
        $attachments = [];
        foreach ($this->children($entry, 'Binary') as $binary) {
            $filename = $this->text($binary, 'Key', trim: false) ?? '';
            $value = $this->child($binary, 'Value');
            $ref = $value?->hasAttribute('Ref') ? trim($value->getAttribute('Ref')) : null;

            if ($ref === null && $value !== null && trim($value->textContent) !== '') {
                // Older exports may carry the data inline instead of in the pool.
                $ref = 'inline-'.(++$this->inlineBlobs);
                $this->pool[$ref] = [
                    'data' => $value->textContent,
                    'compressed' => strcasecmp($value->getAttribute('Compressed'), 'True') === 0,
                ];
            }

            $error = $ref === null || ! isset($this->pool[$ref])
                ? KeepassBinaryDecoder::MISSING_REFERENCE
                : $this->decodeBlob($ref);

            if ($error !== null) {
                $this->warnings[self::WARNING_INVALID_ATTACHMENTS]++;
                $attachments[] = ['filename' => $filename, 'invalid' => $error];

                continue;
            }

            $attachments[] = ['filename' => $filename, 'blob_ref' => (string) $ref];
        }

        return $attachments;
    }

    /**
     * Decode a pooled binary once, counting it against the import's aggregate
     * attachment budget.
     *
     * @throws KeepassImportException
     */
    private function decodeBlob(string $ref): ?string
    {
        if (isset($this->blobs[$ref])) {
            return null;
        }
        if (isset($this->blobErrors[$ref])) {
            return $this->blobErrors[$ref];
        }

        $decoded = $this->decoder->decode($this->pool[$ref]['data'], $this->pool[$ref]['compressed'], $this->maxBlobBytes);
        if (isset($decoded['error'])) {
            return $this->blobErrors[$ref] = $decoded['error'];
        }

        $this->decodedBytes += strlen($decoded['bytes']);
        if ($this->decodedBytes > $this->maxTotalBytes) {
            throw new KeepassImportException(KeepassImportException::ATTACHMENTS_TOO_LARGE);
        }

        $this->blobs[$ref] = $decoded['bytes'];

        return null;
    }

    private function loadPool(?DOMElement $meta): void
    {
        foreach ($this->children($this->child($meta, 'Binaries'), 'Binary') as $binary) {
            $id = trim($binary->getAttribute('ID'));
            if ($id !== '' && ! isset($this->pool[$id])) {
                $this->pool[$id] = [
                    'data' => $binary->textContent,
                    'compressed' => strcasecmp($binary->getAttribute('Compressed'), 'True') === 0,
                ];
            }
        }
    }

    private function recycleBinUuid(?DOMElement $meta): ?string
    {
        $uuid = $this->text($meta, 'RecycleBinUUID');
        if ($uuid === null) {
            return null;
        }

        $raw = base64_decode($uuid, true);

        return is_string($raw) && trim($raw, "\0") === '' ? null : $uuid;
    }

    /**
     * Entries in a skipped group and all of its subgroups, without history.
     */
    private function countEntries(DOMElement $group): int
    {
        $count = 0;
        foreach ($group->childNodes as $node) {
            if ($node instanceof DOMElement && $node->nodeName === 'Entry') {
                $count++;
            } elseif ($node instanceof DOMElement && $node->nodeName === 'Group') {
                $count += $this->countEntries($node);
            }
        }

        return $count;
    }

    private function countHistory(DOMElement $entry): int
    {
        return count($this->children($this->child($entry, 'History'), 'Entry'));
    }

    /**
     * The expiry when `Times/Expires` is set, as a UTC timestamp. Accepts ISO
     * dates and base64 KDBX 4 timestamps (seconds since 0001-01-01).
     */
    private function expiry(DOMElement $entry): ?string
    {
        $times = $this->child($entry, 'Times');
        if (strcasecmp($this->text($times, 'Expires') ?? '', 'True') !== 0) {
            return null;
        }

        $value = $this->text($times, 'ExpiryTime') ?? '';
        $timestamp = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            try {
                $timestamp = CarbonImmutable::parse($value, 'UTC')->getTimestamp();
            } catch (Throwable) {
                return null;
            }
        } else {
            $raw = base64_decode($value, true);
            if (is_string($raw) && strlen($raw) === 8) {
                $seconds = unpack('P', $raw);
                $timestamp = is_array($seconds) ? (int) $seconds[1] - self::KDBX_EPOCH_OFFSET : null;
            }
        }

        if ($timestamp === null || $timestamp < self::MIN_EXPIRY || $timestamp > self::MAX_EXPIRY) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d H:i:s');
    }

    /**
     * @return list<string>
     */
    private function tags(string $tags): array
    {
        $names = [];
        foreach (preg_split('/[;,]/', $tags) ?: [] as $tag) {
            $tag = mb_substr(trim($tag), 0, self::MAX_TAG_NAME);
            if ($tag !== '') {
                $names[mb_strtolower($tag)] ??= $tag;
            }
        }

        return array_values($names);
    }

    private function child(?DOMElement $element, string $name): ?DOMElement
    {
        foreach ($element->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement && $node->nodeName === $name) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return list<DOMElement>
     */
    private function children(?DOMElement $element, string $name): array
    {
        $children = [];
        foreach ($element->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement && $node->nodeName === $name) {
                $children[] = $node;
            }
        }

        return $children;
    }

    private function text(?DOMElement $element, string $name, bool $trim = true): ?string
    {
        $child = $this->child($element, $name);
        if ($child === null) {
            return null;
        }

        $value = $trim ? trim($child->textContent) : $child->textContent;

        return $value === '' ? null : $value;
    }
}
