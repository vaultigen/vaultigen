<?php

namespace App\Services\Imports;

use App\Contracts\EntrySecretResolver;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\EntryAttachments;
use App\Services\EntryPayload;
use App\Services\EntryVault;
use App\Services\Normalizer;
use App\Services\VaultAdministration;
use App\Services\VaultDirectory;
use App\Services\VaultFolders;
use App\Services\VaultMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The source-agnostic import runner.
 *
 * Every write goes through the ordinary domain services as the vault's selected
 * Manager, so encryption, revisions, grants, attachments, and audit are
 * identical to a manual write. Each vault, folder, tag, entry, and attachment
 * is its own committed unit: one failure is recorded and processing continues.
 * The driver's secret resolver checks the execution credentials before any
 * write and resolves each entry's secrets lazily as it is written, so progress
 * advances from the first entry and excluded vaults never pay that cost.
 *
 * @phpstan-import-type Counters from ImportResult
 * @phpstan-import-type Failure from ImportResult
 */
final class ImportRunner
{
    public const ERROR_PASSWORD = 'password_decrypt_failed';

    public const ERROR_TITLE = 'title_too_long';

    public const ERROR_URL = 'url_too_long';

    public const ERROR_NOTES = 'notes_too_long';

    public const ERROR_CUSTOM_FIELDS = 'too_many_custom_fields';

    public const ERROR_CUSTOM_FIELD = 'custom_field_too_long';

    public const ERROR_FOLDER = 'folder_name_invalid';

    public const ERROR_VAULT = 'vault_create_failed';

    public const ERROR_ATTACHMENT_MISSING = 'missing_reference';

    public const ERROR_ATTACHMENT_REJECTED = 'attachment_rejected';

    public const ERROR_ATTACHMENT_FAILED = 'attachment_failed';

    public const ERROR_UNKNOWN = 'unknown';

    public const KIND_ENTRY = 'entry';

    public const KIND_ATTACHMENT = 'attachment';

    public const MAX_FAILURES = 500;

