<?php

namespace App\Contracts;

use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportIr;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;

/**
 * One import source (sysPass, KeePass XML; CSV and 1Password later).
 *
 * A driver owns parsing and its source's declarations only. Planning, the
 * review UI, execution, the `import_runs` table, encrypted staging, and
 * reporting are shared and source-agnostic, so a later importer adds a driver
 * rather than a second workflow. Every driver exposes the same canonical
 * intermediate representation (see {@see ImportIr}).
 */
interface ImportDriver
{
    /**
     * The stable `source_format` value stored on the run.
     */
    public static function source(): string;

    /**
     * The human-readable source name shown in the import hub.
     */
    public function label(): string;

    /**
     * The request fields the upload needs (for example an export password).
     * They are request-scoped and never persisted; the driver validates which
     * of them are required.
     *
     * @return list<string>
     */
    public function stagingCredentialFields(): array;

    /**
     * The request fields the confirm step seals for the queued job (for
     * example a master password). An empty list still seals an envelope.
     *
     * @return list<string>
     */
    public function executionCredentialFields(): array;

    /**
     * The upload `accept` hint: file extensions and MIME types.
     *
     * @return list<string>
     */
    public function acceptedFileTypes(): array;

    /**
     * The groupings an entry can be placed by, in display order. An axis that
     * lists `excludes` cannot be used together with those axes.
     *
     * @return array<string, array{excludes?: list<string>}>
     */
    public function axes(): array;

    /**
     * The wizard's starting configuration for this source.
     *
     * @return array{layout: string, folder_axis: string, destinations: array<string, string>}
     */
    public function planDefaults(): array;

    /**
     * Resolves each entry's secret fields when it is written.
     */
    public function secretResolver(): EntrySecretResolver;

    /**
     * Parse and seal the upload into an `ImportRun` in the `staged` state.
     *
     * The parsed intermediate representation is sealed under a per-run import
     * key before it is written, and the plaintext export never touches the
     * database. Credentials are request-scoped and are never persisted.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function stage(UploadedFile $file, #[\SensitiveParameter] array $credentials, User $actor): ImportRun;

    /**
     * Unseal the staged source and map it to the canonical representation.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the staged object is missing, malformed,
     *                           or uses an unsupported schema version
     */
    public function openIr(ImportRun $run): array;

    /**
     * Non-secret structure counts for the review step.
     *
     * @return array<string, mixed>
     */
    public function preview(ImportRun $run): array;

    /**
     * The summary chips the review step shows, keyed into `preview().structure`.
     *
     * @return list<array{key: string, icon: Heroicon, color: string}>
     */
    public function previewStats(): array;
}
