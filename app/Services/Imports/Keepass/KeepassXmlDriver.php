<?php

namespace App\Services\Imports\Keepass;

use App\Contracts\EntrySecretResolver;
use App\Contracts\ImportDriver;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\AttachmentQuota;
use App\Services\Imports\ImportIr;
use App\Services\Imports\ImportPlanner;
use App\Services\Imports\ImportStager;
use App\Services\Imports\PassThroughSecretResolver;
use App\Services\Imports\SafeXml;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The KeePass 2.x / KeePassXC XML export driver.
 *
 * The export is plaintext, so staging needs no credential and the run needs
 * none either: the parsed canonical representation, including decoded
 * attachment bytes, is sealed under a per-run import key right away. Native
 * `.kdbx` files and the legacy KeePassX 0.4 format are not supported.
 */
final class KeepassXmlDriver implements ImportDriver
{
    public function __construct(
        private readonly KeepassXmlParser $parser,
        private readonly ImportStager $stager,
        private readonly AttachmentQuota $quota,
        private readonly PassThroughSecretResolver $resolver,
    ) {}

    public static function source(): string
    {
        return 'keepass_xml';
    }

    public function label(): string
    {
        return (string) __('imports.sources.keepass_xml');
    }

    public function stagingCredentialFields(): array
    {
        return [];
    }

    public function executionCredentialFields(): array
    {
        return [];
    }

    public function acceptedFileTypes(): array
    {
        return ['.xml', 'text/xml', 'application/xml'];
    }

    public function axes(): array
    {
        return [
            KeepassXmlParser::AXIS_TOP_GROUP => [],
            KeepassXmlParser::AXIS_SUBGROUP => [],
            KeepassXmlParser::AXIS_GROUP_PATH => ['excludes' => [KeepassXmlParser::AXIS_TOP_GROUP, KeepassXmlParser::AXIS_SUBGROUP]],
        ];
    }

    public function planDefaults(): array
    {
        return [
            'layout' => ImportPlanner::SINGLE_VAULT,
            'folder_axis' => KeepassXmlParser::AXIS_GROUP_PATH,
            'destinations' => [
                KeepassXmlParser::AXIS_TOP_GROUP => 'skip',
                KeepassXmlParser::AXIS_SUBGROUP => 'skip',
                KeepassXmlParser::AXIS_GROUP_PATH => 'folder',
            ],
        ];
    }

    public function secretResolver(): EntrySecretResolver
    {
        return $this->resolver;
    }

    public function stage(UploadedFile $file, #[\SensitiveParameter] array $credentials, User $actor): ImportRun
    {
        $contents = $this->stager->read($file);

        try {
            $ir = $this->parser->parse($contents, $this->quota->maxBytesPerFile(), $this->maxAttachmentBytes());
        } catch (KeepassImportException $exception) {
            throw ValidationException::withMessages(['file' => __('imports.errors.'.$exception->reason)]);
        }

        return $this->stager->stage(self::source(), $contents, $file, 'keepass-export.xml', $ir, $actor);
    }

    public function openIr(ImportRun $run): array
    {
        $ir = $this->stager->open($run);
        ImportIr::assertSupported($ir);

        return $ir;
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(ImportRun $run): array
    {
        $ir = $this->openIr($run);
        $entries = ImportIr::entries($ir);
        $warnings = is_array($ir['warnings'] ?? null) ? $ir['warnings'] : [];

        return [
            'source_format' => (string) $run->source_format,
            'original_filename' => $run->original_filename,
            'source_fingerprint' => (string) $run->source_fingerprint,
            'structure' => [
                'meta' => is_array($ir['meta'] ?? null) ? $ir['meta'] : [],
                'entries' => count($entries),
                'groups' => (int) ($ir['meta']['groups'] ?? 0),
                'attachments' => array_sum(array_map(fn (array $entry): int => count((array) ($entry['attachments'] ?? [])), $entries)),
                'totp' => count(array_filter($entries, fn (array $entry): bool => is_array($entry['totp'] ?? null))),
                'recycle_bin' => (int) ($warnings[KeepassXmlParser::WARNING_RECYCLE_BIN] ?? 0),
                'history' => (int) ($warnings[KeepassXmlParser::WARNING_HISTORY] ?? 0),
                'totp_unsupported' => (int) ($warnings[KeepassXmlParser::WARNING_TOTP_UNSUPPORTED] ?? 0),
                'field_references' => (int) ($warnings[KeepassXmlParser::WARNING_FIELD_REFERENCES] ?? 0),
                'invalid_attachments' => (int) ($warnings[KeepassXmlParser::WARNING_INVALID_ATTACHMENTS] ?? 0),
            ],
        ];
    }

    public function previewStats(): array
    {
        return [
            ['key' => 'entries', 'icon' => Heroicon::OutlinedKey, 'color' => 'primary'],
            ['key' => 'groups', 'icon' => Heroicon::OutlinedFolder, 'color' => 'warning'],
            ['key' => 'attachments', 'icon' => Heroicon::OutlinedPaperClip, 'color' => 'info'],
            ['key' => 'totp', 'icon' => Heroicon::OutlinedClock, 'color' => 'success'],
            ['key' => 'recycle_bin', 'icon' => Heroicon::OutlinedTrash, 'color' => 'gray'],
            ['key' => 'history', 'icon' => Heroicon::OutlinedArchiveBox, 'color' => 'gray'],
            ['key' => 'totp_unsupported', 'icon' => Heroicon::OutlinedExclamationTriangle, 'color' => 'danger'],
            ['key' => 'field_references', 'icon' => Heroicon::OutlinedLink, 'color' => 'danger'],
            ['key' => 'invalid_attachments', 'icon' => Heroicon::OutlinedExclamationTriangle, 'color' => 'danger'],
        ];
    }

    /**
     * The aggregate cap on decoded attachment bytes for one import.
     */
    private function maxAttachmentBytes(): int
    {
        return max(1, (int) config('vaultigen.imports.max_attachment_bytes', SafeXml::MAX_BYTES));
    }
}
