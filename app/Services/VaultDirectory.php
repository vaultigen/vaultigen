<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The tag directory: installation-wide tags and vault-scoped tags.
 *
 * A global tag (`vault_id = null`) is managed by a Super Admin and may be
 * attached to an entry in any vault. A vault tag belongs to exactly one vault,
 * is managed by that vault's Editor or Manager, and can never leave the vault.
 * Both kinds may be deleted; deletion detaches the tag from every entry.
 */
final class VaultDirectory
{
    public const MAX_TAG_NAME = 60;

    public const COLORS = ['gray', 'primary', 'info', 'success', 'warning', 'danger'];

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly VaultAccess $access,
        private readonly Normalizer $normalizer,
    ) {}

    /**
     * Create a tag. A null vault id creates an installation-wide tag; a vault id
     * creates a vault-scoped tag.
     *
     * @param  array<string, mixed>  $data
     */
    public function createTag(User $actor, ?string $vaultId, array $data): string
    {
        if ($vaultId === null) {
            $this->assertGlobalManager($actor);
            $scope = Tag::SCOPE_GLOBAL;
        } else {
            $this->assertEditor($actor, $vaultId);
            $this->assertVaultActive($vaultId);
            $scope = Tag::SCOPE_VAULT;
        }

        $fields = $this->validateTag($data);
        if ($scope === Tag::SCOPE_GLOBAL) {
            $this->assertGlobalUnique($fields['normalized_name']);
        } else {
            $this->assertLocalUnique($vaultId, $fields['normalized_name']);
            $this->assertNoGlobalShadow($fields['normalized_name']);
        }

        $tagId = (string) Str::uuid();
        $this->audit->transaction(function () use ($actor, $vaultId, $scope, $tagId, $fields): void {
            DB::table('tags')->insert([
                'id' => $tagId,
                'vault_id' => $vaultId,
                'scope' => $scope,
                'global_key' => $scope === Tag::SCOPE_GLOBAL ? $fields['normalized_name'] : null,
                'name' => $fields['name'],
                'normalized_name' => $fields['normalized_name'],
                'color' => $fields['color'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('tag.created', $actor->id);
        });

        return $tagId;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTag(User $actor, string $tagId, array $data): void
    {
        $tag = Tag::query()->find($tagId);
        abort_if($tag === null, 404);

        if ($tag->isGlobal()) {
            $this->assertGlobalManager($actor);
        } else {
            $this->assertEditor($actor, (string) $tag->vault_id);
            $this->assertVaultActive((string) $tag->vault_id);
        }

        $fields = $this->validateTag($data);
        if ($tag->isGlobal()) {
            $this->assertGlobalUnique($fields['normalized_name'], $tagId);
        } else {
            $this->assertLocalUnique((string) $tag->vault_id, $fields['normalized_name'], $tagId);
            $this->assertNoGlobalShadow($fields['normalized_name']);
        }

        $this->audit->transaction(function () use ($actor, $tag, $fields): void {
            DB::table('tags')->where('id', $tag->id)->update([
                'name' => $fields['name'],
                'normalized_name' => $fields['normalized_name'],
                'global_key' => $tag->isGlobal() ? $fields['normalized_name'] : null,
                'color' => $fields['color'],
                'updated_at' => now(),
            ]);
            $this->audit->record('tag.updated', $actor->id);
        });
    }

    /**
     * Delete a tag and detach it from every entry. A global tag needs a Super
     * Admin; a vault tag needs that vault's Editor or Manager.
     */
    public function deleteTag(User $actor, string $tagId): void
    {
        $tag = Tag::query()->find($tagId);
        abort_if($tag === null, 404);

        if ($tag->isGlobal()) {
            $this->assertGlobalManager($actor);
        } else {
            $this->assertEditor($actor, (string) $tag->vault_id);
            $this->assertVaultActive((string) $tag->vault_id);
        }

        $this->audit->transaction(function () use ($actor, $tag): void {
            DB::table('entry_tag')->where('tag_id', $tag->id)->delete();
            DB::table('tags')->where('id', $tag->id)->delete();
            $this->audit->record('tag.deleted', $actor->id);
        });
    }

    /**
     * How many live entries carry the tag, and in which vaults. Drives the
     * delete warning before a tag is removed.
     *
     * @return array{entries: int, vaults: list<array{id: string, name: string}>}
     */
    public function usage(string $tagId): array
    {
        $base = fn () => DB::table('entry_tag')
            ->join('entries', 'entries.id', '=', 'entry_tag.entry_id')
            ->where('entry_tag.tag_id', $tagId)
            ->whereNull('entries.deleted_at');

        $entries = $base()->count();

        $vaults = array_values($base()
            ->join('vaults', 'vaults.id', '=', 'entry_tag.vault_id')
            ->distinct()
            ->orderBy('vaults.name')
            ->get(['vaults.id', 'vaults.name'])
            ->map(fn (\stdClass $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name])
            ->all());

        return ['entries' => $entries, 'vaults' => $vaults];
    }

    /**
     * The labels a user may choose from in a vault: every installation-wide tag
     * plus that vault's own tags. Only vault-scope actors see the directory, so
     * folder-scoped users never browse unrelated metadata.
     *
     * @return array<string, string>
     */
    public function tagOptions(User $user, string $vaultId): array
    {
        if (! $this->access->hasVaultScope($user, $vaultId)) {
            return [];
        }

        $globals = DB::table('tags')->where('scope', Tag::SCOPE_GLOBAL)
            ->orderBy('name')->pluck('name', 'id')->all();
        $locals = DB::table('tags')->where('scope', Tag::SCOPE_VAULT)
            ->where('vault_id', $vaultId)->orderBy('name')->pluck('name', 'id')->all();

        return array_combine(
            array_merge(array_keys($globals), array_keys($locals)),
            array_merge(
                array_map(fn (string $name): string => (string) __('tags.options.global_suffix', ['name' => $name]), $globals),
                array_values($locals),
            ),
        );
    }

    /**
     * Plain tag names a vault-scope actor can pick from in a chip-style tag
     * input: every installation-wide tag plus this vault's own tags. Unlike
     * {@see tagOptions()} these carry no display suffix, because the entered
     * value is resolved back to a tag by name.
     *
     * @return list<string>
     */
    public function tagSuggestions(User $user, string $vaultId): array
    {
        if (! $this->access->hasVaultScope($user, $vaultId)) {
            return [];
        }

        return array_values(array_unique(array_merge(
            DB::table('tags')->where('scope', Tag::SCOPE_GLOBAL)->orderBy('name')->pluck('name')->all(),
            DB::table('tags')->where('scope', Tag::SCOPE_VAULT)->where('vault_id', $vaultId)->orderBy('name')->pluck('name')->all(),
        )));
    }

    /**
     * Resolve tag values submitted by the entry form to tag ids. A value may be
     * an existing tag id, an existing tag name (global or same-vault), or a new
     * name, which becomes a vault-local tag. Another vault's tag is never
     * accepted.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public function resolveTagInput(User $actor, string $vaultId, array $values): array
    {
        abort_unless($this->access->allows($actor, $vaultId, 'metadata.view'), 404);

        $hasVaultScope = $this->access->hasVaultScope($actor, $vaultId);
        $resolved = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            if (Str::isUuid($value) && $this->tagExistsInVault($vaultId, $value)) {
                $resolved[] = $value;

                continue;
            }

            $normalized = $this->normalizer->normalize($value);
            if ($normalized === '') {
                continue;
            }

            $existing = DB::table('tags')
                ->where('normalized_name', $normalized)
                ->where(fn ($query) => $query->where('vault_id', $vaultId)->orWhereNull('vault_id'))
                ->orderByRaw('case when vault_id is null then 0 else 1 end')
                ->value('id');
            if ($existing !== null) {
                $resolved[] = (string) $existing;

                continue;
            }

            if (! $hasVaultScope) {
                throw ValidationException::withMessages(['tags' => __('tags.errors.no_access_to_add')]);
            }

            $resolved[] = $this->createTag($actor, $vaultId, ['name' => $value]);
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Validate and de-duplicate tag ids against the vault. A tag is accepted
     * when it is installation-wide or belongs to this vault; another vault's
     * tag is rejected explicitly.
     *
     * @param  array<int, mixed>  $tagIds
     * @return list<string>
     */
    public function validateTags(string $vaultId, array $tagIds): array
    {
        $candidates = array_values(array_unique(array_filter($tagIds, fn (mixed $id): bool => is_string($id) && $id !== '')));
        $tagIds = array_values(array_filter($candidates, fn (string $id): bool => Str::isUuid($id)));
        if ($tagIds !== $candidates) {
            throw ValidationException::withMessages(['tags' => __('tags.errors.choose_valid')]);
        }
        if ($tagIds === []) {
            return [];
        }
        $found = DB::table('tags')->whereIn('id', $tagIds)
            ->where(fn ($query) => $query->where('vault_id', $vaultId)->orWhereNull('vault_id'))
            ->count();
        if ($found !== count($tagIds)) {
            throw ValidationException::withMessages(['tags' => __('tags.errors.choose_valid')]);
        }

        return $tagIds;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, normalized_name: string, color: string|null}
     */
    private function validateTag(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $normalized = $this->normalizer->normalize($name);
        if ($normalized === '') {
            throw ValidationException::withMessages(['name' => __('tags.errors.enter_name')]);
        }

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:'.self::MAX_TAG_NAME],
            'color' => ['nullable', 'string', Rule::in(self::COLORS)],
        ])->validate();

        return [
            'name' => $name,
            'normalized_name' => $normalized,
            'color' => $this->optional($validated, 'color'),
        ];
    }

    private function tagExistsInVault(string $vaultId, string $tagId): bool
    {
        return DB::table('tags')->where('id', $tagId)
            ->where(fn ($query) => $query->where('vault_id', $vaultId)->orWhereNull('vault_id'))
            ->exists();
    }

    private function assertGlobalManager(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('manage-installation')) {
            throw new AuthorizationException((string) __('tags.errors.global_manager_only'));
        }
    }

    private function assertEditor(User $actor, string $vaultId): void
    {
        if (! $this->access->allows($actor, $vaultId, 'entry.update')) {
            throw new AuthorizationException((string) __('tags.errors.editor_required'));
        }
    }

    private function assertVaultActive(string $vaultId): void
    {
        if (DB::table('vaults')->where('id', $vaultId)->value('state') !== 'active') {
            throw ValidationException::withMessages(['name' => __('tags.errors.vault_not_active')]);
        }
    }

    private function assertLocalUnique(string $vaultId, string $normalized, ?string $exceptId = null): void
    {
        $query = DB::table('tags')->where('scope', Tag::SCOPE_VAULT)
            ->where('vault_id', $vaultId)->where('normalized_name', $normalized);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => __('tags.errors.duplicate_vault')]);
        }
    }

    private function assertGlobalUnique(string $normalized, ?string $exceptId = null): void
    {
        $query = DB::table('tags')->where('scope', Tag::SCOPE_GLOBAL)->where('normalized_name', $normalized);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => __('tags.errors.duplicate_global')]);
        }
    }

    private function assertNoGlobalShadow(string $normalized): void
    {
        if (DB::table('tags')->where('scope', Tag::SCOPE_GLOBAL)->where('normalized_name', $normalized)->exists()) {
            throw ValidationException::withMessages(['name' => __('tags.errors.duplicate_global_shadow')]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function optional(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
