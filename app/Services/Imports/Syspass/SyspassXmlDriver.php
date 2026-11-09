<?php

namespace App\Services\Imports\Syspass;

use App\Contracts\EntrySecretResolver;
use App\Contracts\ImportDriver;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportIr;
use App\Services\Imports\ImportPlanner;
use App\Services\Imports\ImportStager;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The sysPass encrypted-XML import driver.
 *
 * Staging decrypts the export once with the export password and re-seals the
 * parsed sysPass representation under a per-run import key. That stored
 * format is unchanged from before the shared pipeline existed, so runs staged
 * earlier keep working; it is mapped to the canonical representation on read.
 * Account passwords stay encrypted until the runner writes each entry.
 */
final class SyspassXmlDriver implements ImportDriver
{
    public function __construct(
        private readonly ExportDecryptor $decryptor,
        private readonly SyspassXmlParser $parser,
        private readonly ImportStager $stager,
        private readonly SyspassSecretResolver $resolver,
    ) {}

    public static function source(): string
    {
        return 'syspass_xml';
    }

    public function label(): string
    {
        return (string) __('imports.sources.syspass_xml');
    }

    public function stagingCredentialFields(): array
    {
        return ['export_password'];
    }

    public function executionCredentialFields(): array
    {
        return ['master_password'];
    }

    public function acceptedFileTypes(): array
    {
        return ['.xml', 'text/xml', 'application/xml'];
    }

    public function axes(): array
    {
        return ['client' => [], 'category' => []];
    }

    public function planDefaults(): array
    {
        return [
            'layout' => ImportPlanner::SINGLE_VAULT,
            'folder_axis' => 'category',
            'destinations' => ['client' => 'tag', 'category' => 'folder'],
        ];
    }

    public function secretResolver(): EntrySecretResolver
    {
        return $this->resolver;
    }

    public function stage(UploadedFile $file, #[\SensitiveParameter] array $credentials, User $actor): ImportRun
    {
        $contents = $this->stager->read($file);
        $exportPassword = $credentials['export_password'] ?? '';
        $exportPassword = is_string($exportPassword) ? $exportPassword : '';
        if (trim($exportPassword) === '') {
            throw ValidationException::withMessages(['export_password' => __('imports.errors.credential_required')]);
        }

        try {
            $plainXml = $this->decryptor->decryptExport($contents, $exportPassword);
            $parsed = $this->parser->parse($plainXml);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => __('imports.errors.unreadable_export'),
            ]);
        }