    private const PROGRESS_INTERVAL = 25;

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly ImportRegistry $registry,
        private readonly ImportSourceStore $store,
        private readonly VaultAdministration $vaults,
        private readonly VaultFolders $folders,
        private readonly VaultDirectory $directory,
        private readonly VaultMembership $membership,
        private readonly EntryVault $entries,
        private readonly EntryAttachments $attachments,
        private readonly Normalizer $normalizer,
    ) {}

    /**
     * @param  array<string, string>  $credentials
     */
    public function run(ImportRun $run, #[\SensitiveParameter] array $credentials): ImportResult
    {
        $admin = User::query()->findOrFail($run->requested_by_user_id);
        $plan = is_array($run->plan) ? $run->plan : [];
        $driver = $this->registry->resolve((string) $run->source_format);

        $this->audit->record('import.started', $admin->id, 'success', [
            'sanitized_context' => ['import_run_id' => (string) $run->id],
        ]);
        $this->transition($run, ['status' => ImportRun::STATUS_IMPORTING, 'started_at' => now()]);

        try {
            $ir = $driver->openIr($run);
        } catch (Throwable) {
            return $this->fail($run, $admin, 'staging_unavailable');
        }

        $entries = ImportIr::entries($ir);
        $resolver = $driver->secretResolver();
        $assignments = is_array($plan['assignments'] ?? null) ? array_values($plan['assignments']) : [];
        $planVaults = is_array($plan['vaults'] ?? null) ? $plan['vaults'] : [];
        $planTags = is_array($plan['tags'] ?? null) ? array_values($plan['tags']) : [];
        $grants = is_array($plan['grants'] ?? null) ? array_values($plan['grants']) : [];
        $folderAxis = is_string($plan['axes']['folder_axis'] ?? null) ? (string) $plan['axes']['folder_axis'] : ImportPlanner::NONE;

        $failures = [];
        $failureCount = 0;

        // Check the credentials before writing anything, so a typo cannot
        // create a batch of empty vaults.
        $credentialError = $resolver->verify($entries, $credentials);
        if ($credentialError !== null) {
            return $this->fail($run, $admin, $credentialError);
        }

        $counters = ImportResult::emptyCounters();
        $createdVaultIds = [];

        $entriesByVault = [];
        $writeTotal = 0;
        foreach ($entries as $index => $entry) {
            $assignment = is_array($assignments[$index] ?? null) ? $assignments[$index] : [];
            if (($assignment['include'] ?? true) === false) {
                continue;
            }
            $key = (string) ($assignment['vault_key'] ?? '');
            $entriesByVault[$key][] = $index;
            $writeTotal++;
        }

        // Keep the progress denominator correct even when the runner is invoked
        // directly instead of through the controller that enqueues the run.
        $this->transition($run, ['progress_total' => $writeTotal]);

        $execute = function () use (
            $run, $admin, $ir, $entries, $resolver, $credentials, $assignments, $planVaults, $planTags, $grants, $folderAxis,
            $entriesByVault,
            &$counters, &$createdVaultIds, &$failures, &$failureCount
        ): void {
            $globalTags = $this->provisionGlobalTags($admin, $planTags, $counters);
            $localPlanned = $this->localTagPlan($planTags);

            foreach ($planVaults as $vaultKey => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['include'] ?? true) === false) {
                    // The admin excluded this vault, so neither the vault nor the
                    // entries destined for it are written.
                    continue;
                }
                $indices = $entriesByVault[$vaultKey] ?? [];
                $vaultId = $this->createVault($admin, $row);
                if ($vaultId === null) {
                    foreach ($indices as $index) {
                        $failureCount++;
                        $this->recordFailure($failures, $entries[$index], self::ERROR_VAULT);
                    }
                    $this->persistProgress($run, $counters, $createdVaultIds, $failures, $failureCount);

                    continue;
                }

                $createdVaultIds[] = $vaultId;
                $counters['vaults']++;
                $manager = User::query()->findOrFail((string) ($row['manager_user_id'] ?? $admin->id));
                $custodianPrincipalId = (string) DB::table('users')->where('id', (string) ($row['custodian_user_id'] ?? $admin->id))->value('principal_id');

                $this->applyGrants($manager, $vaultId, $grants, $custodianPrincipalId, $counters);
                $localTags = [];
                $folderIds = [];
                $folderNames = [];

                foreach ($indices as $processed => $index) {
                    $assignment = is_array($assignments[$index] ?? null) ? $assignments[$index] : [];
                    $this->importEntry(
                        $manager, $vaultId, $folderAxis, $folderIds, $folderNames,
                        $ir, $entries[$index], $assignment, $resolver, $credentials, $localPlanned, $localTags, $globalTags,
                        $counters, $failures, $failureCount,
                    );

                    if (($processed + 1) % self::PROGRESS_INTERVAL === 0) {
                        $this->persistProgress($run, $counters, $createdVaultIds, $failures, $failureCount);
                    }
                }

                $this->persistProgress($run, $counters, $createdVaultIds, $failures, $failureCount);
            }
        };

        try {
            $this->audit->withContext(['import_run_id' => (string) $run->id], $execute);
        } catch (ImportCancelledException) {
            return $this->cancelRun($run, $admin, $counters, $createdVaultIds, $failures, $failureCount);
        }

        $this->transition($run, [
            'status' => ImportRun::STATUS_COMPLETED,
            'completed_at' => now(),
            // A duplicate delivery can have marked the run failed before the
            // authoritative attempt finished; completion wins and clears it.
            'error_code' => null,
            'failed_at' => null,
            'cancel_requested_at' => null,
            'counters' => $counters,
            'created_vault_ids' => $createdVaultIds,
            'failures' => $failures,
            'failure_count' => $failureCount,
        ]);
        $this->store->purge($run->refresh());
        $this->audit->record('import.completed', $admin->id, 'success', [
            'sanitized_context' => [
                'import_run_id' => (string) $run->id,
                'vaults' => $counters['vaults'],
                'entries' => $counters['entries'],
                'failures' => $failureCount,
                'attachments' => $counters['attachments'],
                'attachment_failures' => $counters['attachment_failures'],
            ],
        ]);

        return ImportResult::completed($counters, $failures, $failureCount);
    }

    /**
     * @param  array<string, string>  $folderIds
     * @param  array<string, string>  $folderNames
     * @param  array<string, mixed>  $ir
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $assignment
     * @param  array<string, string>  $credentials
     * @param  array<string, string>  $localPlanned
     * @param  array<string, string>  $localTags
     * @param  array<string, string>  $globalTags
     * @param  Counters  $counters
     * @param  list<Failure>  $failures
     */
    private function importEntry(
        User $manager,
        string $vaultId,
        string $folderAxis,
        array &$folderIds,
        array &$folderNames,
        array $ir,
        array $entry,
        array $assignment,
        EntrySecretResolver $resolver,
        #[\SensitiveParameter] array $credentials,
        array $localPlanned,
        array &$localTags,
        array $globalTags,
        array &$counters,
        array &$failures,
        int &$failureCount,
    ): void {
        try {
            $secrets = $resolver->resolve($entry, $credentials);
        } catch (Throwable) {
            // A per-entry resolve failure must never write a blank password.
            $failureCount++;
            $this->recordFailure($failures, $entry, self::ERROR_PASSWORD);

            return;
        }

        $title = trim($this->string($entry, 'title'));
        if ($title === '') {
            $title = (string) __('imports.placeholders.untitled_entry');
        }

        $url = trim($this->string($entry, 'url'));
        $notes = trim($this->string($entry, 'notes'));
        $customFields = $this->customFields($entry);

        $error = match (true) {
            mb_strlen($title) > EntryPayload::MAX_TITLE => self::ERROR_TITLE,
            mb_strlen($url) > EntryPayload::MAX_URL => self::ERROR_URL,
            mb_strlen($notes) > EntryPayload::MAX_NOTES => self::ERROR_NOTES,
            count($customFields) > EntryPayload::MAX_CUSTOM_FIELDS => self::ERROR_CUSTOM_FIELDS,
            ! $this->customFieldsFit($customFields) => self::ERROR_CUSTOM_FIELD,
            default => null,
        };
        if ($error !== null) {
            $failureCount++;
            $this->recordFailure($failures, $entry, $error);

            return;
        }

        $folderId = $this->resolveFolder($manager, $vaultId, $folderAxis, $ir, $assignment, $folderIds, $folderNames, $counters);
        if ($folderId === false) {
            $failureCount++;
            $this->recordFailure($failures, $entry, self::ERROR_FOLDER);

            return;
        }

        $tagIds = $this->tagIdsFor($assignment, $globalTags, $localPlanned, $localTags, $manager, $vaultId, $counters);
        $totpSecret = is_string($entry['totp']['secret'] ?? null) ? (string) $entry['totp']['secret'] : null;

        try {
            $entryId = $this->entries->create($manager, $vaultId, [
                'title' => $title,
                'owner_user_id' => $manager->id,
                'type' => EntryPayload::TYPE_LOGIN,
                'username' => $entry['username'] ?? null,
                'password' => $secrets['password'],
                'url' => $url,
                'notes' => $notes,
                'totp_secret' => $totpSecret,
                'custom_fields' => $customFields,
                'expires_at' => is_string($entry['expires_at'] ?? null) ? $entry['expires_at'] : null,
                'folder_id' => $folderId,
                'tags' => $tagIds,
            ], ['allow_empty_secrets' => true]);
            $counters['entries']++;
        } catch (Throwable) {
            $failureCount++;
            $this->recordFailure($failures, $entry, self::ERROR_UNKNOWN);

            return;
        }

        $this->importAttachments($manager, $entryId, $ir, $entry, $counters, $failures);
    }

    /**
     * Write an entry's attachments after the entry exists. A failed file keeps
     * the entry and is recorded as an attachment failure, never as a failed
     * entry.
     *
     * @param  array<string, mixed>  $ir
     * @param  array<string, mixed>  $entry
     * @param  Counters  $counters
     * @param  list<Failure>  $failures
     */
    private function importAttachments(User $manager, string $entryId, array $ir, array $entry, array &$counters, array &$failures): void
    {
        $attachments = is_array($entry['attachments'] ?? null) ? array_values(array_filter($entry['attachments'], 'is_array')) : [];
        $total = count($attachments);

        foreach ($attachments as $position => $attachment) {
            $error = is_string($attachment['invalid'] ?? null) ? (string) $attachment['invalid'] : null;
            $bytes = null;
            if ($error === null) {
                $ref = $attachment['blob_ref'] ?? null;
                $encoded = is_string($ref) || is_int($ref) ? ($ir['blobs'][(string) $ref] ?? null) : null;
                $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
                if (! is_string($bytes)) {
                    $error = self::ERROR_ATTACHMENT_MISSING;
                }
            }

            if ($error === null && is_string($bytes)) {
                $error = $this->writeAttachment($manager, $entryId, $bytes, $this->string($attachment, 'filename'));
            }

            if ($error === null) {
                $counters['attachments']++;

                continue;
            }

            $counters['attachment_failures']++;
            $this->recordFailure($failures, $entry, $error, [
                'kind' => self::KIND_ATTACHMENT,
                'entry_id' => $entryId,
                'index' => $position + 1,
                'of' => $total,
            ]);
        }
    }

    /**
     * Stream one attachment's bytes through the ordinary attachment writer.
     * The plaintext lives only in a memory stream.
     *
     * @return string|null an error code, or null when the file was stored
     */
    private function writeAttachment(User $manager, string $entryId, #[\SensitiveParameter] string $bytes, string $filename): ?string
    {
        $stream = null;

        try {
            $stream = fopen('php://memory', 'w+b') ?: throw new RuntimeException('The attachment buffer could not be opened.');
            fwrite($stream, $bytes);
            rewind($stream);
            $this->attachments->store($manager, $entryId, $stream, strlen($bytes), $filename);

            return null;
        } catch (ValidationException) {
            return self::ERROR_ATTACHMENT_REJECTED;
        } catch (Throwable) {
            return self::ERROR_ATTACHMENT_FAILED;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array{label: string, value: string, concealed: bool}>
     */
    private function customFields(array $entry): array
    {
        $fields = [];
        foreach (array_filter((array) ($entry['custom_fields'] ?? []), 'is_array') as $field) {
            $fields[] = [
                'label' => trim($this->string($field, 'label')),
                'value' => $this->string($field, 'value'),
                'concealed' => (bool) ($field['concealed'] ?? false),
            ];
        }

        return $fields;
    }

    /**
     * @param  list<array{label: string, value: string, concealed: bool}>  $fields
     */
    private function customFieldsFit(array $fields): bool
    {
        foreach ($fields as $field) {
            if (mb_strlen($field['label']) > EntryPayload::MAX_CUSTOM_LABEL || mb_strlen($field['value']) > EntryPayload::MAX_CUSTOM_VALUE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve or lazily create the destination folder. A null source means the
     * vault root. Returns false when the folder could not be created. Source
     * values whose labels normalize to the same name share one folder.
     *
     * @param  array<string, mixed>  $ir
     * @param  array<string, mixed>  $assignment
     * @param  array<string, string>  $folderIds
     * @param  array<string, string>  $folderNames
     * @param  Counters  $counters
     */
    private function resolveFolder(
        User $manager,
        string $vaultId,
        string $folderAxis,
        array $ir,
        array $assignment,
        array &$folderIds,
        array &$folderNames,
        array &$counters,
    ): string|false|null {
        $source = $assignment['folder_source'] ?? null;
        if ($folderAxis === ImportPlanner::NONE || ! is_string($source) || $source === '') {
            return null;
        }
        if (array_key_exists($source, $folderIds)) {
            return $folderIds[$source];
        }

        $name = ImportIr::axisLabel($ir, $folderAxis, $source) ?? (string) __('imports.placeholders.untitled');
        $name = mb_substr($name, 0, VaultFolders::MAX_NAME);
        $normalized = $this->normalizer->normalize($name);
        if (isset($folderNames[$normalized])) {
            return $folderIds[$source] = $folderNames[$normalized];
        }

        try {
            $folderId = $this->folders->create($manager, $vaultId, $name);
            $counters['folders']++;
        } catch (Throwable) {
            return false;
        }

        return $folderIds[$source] = $folderNames[$normalized] = $folderId;
    }

    /**
     * Resolve the tag ids for one entry, creating a vault-local tag the first
     * time an entry in that vault needs it.
     *
     * @param  array<string, mixed>  $assignment
     * @param  array<string, string>  $globalTags
     * @param  array<string, string>  $localPlanned
     * @param  array<string, string>  $localTags
     * @param  Counters  $counters
     * @return list<string>
     */
    private function tagIdsFor(array $assignment, array $globalTags, array $localPlanned, array &$localTags, User $manager, string $vaultId, array &$counters): array
    {
        $names = is_array($assignment['tag_names'] ?? null) ? $assignment['tag_names'] : [];
        $ids = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $normalized = $this->normalizer->normalize($name);
            if (isset($globalTags[$normalized])) {
                $ids[] = $globalTags[$normalized];

                continue;
            }
            if (isset($localTags[$normalized])) {
                $ids[] = $localTags[$normalized];

                continue;
            }
            if (! isset($localPlanned[$normalized])) {
                continue;
            }

            try {
                $localTags[$normalized] = $this->directory->createTag($manager, $vaultId, ['name' => $localPlanned[$normalized]]);
                $counters['tags']++;
                $ids[] = $localTags[$normalized];
            } catch (Throwable) {
                // Skip a tag that cannot be created; the entry imports without it.
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createVault(User $admin, array $row): ?string
    {
        try {
            return $this->vaults->create($admin, [
                'name' => (string) ($row['name'] ?? __('imports.placeholders.untitled')),
                'description' => null,
                'icon' => null,
                'primary_custodian_user_id' => (string) ($row['custodian_user_id'] ?? $admin->id),
                'initial_manager_user_id' => (string) ($row['manager_user_id'] ?? $admin->id),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $grants
     * @param  Counters  $counters
     */
    private function applyGrants(User $manager, string $vaultId, array $grants, string $custodianPrincipalId, array &$counters): void
    {
        foreach ($grants as $grant) {
            $principalId = (string) ($grant['principal_id'] ?? '');
            if ($principalId === '' || $principalId === (string) $manager->principal_id || $principalId === $custodianPrincipalId) {
                continue;
            }

            try {
                $this->membership->grant($manager, $vaultId, $principalId, (string) ($grant['permission'] ?? 'viewer'));
                $counters['grants']++;
            } catch (Throwable) {
                // A single unsupported grant must not stop the vault.
            }
        }
    }

    /**
     * Create or reuse every installation-wide imported tag.
     *
     * @param  list<array<string, mixed>>  $planTags
     * @param  Counters  $counters
     * @return array<string, string> normalized name => tag id
     */
    private function provisionGlobalTags(User $admin, array $planTags, array &$counters): array
    {
        $ids = [];
        foreach ($planTags as $tag) {
            if (($tag['scope'] ?? null) !== 'global' || ! is_string($tag['name'] ?? null)) {
                continue;
            }
            if (($tag['include'] ?? true) === false) {
                continue;
            }
            $normalized = $this->normalizer->normalize((string) $tag['name']);
            if ($normalized === '') {
                continue;
            }

            $existing = DB::table('tags')->where('scope', 'global')->where('normalized_name', $normalized)->value('id');
            if ($existing !== null) {
                $ids[$normalized] = (string) $existing;

                continue;
            }

            try {
                $ids[$normalized] = $this->directory->createTag($admin, null, ['name' => (string) $tag['name']]);
                $counters['tags']++;
            } catch (Throwable) {
                // Skip a tag that cannot be created; entries import without it.
            }
        }

        return $ids;
    }

    /**
     * The vault-local import tags, keyed by normalized name, created lazily in
     * the vaults that actually use them.
     *
     * @param  list<array<string, mixed>>  $planTags
     * @return array<string, string> normalized name => display name
     */
    private function localTagPlan(array $planTags): array
    {
        $planned = [];
        foreach ($planTags as $tag) {
            if (($tag['scope'] ?? null) !== 'local' || ! is_string($tag['name'] ?? null)) {
                continue;
            }
            if (($tag['include'] ?? true) === false) {
                continue;
            }
            $normalized = $this->normalizer->normalize((string) $tag['name']);
            if ($normalized !== '') {
                $planned[$normalized] = (string) $tag['name'];
            }
        }

        return $planned;
    }

    /**
     * @param  Counters  $counters
     * @param  list<string>  $createdVaultIds
     * @param  list<Failure>  $failures
     */
    private function persistProgress(ImportRun $run, array $counters, array $createdVaultIds, array $failures, int $failureCount): void
    {
        $this->assertNotCancelled($run);
        $this->transition($run, [
            'counters' => $counters,
            'created_vault_ids' => $createdVaultIds,
            'failures' => $failures,
            'failure_count' => $failureCount,
        ]);
    }

    private function fail(ImportRun $run, User $admin, string $errorCode): ImportResult
    {
        $this->transition($run, ['status' => ImportRun::STATUS_FAILED, 'failed_at' => now(), 'error_code' => $errorCode]);
        $this->store->purge($run->refresh());
        $this->audit->record('import.failed', $admin->id, 'failed', [
            'sanitized_context' => ['import_run_id' => (string) $run->id, 'error_code' => $errorCode],
        ]);

        return ImportResult::failed($errorCode);
    }

    /**
     * A cancellation request arrived mid-run: stop, record what was committed,
     * and purge the staged source. Created vaults and entries are left intact.
     *
     * @param  Counters  $counters
     * @param  list<string>  $createdVaultIds
     * @param  list<Failure>  $failures
     */
    private function cancelRun(ImportRun $run, User $admin, array $counters, array $createdVaultIds, array $failures, int $failureCount): ImportResult
    {
        $this->transition($run, [
            'status' => ImportRun::STATUS_CANCELLED,
            'counters' => $counters,
            'created_vault_ids' => $createdVaultIds,
            'failures' => $failures,
            'failure_count' => $failureCount,
        ]);
        $this->store->purge($run->refresh());
        $this->audit->record('import.cancelled', $admin->id, 'success', [
            'sanitized_context' => ['import_run_id' => (string) $run->id],
        ]);

        return ImportResult::cancelled($counters, $failures, $failureCount);
    }

    /**
     * Check the cancellation flag between progress checkpoints.
     */
    private function assertNotCancelled(ImportRun $run): void
    {
        $fresh = ImportRun::query()->find($run->id);

        if ($fresh === null || $fresh->cancel_requested_at !== null || $fresh->status === ImportRun::STATUS_CANCELLED) {
            throw new ImportCancelledException;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(ImportRun $run, array $attributes): void
    {
        $run->forceFill([...$attributes, 'updated_at' => now()])->save();
    }

    /**
     * Record a non-secret failure. The context label lets an admin tell apart
     * identically titled entries; attachment failures carry the entry id and
     * the file's position instead of its (encrypted) filename.
     *
     * @param  list<Failure>  $failures
     * @param  array<string, mixed>  $entry
     * @param  array{kind?: string, entry_id?: string, index?: int, of?: int}  $extra
     */
    private function recordFailure(array &$failures, array $entry, string $errorCode, array $extra = []): void
    {
        if (count($failures) >= self::MAX_FAILURES) {
            return;
        }

        $failures[] = [
            'kind' => self::KIND_ENTRY,
            'source_id' => is_string($entry['source_id'] ?? null) ? $entry['source_id'] : null,
            'title' => $this->safeTitle($entry),
            'context' => mb_substr($this->string($entry, 'context'), 0, ImportPlanner::MAX_NAME),
            'error_code' => $errorCode,
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function safeTitle(array $entry): string
    {
        $title = trim($this->string($entry, 'title'));
        if ($title === '') {
            $title = (string) __('imports.placeholders.untitled_entry');
        }

        return mb_substr($title, 0, EntryPayload::MAX_TITLE);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
