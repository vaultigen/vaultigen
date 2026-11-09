<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\EntryRevision;
use App\Models\FileAttachment;
use App\Models\Tag;
use App\Models\User;
use App\Models\Vault;
use App\Services\Crypto\AttachmentCipher;
use App\Services\Crypto\EntryCipher;
use App\Services\Crypto\EnvelopeCipher;
use App\Services\Crypto\VaultKeyManager;
use App\Services\SiteIcons\SiteIcons;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Authorized entry reads and writes for a vault.
 *
 * Secrets live only inside the versioned encrypted envelope. Every write is
 * optimistic-locked, every update snapshots the previous state as an encrypted
 * revision, and every secret delivery is audited before the value is returned.
 * Folder and tag placement is a plaintext projection and is part of the
 * encrypted revision snapshot so restores reproduce it.
 */
final class EntryVault
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly VaultAccess $access,
        private readonly VaultKeyManager $keys,
        private readonly EntryCipher $cipher,
        private readonly EntryPayload $payloads,
        private readonly TotpGenerator $totp,
        private readonly VaultFolders $folders,
        private readonly VaultDirectory $directory,
        private readonly SecretReveals $reveals,
        private readonly EntryAttachments $attachments,
        private readonly AttachmentCipher $attachmentCipher,
        private readonly AttachmentQuota $quota,
        private readonly SiteIcons $siteIcons,
    ) {}

    /**
     * The metadata-only entry query for a vault, already permission-scoped to
     * the entries the user may see (vault, folder, or entry grants).
     *
     * @return Builder<Entry>
     */
    public function query(User $user, string $vaultId): Builder
    {
        $vault = $this->visibleVault($user, $vaultId);
        abort_unless($this->access->allows($user, $vault->id, 'metadata.view'), 404);

        return $this->access->visibleEntries($user, $vault->id);
    }

    public function find(User $user, string $entryId): Entry
    {
        $entry = Entry::query()->whereKey($entryId)->with(['vault', 'folder', 'tags'])->first();
        abort_if($entry === null || $entry->deleted_at !== null, 404);
        abort_unless($this->access->allowsOnEntry($user, $entry, 'metadata.view'), 404);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $options
     */
    public function create(User $actor, string $vaultId, array $input, array $options = []): string
    {
        $vault = $this->visibleVault($actor, $vaultId);
        $this->assertWritable($vault);
        $this->authorizeCreationPlacement($actor, $vault, $input, null);

        $type = is_string($input['type'] ?? null) ? (string) $input['type'] : EntryPayload::TYPE_LOGIN;
        $validated = $this->payloads->validate(
            $input,
            $type,
            isCreate: true,
            allowEmptySecrets: (bool) ($options['allow_empty_secrets'] ?? false),
        );
        $this->assertOwnerAllowed($vault, $validated['owner_user_id']);

        $entryId = (string) Str::uuid();
        $urlHost = null;

        $this->audit->transaction(function () use ($actor, $vault, $validated, $input, $entryId, &$urlHost): void {
            $placement = $this->placement($actor, $vault, $validated, $input, null);

            $payload = $this->payloads->compose(null, $validated);
            $projection = $this->payloads->projection(
                $payload,
                $validated['title'],
                $validated['owner_user_id'],
                $validated['expires_at'],
                $placement['folder_id'],
                $placement['tag_ids'],
            );
            $versionId = $this->keys->activeVersionId($vault->id);
            $sealed = $this->cipher->sealPayload($vault->id, $entryId, $versionId, $this->payloads->encode($payload));

            DB::table('entries')->insert([
                'id' => $entryId,
                'vault_id' => $vault->id,
                'folder_id' => $projection['folder_id'],
                'owner_user_id' => $projection['owner_user_id'],
                'type' => $projection['type'],
                'title' => $projection['title'],
                'normalized_title' => $projection['normalized_title'],
                'url_host' => $projection['url_host'],
                'vault_key_version_id' => $versionId,
                'cipher_suite' => EnvelopeCipher::ALGORITHM,
                'payload_format_version' => EnvelopeCipher::FORMAT_VERSION,
                'payload_schema_version' => EntryPayload::SCHEMA_VERSION,
                'payload_nonce' => $sealed->nonce,
                'encrypted_payload' => $sealed->envelope,
                'lock_version' => 1,
                'expires_at' => $projection['expires_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->syncTags($entryId, $vault->id, $projection['tag_ids']);
            $this->audit->record('entry.created', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'entry', 'target_id' => $entryId]);
            $urlHost = $projection['url_host'];
        });

        $this->siteIcons->request($urlHost);

        return $entryId;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, string $entryId, array $input, int $expectedLockVersion): void
    {
        $entry = $this->find($actor, $entryId);
        if (! $this->access->allowsOnEntry($actor, $entry, 'entry.update')) {
            throw new AuthorizationException(__('entries.errors.update_not_allowed'));
        }
        $vault = $this->writableVault((string) $entry->vault_id);

        $validated = $this->payloads->validate($input, (string) $entry->type, isCreate: false);
        $this->assertOwnerAllowed($vault, $validated['owner_user_id']);

        $urlHost = null;
        $this->audit->transaction(function () use ($actor, $entry, $vault, $validated, $input, $expectedLockVersion, &$urlHost): void {
            $current = $this->lockedEntryRow($entry->id, $expectedLockVersion);
            $versionId = (string) $current->vault_key_version_id;
            $existing = $this->payloads->decode($this->cipher->openPayload($vault->id, $entry->id, $versionId, (string) $current->encrypted_payload));

            $placement = $this->placement($actor, $vault, $validated, $input, $entry);
            if ($placement['folder_id'] !== $current->folder_id
                && ! $this->access->canCreateEntry($actor, $vault->id, $placement['folder_id'])) {
                throw new AuthorizationException(__('entries.errors.folder_placement_not_allowed'));
            }
            $this->assertMoveKeepsHistoryProtected($actor, $entry, $vault->id, $placement['folder_id']);

            $this->recordRevision($vault->id, $entry->id, $versionId, $existing, $this->currentProjection($current), $actor);

            $payload = $this->payloads->compose($existing, $validated);
            $projection = $this->payloads->projection(
                $payload,
                $validated['title'],
                $validated['owner_user_id'],
                $validated['expires_at'],
                $placement['folder_id'],
                $placement['tag_ids'],
            );
            $activeVersionId = $this->keys->activeVersionId($vault->id);
            $sealed = $this->cipher->sealPayload($vault->id, $entry->id, $activeVersionId, $this->payloads->encode($payload));

            DB::table('entries')->where('id', $entry->id)->where('vault_id', $vault->id)->update([
                'title' => $projection['title'],
                'normalized_title' => $projection['normalized_title'],
                'url_host' => $projection['url_host'],
                'owner_user_id' => $projection['owner_user_id'],
                'folder_id' => $projection['folder_id'],
                'expires_at' => $projection['expires_at'],
                'vault_key_version_id' => $activeVersionId,
                'cipher_suite' => EnvelopeCipher::ALGORITHM,
                'payload_format_version' => EnvelopeCipher::FORMAT_VERSION,
                'payload_schema_version' => EntryPayload::SCHEMA_VERSION,
                'payload_nonce' => $sealed->nonce,
                'encrypted_payload' => $sealed->envelope,
                'lock_version' => $expectedLockVersion + 1,
                'updated_at' => now(),
            ]);
            $this->syncTags($entry->id, $vault->id, $projection['tag_ids']);
            $this->audit->record('entry.updated', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'entry', 'target_id' => (string) $entry->id]);
            $urlHost = $projection['url_host'];
        });

        $this->siteIcons->request($urlHost);
    }

    /**
     * Move an entry to another folder in the same vault, or to another vault
     * entirely. A cross-vault move re-encrypts the payload and every revision
     * under the destination vault key, keeps installation-wide tag labels, and
     * drops vault-scoped tag labels, which cannot cross the vault boundary.
     */
    public function move(User $actor, string $entryId, string $destinationVaultId, ?string $destinationFolderId, ?string $newFolderName = null): void
    {
        $entry = $this->find($actor, $entryId);
        if (! $this->access->allowsOnEntry($actor, $entry, 'entry.move')) {
            throw new AuthorizationException(__('entries.errors.move_not_allowed'));
        }
        $source = $this->writableVault((string) $entry->vault_id);
        $destination = Vault::query()->findOrFail($destinationVaultId);
        $this->assertWritable($destination);

        $this->audit->transaction(function () use ($actor, $entry, $source, $destination, $destinationFolderId, $newFolderName): void {
            if ($newFolderName !== null) {
                $destinationFolderId = $this->folders->create($actor, $destination->id, $newFolderName);
            }
            $this->assertFolderInVault($destination->id, $destinationFolderId);
            if (! $this->access->canCreateEntry($actor, $destination->id, $destinationFolderId)) {
                throw new AuthorizationException(__('entries.errors.destination_placement_not_allowed'));
            }
            $this->assertMoveKeepsHistoryProtected($actor, $entry, $destination->id, $destinationFolderId);

            if ($source->id === $destination->id) {
                $this->moveWithinVault($actor, $entry, $source, $destinationFolderId);

                return;
            }

            $this->moveAcrossVaults($actor, $entry, $source, $destination, $destinationFolderId);
        });
    }

    /**
     * Deliver one requested field. Callers never receive the whole document.
     */
    public function reveal(User $user, string $entryId, string $field): string
    {
        $entry = $this->find($user, $entryId);
        $payload = $this->decode($entry);

        if (! $this->payloads->isRevealable($payload, $field)) {
            abort(404);
        }

        $value = $this->payloads->field($payload, $field);

        // Fail closed: the required delivery event and reveal marker are written
        // together before the value is returned.
        $this->trackDelivery($user, $entry);

        return $value;
    }

    /**
     * Deliver one concealed custom-field value by its position in the document.
     */
    public function revealCustom(User $user, string $entryId, int $index): string
    {
        $entry = $this->find($user, $entryId);
        $payload = $this->decode($entry);

        if (! $this->payloads->hasCustomField($payload, $index)) {
            abort(404);
        }

        $value = $this->payloads->customField($payload, $index);

        $this->trackDelivery($user, $entry);

        return $value;
    }

    /**
     * The custom fields prepared for the authorized detail view. Concealed
     * values are withheld and delivered only through revealCustom(); values the
     * owner marked visible are returned for inline, access-controlled display.
     *
     * @return list<array{index: int, label: string, concealed: bool, value: string|null}>
     */
    public function customFieldsForDisplay(Entry $entry): array
    {
        return $this->customFieldRows($entry, withValues: true);
    }

    /**
     * The custom-field labels and display choices for the edit form. Values are
     * never returned: the form treats a blank value as "keep the current one".
     *
     * @return list<array{index: int, label: string, concealed: bool, value: string|null}>
     */
    public function customFieldRowsForEdit(Entry $entry): array
    {
        return $this->customFieldRows($entry, withValues: false);
    }

    /**
     * @return list<array{index: int, label: string, concealed: bool, value: string|null}>
     */
    private function customFieldRows(Entry $entry, bool $withValues): array
    {
        $payload = $this->decode($entry);
        $fields = is_array($payload['custom_fields'] ?? null) ? array_values($payload['custom_fields']) : [];

        $rows = [];
        foreach ($fields as $index => $field) {
            $field = is_array($field) ? $field : [];

            $concealed = (bool) ($field['concealed'] ?? false);
            $rows[] = [
                'index' => (int) $index,
                'label' => is_string($field['label'] ?? null) ? $field['label'] : '',
                'concealed' => $concealed,
                'value' => $withValues && ! $concealed && is_string($field['value'] ?? null) ? $field['value'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Whether an entry carries a usable authenticator seed. Login entries may
     * hold an optional seed; TOTP entries always do.
     */
    public function totpAvailable(User $user, Entry $entry): bool
    {
        if (! in_array((string) $entry->type, [EntryPayload::TYPE_TOTP, EntryPayload::TYPE_LOGIN], true)) {
            return false;
        }

        return $this->hasTotpSeed($entry);
    }

    /**
     * Whether the entry currently stores a usable authenticator seed.
     */
    public function hasTotpSeed(Entry $entry): bool
    {
        $seed = $this->payloads->totpSeed($this->decode($entry));

        return $seed !== null && $this->totp->isValidSecret($seed);
    }

    /**
     * The current TOTP parameters for the edit form. TOTP configuration is not
     * a secret, so the form may prefill it to avoid silently resetting it.
     *
     * @return array{algorithm: string, digits: int, period: int}
     */
    public function totpParametersForEdit(Entry $entry): array
    {
        return $this->payloads->totpParameters($this->decode($entry));
    }

    /**
     * Generate the current authenticator code for an entry.
     *
     * @return array{code: string, seconds_remaining: int, period: int, algorithm: string, digits: int}
     */
    public function totpCode(User $user, string $entryId): array
    {
        $entry = $this->find($user, $entryId);
        $payload = $this->decode($entry);
        $seed = $this->payloads->totpSeed($payload);

        if ($seed === null || ! $this->totp->isValidSecret($seed)) {
            abort(404);
        }

        $parameters = $this->payloads->totpParameters($payload);
        $code = $this->totp->code($seed, $parameters['algorithm'], $parameters['digits'], $parameters['period']);

        $this->trackDelivery($user, $entry);

        return [
            'code' => $code,
            'seconds_remaining' => $this->totp->secondsRemaining($parameters['period']),
            'period' => $parameters['period'],
            'algorithm' => $parameters['algorithm'],
            'digits' => $parameters['digits'],
        ];
    }

    /**
     * @return Collection<int, EntryRevision>
     */
    public function history(User $user, string $entryId): Collection
    {
        $entry = $this->find($user, $entryId);
        if (! $this->access->allowsOnEntry($user, $entry, 'entry.history')) {
            throw new AuthorizationException(__('entries.errors.history_not_allowed'));
        }

        return EntryRevision::query()
            ->where('entry_id', $entry->id)
            ->with('changedBy')
            ->orderByDesc('revision_number')
            ->get();
    }

    public function restore(User $actor, string $entryId, string $revisionId): void
    {
        $entry = $this->find($actor, $entryId);
        if (! $this->access->allowsOnEntry($actor, $entry, 'entry.restore')) {
            throw new AuthorizationException(__('entries.errors.restore_not_allowed'));
        }
        $vault = $this->writableVault((string) $entry->vault_id);

        $urlHost = null;
        $this->audit->transaction(function () use ($actor, $entry, $vault, $revisionId, &$urlHost): void {
            $current = Entry::query()->findOrFail($entry->id);
            $revision = DB::table('entry_revisions')
                ->where('id', $revisionId)
                ->where('entry_id', $entry->id)
                ->where('vault_id', $vault->id)
                ->first();
            if ($revision === null) {
                abort(404);
            }

            $snapshot = $this->splitSnapshot($this->payloads->decodeDocument($this->cipher->openSnapshot(
                $vault->id,
                (string) $revision->id,
                (string) $revision->vault_key_version_id,
                (string) $revision->encrypted_snapshot,
            )));
            $this->payloads->assertSupported($snapshot['payload']);

            $currentVersionId = (string) $current->vault_key_version_id;
            $existing = $this->payloads->decode($this->cipher->openPayload($vault->id, $entry->id, $currentVersionId, (string) $current->encrypted_payload));
            $this->recordRevision($vault->id, $entry->id, $currentVersionId, $existing, $this->currentProjection($current), $actor);

            $projection = $snapshot['projection'];
            $placement = $this->restoredPlacement($actor, $vault->id, $current, $projection);
            $activeVersionId = $this->keys->activeVersionId($vault->id);
            $sealed = $this->cipher->sealPayload($vault->id, $entry->id, $activeVersionId, $this->payloads->encode($snapshot['payload']));

            DB::table('entries')->where('id', $entry->id)->where('vault_id', $vault->id)->update([
                'title' => (string) $projection['title'],
                'normalized_title' => Str::lower((string) $projection['title']),
                'url_host' => $projection['url_host'],
                'owner_user_id' => $this->restoredOwner($vault, $current, $projection),
                'folder_id' => $placement['folder_id'],
                'expires_at' => $projection['expires_at'],
                'vault_key_version_id' => $activeVersionId,
                'cipher_suite' => EnvelopeCipher::ALGORITHM,
                'payload_format_version' => EnvelopeCipher::FORMAT_VERSION,
                'payload_schema_version' => EntryPayload::SCHEMA_VERSION,
                'payload_nonce' => $sealed->nonce,
                'encrypted_payload' => $sealed->envelope,
                'lock_version' => ((int) $current->lock_version) + 1,
                'updated_at' => now(),
            ]);
            $this->syncTags($entry->id, $vault->id, $placement['tag_ids']);
            $attachmentIds = array_values(array_filter((array) ($projection['attachment_ids'] ?? []), 'is_string'));
            $this->attachments->restoreMembership($vault->id, $entry->id, $attachmentIds);
            $this->audit->record('entry.restored', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'entry', 'target_id' => (string) $entry->id]);
            $urlHost = is_string($projection['url_host'] ?? null) ? $projection['url_host'] : null;
        });

        $this->siteIcons->request($urlHost);
    }

    /**
     * Users who may own an entry: anyone with an active grant on the vault,
     * directly or through a team. This never exposes the installation directory.
     *
     * @return array<string, string>
     */
    public function ownerOptions(Vault $vault): array
    {
        $principalIds = DB::table('vault_grants')
            ->where('vault_id', $vault->id)
            ->whereNotNull('active_slot')
            ->whereNull('revoked_at')
            ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('principal_id')
            ->all();

        $userIds = DB::table('team_memberships')
            ->join('teams', 'teams.id', '=', 'team_memberships.team_id')
            ->whereIn('teams.principal_id', $principalIds)
            ->pluck('team_memberships.user_id')
            ->all();

        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($principalIds, $userIds): void {
                $query->whereIn('principal_id', $principalIds)->orWhereIn('id', $userIds);
            })
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [(string) $user->id => "{$user->name} ({$user->email})"])
            ->all();
    }

    /**
     * Revisions hold old secrets restricted to whoever holds `entry.history`. A
     * move must not hand them to someone without it: a cross-vault move carries
     * them to the destination's Managers, and a folder change may make the actor
     * a Manager of the entry.
     */
    private function assertMoveKeepsHistoryProtected(User $actor, Entry $entry, string $destinationVaultId, ?string $destinationFolderId): void
    {
        if ($this->access->allowsOnEntry($actor, $entry, 'entry.history')
            || DB::table('entry_revisions')->where('entry_id', $entry->id)->doesntExist()) {
            return;
        }

        $crossesVaults = (string) $entry->vault_id !== $destinationVaultId;
        $gainsHistory = $destinationFolderId !== null && $this->access->allowsOnFolder($actor, $destinationFolderId, 'entry.history');

        if ($crossesVaults || $gainsHistory) {
            throw new AuthorizationException(__('entries.errors.move_exposes_history'));
        }
    }

    private function moveWithinVault(User $actor, Entry $entry, Vault $vault, ?string $destinationFolderId): void
    {
        $current = Entry::query()->findOrFail($entry->id);
        $versionId = (string) $current->vault_key_version_id;
        $existing = $this->payloads->decode($this->cipher->openPayload($vault->id, $entry->id, $versionId, (string) $current->encrypted_payload));
        $this->recordRevision($vault->id, $entry->id, $versionId, $existing, $this->currentProjection($current), $actor);

        DB::table('entries')->where('id', $entry->id)->where('vault_id', $vault->id)->update([
            'folder_id' => $destinationFolderId,
            'lock_version' => ((int) $current->lock_version) + 1,
            'updated_at' => now(),
        ]);
        $this->audit->record('entry.moved', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'entry', 'target_id' => (string) $entry->id]);
    }

    private function moveAcrossVaults(User $actor, Entry $entry, Vault $source, Vault $destination, ?string $destinationFolderId): void
    {
        $current = Entry::query()->findOrFail($entry->id);
        $sourceVersionId = (string) $current->vault_key_version_id;
        $existing = $this->payloads->decode($this->cipher->openPayload($source->id, $entry->id, $sourceVersionId, (string) $current->encrypted_payload));

        $revisions = DB::table('entry_revisions')
            ->where('entry_id', $entry->id)
            ->orderBy('revision_number')
            ->get()
            ->map(fn (\stdClass $revision): array => [
                'id' => (string) $revision->id,
                'number' => (int) $revision->revision_number,
                'document' => $this->payloads->decodeDocument($this->cipher->openSnapshot(
                    $source->id,
                    (string) $revision->id,
                    (string) $revision->vault_key_version_id,
                    (string) $revision->encrypted_snapshot,
                )),
                'changed_by_user_id' => (string) $revision->changed_by_user_id,
                'created_at' => $revision->created_at,
            ])
            ->all();

        $destinationVersionId = $this->keys->activeVersionId($destination->id);
        $sealed = $this->cipher->sealPayload($destination->id, $entry->id, $destinationVersionId, $this->payloads->encode($existing));
        $preparedAttachments = $this->prepareAttachmentMove($entry->id, $source, $destination, $destinationVersionId);

        $globalTagIds = DB::table('entry_tag')
            ->join('tags', 'tags.id', '=', 'entry_tag.tag_id')
            ->where('entry_tag.entry_id', $entry->id)
            ->where('tags.scope', Tag::SCOPE_GLOBAL)
            ->pluck('entry_tag.tag_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        DB::table('entry_tag')->where('entry_id', $entry->id)->delete();
        DB::table('entry_grants')->where('entry_id', $entry->id)->delete();
        DB::table('entry_revisions')->where('entry_id', $entry->id)->delete();
        DB::table('file_attachments')->where('entry_id', $entry->id)->delete();

        DB::table('entries')->where('id', $entry->id)->where('vault_id', $source->id)->update([
            'vault_id' => $destination->id,
            'folder_id' => $destinationFolderId,
            'owner_user_id' => $actor->id,
            'vault_key_version_id' => $destinationVersionId,
            'cipher_suite' => EnvelopeCipher::ALGORITHM,
            'payload_format_version' => EnvelopeCipher::FORMAT_VERSION,
            'payload_schema_version' => EntryPayload::SCHEMA_VERSION,
            'payload_nonce' => $sealed->nonce,
            'encrypted_payload' => $sealed->envelope,
            'lock_version' => ((int) $current->lock_version) + 1,
            'updated_at' => now(),
        ]);

        foreach ($globalTagIds as $tagId) {
            DB::table('entry_tag')->insert([
                'vault_id' => $destination->id,
                'entry_id' => $entry->id,
                'tag_id' => $tagId,
            ]);
        }

        $attachmentIds = [];
        foreach ($preparedAttachments as $attachment) {
            $attachmentIds[] = $attachment['id'];
            DB::table('file_attachments')->insert(['vault_id' => $destination->id, ...$attachment]);
        }

        foreach ($revisions as $revision) {
            $sealedRevision = $this->cipher->sealSnapshot(
                $destination->id,
                (string) $revision['id'],
                $destinationVersionId,
                $this->payloads->encodeDocument($revision['document']),
            );
            DB::table('entry_revisions')->insert([
                'id' => (string) $revision['id'],
                'vault_id' => $destination->id,
                'entry_id' => $entry->id,
                'revision_number' => $revision['number'],
                'vault_key_version_id' => $destinationVersionId,
                'cipher_suite' => EnvelopeCipher::ALGORITHM,
                'payload_format_version' => EnvelopeCipher::FORMAT_VERSION,
                'payload_schema_version' => EntryPayload::SCHEMA_VERSION,
                'payload_nonce' => $sealedRevision->nonce,
                'encrypted_snapshot' => $sealedRevision->envelope,
                'changed_by_user_id' => $revision['changed_by_user_id'],
                'created_at' => $revision['created_at'],
            ]);
        }

        $nextNumber = $revisions === [] ? 1 : ((int) max(array_column($revisions, 'number')) + 1);
        $this->recordRevision($destination->id, $entry->id, $destinationVersionId, $existing, [
            'title' => (string) $current->title,
            'url_host' => $current->url_host,
            'owner_user_id' => (string) $current->owner_user_id,
            'expires_at' => $current->expires_at,
            'type' => (string) $current->type,
            'folder_id' => $current->folder_id,
            'tag_ids' => $globalTagIds,
            'attachment_ids' => $attachmentIds,
        ], $actor, $nextNumber);

        $this->audit->record('entry.moved', $actor->id, 'success', ['vault_id' => (string) $destination->id, 'target_type' => 'entry', 'target_id' => (string) $entry->id]);
    }

    /**
     * Prepare an entry's attachments for a cross-vault move: enforce the
     * destination quota, purge any already-trashed objects, and re-wrap every
     * live file key and metadata envelope under the destination vault key. The
     * ciphertext bytes are vault-independent and are never re-encrypted.
     *
     * @return list<array<string, mixed>>
     */
    private function prepareAttachmentMove(string $entryId, Vault $source, Vault $destination, string $destinationVersionId): array
    {
        $rows = DB::table('file_attachments')->where('entry_id', $entryId)->get();
        $liveBytes = (int) $rows->filter(fn (\stdClass $row): bool => $row->deleted_at === null)->sum('byte_size');

        if ($liveBytes > 0 && $this->quota->usedBytes($destination->id) + $liveBytes > $this->quota->maxBytesPerVault()) {
            throw ValidationException::withMessages(['attachment' => __('attachments.validation.vault_quota')]);
        }

        $deletedPaths = $rows
            ->filter(fn (\stdClass $row): bool => $row->deleted_at !== null)
            ->map(fn (\stdClass $row): string => (string) $row->random_storage_path)
            ->all();
        if ($deletedPaths !== []) {
            Storage::disk(EntryAttachments::DISK)->delete($deletedPaths);
        }

        $prepared = [];
        foreach ($rows as $row) {
            if ($row->deleted_at !== null) {
                continue;
            }

            $attachmentId = (string) $row->id;
            $sourceVersionId = (string) $row->vault_key_version_id;
            $metadata = $this->attachmentCipher->openMetadata($source->id, $attachmentId, $sourceVersionId, (string) $row->encrypted_metadata);
            $sealedMetadata = $this->attachmentCipher->sealMetadata($destination->id, $attachmentId, $destinationVersionId, $metadata);
            $fileKey = $this->attachmentCipher->unwrapFileKey($source->id, $attachmentId, $sourceVersionId, (string) $row->wrapped_file_key);
            $wrapped = $this->attachmentCipher->wrapFileKey($destination->id, $attachmentId, $destinationVersionId, $fileKey);

            $prepared[] = [
                'id' => $attachmentId,
                'entry_id' => $entryId,
                'vault_key_version_id' => $destinationVersionId,
                'encrypted_metadata' => $sealedMetadata->envelope,
                'byte_size' => (int) $row->byte_size,
                'random_storage_path' => (string) $row->random_storage_path,
                'stream_format_version' => (int) $row->stream_format_version,
                'wrapped_file_key_algorithm' => (string) $row->wrapped_file_key_algorithm,
                'wrapped_file_key_nonce' => $wrapped->nonce,
                'wrapped_file_key' => $wrapped->envelope,
                'ciphertext_checksum' => (string) $row->ciphertext_checksum,
                'keyed_plaintext_fingerprint' => $row->keyed_plaintext_fingerprint,
                'state' => FileAttachment::STATE_AVAILABLE,
                'created_by_user_id' => (string) $row->created_by_user_id,
                'created_at' => $row->created_at,
                'updated_at' => now(),
            ];
        }

        return $prepared;
    }

    /**
     * Authorize entry creation before any validation runs, so an unauthorized
     * actor receives a denial rather than a validation redirect.
     *
     * @param  array<string, mixed>  $input
     */
    private function authorizeCreationPlacement(User $actor, Vault $vault, array $input, ?Entry $existing): void
    {
        $newFolder = $input['new_folder_name'] ?? null;
        if (is_string($newFolder) && trim($newFolder) !== '') {
            if (! $this->access->allows($actor, $vault->id, 'folder.create')) {
                throw new AuthorizationException(__('entries.errors.folder_create_manager_only'));
            }

            return;
        }

        $folderId = $input['folder_id'] ?? $existing?->folder_id;
        $folderId = is_string($folderId) && $folderId !== '' ? $folderId : null;
        if (! $this->access->canCreateEntry($actor, $vault->id, $folderId)) {
            throw new AuthorizationException(__('entries.errors.create_in_folder_not_allowed'));
        }
    }

    /**
     * Resolve folder/tag placement, preserving existing placement when the
     * input omits those fields and creating an inline Manager-only folder.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $input
     * @return array{folder_id: string|null, tag_ids: list<string>}
     */
    private function placement(User $actor, Vault $vault, array $validated, array $input, ?Entry $existing): array
    {
        $folderId = array_key_exists('folder_id', $input)
            ? $validated['folder_id']
            : $existing?->folder_id;
        if ($validated['new_folder_name'] !== null) {
            $folderId = $this->folders->create($actor, $vault->id, $validated['new_folder_name']);
        }
        $this->assertFolderInVault($vault->id, $folderId);

        $tagIds = array_key_exists('tags', $input)
            ? $validated['tag_ids']
            : ($existing === null ? [] : $this->tagIdsFor($existing->id));

        return [
            'folder_id' => $folderId,
            'tag_ids' => $this->directory->validateTags($vault->id, $tagIds),
        ];
    }

    /**
     * The placement a restore reapplies. A restore always brings the content
     * back, but it moves the entry only where the actor may place entries;
     * otherwise the entry stays in its current folder.
     *
     * @param  array<string, mixed>  $projection
     * @return array{folder_id: string|null, tag_ids: list<string>}
     */
    private function restoredPlacement(User $actor, string $vaultId, Entry $current, array $projection): array
    {
        $folderId = is_string($projection['folder_id'] ?? null) ? $projection['folder_id'] : null;
        if ($folderId !== null && DB::table('folders')->where('vault_id', $vaultId)->where('id', $folderId)->whereNull('deleted_at')->doesntExist()) {
            $folderId = null;
        }
        if ($folderId !== $current->folder_id && ! $this->access->canCreateEntry($actor, $vaultId, $folderId)) {
            $folderId = $current->folder_id;
        }

        $tagIds = is_array($projection['tag_ids'] ?? null) ? array_values(array_filter($projection['tag_ids'], 'is_string')) : [];
        $tagIds = array_values(DB::table('tags')->whereIn('id', $tagIds)
            ->where(fn ($query) => $query->where('vault_id', $vaultId)->orWhereNull('vault_id'))
            ->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());

        return ['folder_id' => $folderId, 'tag_ids' => $tagIds];
    }

    /**
     * The owner a restore reapplies. A recorded owner who may no longer own
     * entries in the vault is not reinstated; the current owner stays.
     *
     * @param  array<string, mixed>  $projection
     */
    private function restoredOwner(Vault $vault, Entry $current, array $projection): string
    {
        $ownerId = (string) $projection['owner_user_id'];

        return array_key_exists($ownerId, $this->ownerOptions($vault)) ? $ownerId : (string) $current->owner_user_id;
    }

    /**
     * @return list<string>
     */
    private function tagIdsFor(string $entryId): array
    {
        return array_values(DB::table('entry_tag')->where('entry_id', $entryId)->pluck('tag_id')->map(fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * @param  list<string>  $tagIds
     */
    private function syncTags(string $entryId, string $vaultId, array $tagIds): void
    {
        DB::table('entry_tag')->where('entry_id', $entryId)->delete();
        foreach ($tagIds as $tagId) {
            DB::table('entry_tag')->insert([
                'vault_id' => $vaultId,
                'entry_id' => $entryId,
                'tag_id' => $tagId,
            ]);
        }
    }

    private function assertFolderInVault(string $vaultId, ?string $folderId): void
    {
        if ($folderId !== null && DB::table('folders')->where('vault_id', $vaultId)->where('id', $folderId)->whereNull('deleted_at')->doesntExist()) {
            throw ValidationException::withMessages(['folder_id' => __('entries.validation.folder_not_in_vault')]);
        }
    }

    private function visibleVault(User $user, string $vaultId): Vault
    {
        $vault = $this->access->visibleVaults($user)->find($vaultId);
        abort_if($vault === null, 404);

        return $vault;
    }

    private function writableVault(string $vaultId): Vault
    {
        $vault = Vault::query()->findOrFail($vaultId);
        $this->assertWritable($vault);

        return $vault;
    }

    private function assertWritable(Vault $vault): void
    {
        if ($vault->state !== 'active') {
            throw ValidationException::withMessages(['entry' => __('entries.validation.vault_not_active')]);
        }
    }

    private function lockedEntryRow(string $entryId, int $expectedLockVersion): Entry
    {
        $row = Entry::query()->findOrFail($entryId);
        if ($row->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages(['entry' => __('entries.validation.stale_entry')]);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Entry $entry): array
    {
        return $this->payloads->decode($this->cipher->openPayload(
            (string) $entry->vault_id,
            (string) $entry->id,
            (string) $entry->vault_key_version_id,
            (string) $entry->encrypted_payload,
        ));
    }

    /**
     * @return array{title: string, url_host: mixed, owner_user_id: string, expires_at: mixed, type: string, folder_id: string|null, tag_ids: list<string>, attachment_ids: list<string>}
     */
    private function currentProjection(Entry $entry): array
    {
        return [
            'title' => (string) $entry->title,
            'url_host' => $entry->url_host,
            'owner_user_id' => (string) $entry->owner_user_id,
            'expires_at' => $entry->expires_at,
            'type' => (string) $entry->type,
            'folder_id' => $entry->folder_id,
            'tag_ids' => $this->tagIdsFor((string) $entry->id),
            'attachment_ids' => $this->attachments->idsForEntry((string) $entry->id),
        ];
    }

    /**
     * Write the immutable delivery audit event and the per-user reveal marker in
     * one transaction, so a failed marker cannot silently drop the delivery.
     */
    private function trackDelivery(User $user, Entry $entry): void
    {
        $this->audit->transaction(function () use ($user, $entry): void {
            $this->audit->record('entry.secret_delivered', $user->id, 'success', ['vault_id' => (string) $entry->vault_id, 'target_type' => 'entry', 'target_id' => (string) $entry->id]);
            $this->reveals->record($user, (string) $entry->vault_id, (string) $entry->id);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $projection
     */
    private function recordRevision(string $vaultId, string $entryId, string $vaultKeyVersionId, array $payload, array $projection, User $actor, ?int $revisionNumber = null): void
    {
        $revisionId = (string) Str::uuid();
        $sealed = $this->cipher->sealSnapshot(
            $vaultId,
            $revisionId,
            $vaultKeyVersionId,
            $this->payloads->encodeDocument(['payload' => $payload, 'projection' => $projection]),
        );
        $next = $revisionNumber ?? (((int) DB::table('entry_revisions')->where('entry_id', $entryId)->max('revision_number')) + 1);

        DB::table('entry_revisions')->insert([
            'id' => $revisionId,
            'vault_id' => $vaultId,
            'entry_id' => $entryId,
            'revision_number' => $next,
            'vault_key_version_id' => $vaultKeyVersionId,
            'cipher_suite' => EnvelopeCipher::ALGORITHM,
            'payload_format_version' => EnvelopeCipher::FORMAT_VERSION,
            'payload_schema_version' => EntryPayload::SCHEMA_VERSION,
            'payload_nonce' => $sealed->nonce,
            'encrypted_snapshot' => $sealed->envelope,
            'changed_by_user_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{payload: array<string, mixed>, projection: array<string, mixed>}
     */
    private function splitSnapshot(array $snapshot): array
    {
        if (! is_array($snapshot['payload'] ?? null) || ! is_array($snapshot['projection'] ?? null)) {
            throw new RuntimeException('The entry revision snapshot is malformed.');
        }

        return ['payload' => $snapshot['payload'], 'projection' => $snapshot['projection']];
    }

    private function assertOwnerAllowed(Vault $vault, string $ownerId): void
    {
        if (! array_key_exists($ownerId, $this->ownerOptions($vault))) {
            throw ValidationException::withMessages(['owner_user_id' => __('entries.validation.owner_not_allowed')]);
        }
    }
}