        return $this->stager->stage(self::source(), $contents, $file, 'syspass-export.xml', $parsed, $actor);
    }

    public function openIr(ImportRun $run): array
    {
        return $this->canonicalIr($this->stager->open($run));
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(ImportRun $run): array
    {
        return [
            'source_format' => (string) $run->source_format,
            'original_filename' => $run->original_filename,
            'source_fingerprint' => (string) $run->source_fingerprint,
            'structure' => $this->structure($this->stager->open($run)),
        ];
    }

    public function previewStats(): array
    {
        return [
            ['key' => 'accounts', 'icon' => Heroicon::OutlinedKey, 'color' => 'primary'],
            ['key' => 'clients', 'icon' => Heroicon::OutlinedBuildingOffice2, 'color' => 'info'],
            ['key' => 'categories', 'icon' => Heroicon::OutlinedFolder, 'color' => 'warning'],
            ['key' => 'tags', 'icon' => Heroicon::OutlinedTag, 'color' => 'success'],
            ['key' => 'empty_passwords', 'icon' => Heroicon::OutlinedExclamationTriangle, 'color' => 'danger'],
        ];
    }

    /**
     * Non-secret counts of a stored sysPass representation.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public function structure(array $stored): array
    {
        $accounts = $this->accounts($stored);

        return [
            'meta' => is_array($stored['meta'] ?? null) ? $stored['meta'] : [],
            'clients' => is_array($stored['clients'] ?? null) ? count($stored['clients']) : 0,
            'categories' => is_array($stored['categories'] ?? null) ? count($stored['categories']) : 0,
            'tags' => is_array($stored['tags'] ?? null) ? count($stored['tags']) : 0,
            'accounts' => count($accounts),
            'empty_passwords' => count(array_filter($accounts, fn (array $account): bool => (string) ($account['pass'] ?? '') === '')),
        ];
    }

    /**
     * Map the stored sysPass representation to the canonical one.
     *
     * The stored format has no schema marker; one that carries a marker other
     * than the current version is refused rather than misread.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public function canonicalIr(array $stored): array
    {
        if (array_key_exists('schema', $stored) && $stored['schema'] !== ImportIr::SCHEMA_VERSION) {
            throw new RuntimeException('The staged import uses an unsupported schema version.');
        }

        $clients = $this->nameMap($stored['clients'] ?? null);
        $categories = $this->nameMap($stored['categories'] ?? null);
        $tagNames = $this->tagNameMap($stored['tags'] ?? null);

        $entries = [];
        foreach ($this->accounts($stored) as $account) {
            $clientId = is_string($account['client_id'] ?? null) ? (string) $account['client_id'] : '';
            $categoryId = is_string($account['category_id'] ?? null) ? (string) $account['category_id'] : '';

            $names = [];
            foreach ((array) ($account['tag_ids'] ?? []) as $id) {
                if (is_string($id) && isset($tagNames[$id])) {
                    $names[] = $tagNames[$id];
                }
            }

            $entries[] = [
                'source_id' => is_string($account['id'] ?? null) ? $account['id'] : null,
                'title' => is_string($account['name'] ?? null) ? $account['name'] : '',
                'username' => is_string($account['login'] ?? null) ? $account['login'] : null,
                'password' => '',
                'url' => is_string($account['url'] ?? null) ? $account['url'] : '',
                'notes' => is_string($account['notes'] ?? null) ? $account['notes'] : '',
                'totp' => null,
                'custom_fields' => [],
                'expires_at' => null,
                'tag_names' => $names,
                'axis_values' => ['client' => $clientId, 'category' => $categoryId],
                'attachments' => [],
                'context' => $clientId === '' ? '' : ($clients[$clientId] ?? ''),
                'secret_ref' => [
                    'pass' => is_string($account['pass'] ?? null) ? $account['pass'] : '',
                    'key' => is_string($account['key'] ?? null) ? $account['key'] : '',
                ],
            ];
        }

        return [
            'schema' => ImportIr::SCHEMA_VERSION,
            'meta' => is_array($stored['meta'] ?? null) ? $stored['meta'] : [],
            'warnings' => [],
            'axes' => ['client' => $clients, 'category' => $categories],
            'root_label' => null,
            'blobs' => [],
            'entries' => $entries,
        ];
    }

    /**
     * Source id => name for named client or category records. The first
     * record with an id wins; unnamed records have no label.
     *
     * @return array<string, string>
     */
    private function nameMap(mixed $records): array
    {
        $map = [];
        foreach (array_filter(is_array($records) ? $records : [], 'is_array') as $record) {
            $id = (string) ($record['id'] ?? '');
            if (! array_key_exists($id, $map)) {
                $map[$id] = trim((string) ($record['name'] ?? ''));
            }
        }

        return array_filter($map, fn (string $name): bool => $name !== '');
    }

    /**
     * @return array<string, string>
     */
    private function tagNameMap(mixed $tags): array
    {
        $map = [];
        foreach (is_array($tags) ? $tags : [] as $tag) {
            if (! is_array($tag)) {
                continue;
            }
            $id = $tag['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $map[$id] = mb_substr(trim((string) ($tag['name'] ?? '')), 0, ImportPlanner::MAX_TAG_NAME);
            }
        }

        return array_filter($map, fn (string $name): bool => $name !== '');
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return list<array<string, mixed>>
     */
    private function accounts(array $stored): array
    {
        $accounts = $stored['accounts'] ?? [];

        return is_array($accounts) ? array_values(array_filter($accounts, 'is_array')) : [];
    }
}
