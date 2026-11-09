<?php

namespace App\Services\Imports;

use App\Contracts\ImportDriver;
use App\Models\User;
use App\Services\Normalizer;
use App\Services\VaultMembership;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a canonical import into concrete vaults, folders, tags, and grants.
 *
 * Layouts and axes come from the driver: `single_vault` plus one
 * `vault_per_{axis}` per declared axis. Any other axis may be the folder axis,
 * and every remaining axis becomes a tag or is skipped. Axes that exclude each
 * other are never used together.
 *
 * The plan is non-secret: it holds vault names, source ids, tag labels, and
 * grant rules only, never a password, URL, or note. The runner consumes it
 * together with the sealed intermediate representation.
 */
final class ImportPlanner
{
    public const SINGLE_VAULT = 'single_vault';

    public const NONE = 'none';

    public const DESTINATIONS = ['vault', 'folder', 'tag', 'skip'];

    public const TAG_SCOPES = ['global', 'local', 'skip'];

    public const MAX_NAME = 100;

    public const MAX_TAG_NAME = 60;

    private const LAYOUT_PREFIX = 'vault_per_';

    public function __construct(private readonly Normalizer $normalizer) {}

    /**
     * @return list<string>
     */
    public function layouts(ImportDriver $driver): array
    {
        $layouts = [];
        foreach (array_keys($driver->axes()) as $axis) {
            $layouts[] = self::LAYOUT_PREFIX.$axis;
        }
        $layouts[] = self::SINGLE_VAULT;

        return $layouts;
    }

    public function vaultAxisForLayout(ImportDriver $driver, string $layout): string
    {
        foreach (array_keys($driver->axes()) as $axis) {
            if ($layout === self::LAYOUT_PREFIX.$axis) {
                return $axis;
            }
        }

        return self::NONE;
    }

    /**
     * Whether two axes may not be used together.
     */
    public function conflicts(ImportDriver $driver, string $axis, string $other): bool
    {
        if ($axis === self::NONE || $other === self::NONE || $axis === $other) {
            return false;
        }

        $axes = $driver->axes();

        return in_array($other, $axes[$axis]['excludes'] ?? [], true)
            || in_array($axis, $axes[$other]['excludes'] ?? [], true);
    }

    /**
     * The folder axes available for a vault axis, `none` last.
     *
     * @return list<string>
     */
    public function folderAxisChoices(ImportDriver $driver, string $vaultAxis): array
    {
        $choices = [];
        foreach (array_keys($driver->axes()) as $axis) {
            if ($axis !== $vaultAxis && ! $this->conflicts($driver, $axis, $vaultAxis)) {
                $choices[] = $axis;
            }
        }
        $choices[] = self::NONE;

        return $choices;
    }

    /**
     * The destinations an axis may use, given where the other axes go.
     *
     * @param  array<string, string>  $destinations
     * @return list<string>
     */
    public function allowedDestinations(ImportDriver $driver, string $axis, string $vaultAxis, string $folderAxis, array $destinations = []): array
    {
        if ($vaultAxis === $axis) {
            return ['vault'];
        }
        if ($folderAxis === $axis) {
            return ['folder'];
        }
        if ($this->conflicts($driver, $axis, $vaultAxis) || $this->conflicts($driver, $axis, $folderAxis)) {
            return ['skip'];
        }
        foreach ($destinations as $other => $destination) {
            if ($destination === 'tag' && $this->conflicts($driver, $axis, (string) $other)) {
                return ['skip'];
            }
        }

        return ['tag', 'skip'];
    }

    /**
     * Turn a partial or stale configuration into a legal combination.
     *
     * The axis the admin just changed is settled first, so a new tag choice
     * wins over an older conflicting one.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function normalize(ImportDriver $driver, array $config, ?string $changedAxis = null): array
    {
        $defaults = $driver->planDefaults();
        $layout = is_string($config['layout'] ?? null) && in_array($config['layout'], $this->layouts($driver), true)
            ? (string) $config['layout']
            : $defaults['layout'];
        $vaultAxis = $this->vaultAxisForLayout($driver, $layout);

        $folderAxis = is_string($config['folder_axis'] ?? null) ? (string) $config['folder_axis'] : '';
        if (! in_array($folderAxis, $this->folderAxisChoices($driver, $vaultAxis), true)) {
            $folderAxis = $this->preferredFolderAxis($driver, $vaultAxis);
        }

        $axes = array_keys($driver->axes());
        if ($changedAxis !== null && in_array($changedAxis, $axes, true)) {
            $axes = [$changedAxis, ...array_values(array_diff($axes, [$changedAxis]))];
        }

        $destinations = [];
        foreach ($axes as $axis) {
            $allowed = $this->allowedDestinations($driver, $axis, $vaultAxis, $folderAxis, $destinations);
            $current = $config[$axis] ?? ($defaults['destinations'][$axis] ?? null);
            $destinations[$axis] = in_array($current, $allowed, true) ? (string) $current : $allowed[0];
        }

        $normalized = ['layout' => $layout, 'vault_axis' => $vaultAxis, 'folder_axis' => $folderAxis];
        foreach (array_keys($driver->axes()) as $axis) {
            $normalized[$axis] = $destinations[$axis];
        }

        return $normalized;
    }

    /**
     * The driver's default folder axis when it is available, otherwise the
     * first axis that may be combined with the vault axis.
     */
    public function preferredFolderAxis(ImportDriver $driver, string $vaultAxis): string
    {
        $choices = $this->folderAxisChoices($driver, $vaultAxis);
        $preferred = $driver->planDefaults()['folder_axis'];

        return in_array($preferred, $choices, true) ? $preferred : $choices[0];
    }

    /**
     * Sensible defaults for the review screen.
     *
     * @param  array<string, mixed>  $ir
     * @return array<string, mixed>
     */
    public function defaults(ImportDriver $driver, array $ir, User $admin): array
    {
        $defaults = $driver->planDefaults();

        return $this->resolve($driver, $ir, [
            ...$this->normalize($driver, [
                'layout' => $defaults['layout'],
                'folder_axis' => $defaults['folder_axis'],
                ...$defaults['destinations'],
            ]),
            'single_vault_name' => (string) __('imports.defaults.single_vault_name'),
            'default_custodian_user_id' => $admin->id,
            'default_manager_user_id' => $admin->id,
            'vaults' => [],
            'grants' => [],
            'tags' => [],
        ], $admin);
    }

    /**
     * Validate and resolve the admin's plan.
     *
     * @param  array<string, mixed>  $ir
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(ImportDriver $driver, array $ir, array $input, User $admin): array
    {
        $config = $this->validatedConfig($driver, $input);
        $grants = $this->validatedGrants($input['grants'] ?? []);
        $entries = ImportIr::entries($ir);

        $vaultKeys = $this->vaultKeys($ir, $entries, $config);
        $vaults = $this->resolveVaults($vaultKeys, $input['vaults'] ?? [], $config, $admin);

        // Only vaults the admin kept checked shape the tags and the entries that
        // will be written. Excluded vaults stay in the plan so the review table
        // can still show them.
        $includedVaultKeys = array_fill_keys(array_keys(array_filter(
            $vaults,
            fn (array $vault): bool => $vault['include'] === true,
        )), true);

        $tags = $this->resolveTags($ir, $entries, $config, $input['tags'] ?? [], $includedVaultKeys);
        $assignments = $this->resolveAssignments($ir, $entries, $config, $tags, $includedVaultKeys);

        $vaultEntryCounts = $this->vaultEntryCounts($entries, $config);
        foreach ($vaults as $key => $vault) {
            $vaults[$key]['entry_count'] = $vaultEntryCounts[$key] ?? 0;
        }

        return [
            'layout' => $config['layout'],
            'axes' => [
                'vault_axis' => $config['vault_axis'],
                'folder_axis' => $config['folder_axis'],
                ...$config['destinations'],
            ],
            'defaults' => [
                'custodian_user_id' => (string) ($config['default_custodian_user_id'] ?? $admin->id),
                'manager_user_id' => (string) ($config['default_manager_user_id'] ?? $admin->id),
            ],
            'grants' => $grants,
            'vaults' => $vaults,
            'tags' => array_values($tags['list']),
            'assignments' => $assignments,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{layout: string, vault_axis: string, folder_axis: string, destinations: array<string, string>, single_vault_name: string|null, default_custodian_user_id: string|null, default_manager_user_id: string|null}
     */
    private function validatedConfig(ImportDriver $driver, array $input): array
    {
        $axes = array_keys($driver->axes());
        $rules = [
            'layout' => ['required', Rule::in($this->layouts($driver))],
            'vault_axis' => ['required', Rule::in([...$axes, self::NONE])],
            'folder_axis' => ['required', Rule::in([...$axes, self::NONE])],
            'single_vault_name' => ['nullable', 'string', 'max:'.self::MAX_NAME],
            'default_custodian_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'default_manager_user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ];
        foreach ($axes as $axis) {
            $rules[$axis] = ['required', Rule::in(self::DESTINATIONS)];
        }

        $validated = Validator::make($input, $rules)->validate();

        $layout = (string) $validated['layout'];
        $vaultAxis = (string) $validated['vault_axis'];
        $folderAxis = (string) $validated['folder_axis'];
        $destinations = [];
        foreach ($axes as $axis) {
            $destinations[$axis] = (string) $validated[$axis];
        }
        $errors = [];

        if ($vaultAxis !== self::NONE && $vaultAxis === $folderAxis) {
            $errors['folder_axis'] = __('imports.validation.distinct_axes');
        } elseif ($this->conflicts($driver, $vaultAxis, $folderAxis)) {
            $errors['folder_axis'] = __('imports.validation.excluded_axes');
        }

        if ($vaultAxis !== $this->vaultAxisForLayout($driver, $layout)) {
            $errors['vault_axis'] = __('imports.validation.layout_axis_mismatch');
        }

        foreach ($axes as $axis) {
            $others = array_diff_key($destinations, [$axis => true]);
            if (! in_array($destinations[$axis], $this->allowedDestinations($driver, $axis, $vaultAxis, $folderAxis, $others), true)) {
                $errors[$axis] = __('imports.validation.axis_destination', ['axis' => __('imports.axes.'.$axis)]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $singleVaultName = isset($validated['single_vault_name']) ? trim((string) $validated['single_vault_name']) : null;
        if ($vaultAxis === self::NONE && ($singleVaultName === null || $singleVaultName === '')) {
            throw ValidationException::withMessages(['single_vault_name' => __('imports.validation.single_vault_name_required')]);
        }

        return [
            'layout' => $layout,
            'vault_axis' => $vaultAxis,
            'folder_axis' => $folderAxis,
            'destinations' => $destinations,
            'single_vault_name' => $singleVaultName,
            'default_custodian_user_id' => isset($validated['default_custodian_user_id']) ? (string) $validated['default_custodian_user_id'] : null,
            'default_manager_user_id' => isset($validated['default_manager_user_id']) ? (string) $validated['default_manager_user_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{principal_id: string, permission: string}>
     */
    private function validatedGrants(array $input): array
    {
        $grants = [];
        foreach ($input as $row) {
            if (! is_array($row)) {
                continue;
            }
            $validated = Validator::make($row, [
                'principal_id' => ['required', 'uuid', 'exists:principals,id'],
                'permission' => ['required', Rule::in(VaultMembership::PERMISSIONS)],
            ])->validate();
            $grants[(string) $validated['principal_id']] = [
                'principal_id' => (string) $validated['principal_id'],
                'permission' => (string) $validated['permission'],
            ];
        }

        return array_values($grants);
    }

    /**
     * The vault key each entry maps to, keyed by discovery order.
     *
     * @param  array<string, mixed>  $ir
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $config
     * @return array<string, array{axis_value: string, name: string}>
     */
    private function vaultKeys(array $ir, array $entries, array $config): array
    {
        $keys = [];
        foreach ($entries as $entry) {
            [$key, $axisValue] = $this->vaultKeyFor($entry, $config);
            $keys[$key] ??= ['axis_value' => $axisValue, 'name' => $this->defaultVaultName($ir, $config, $axisValue)];
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: string}
     */
    private function vaultKeyFor(array $entry, array $config): array
    {
        if ($config['vault_axis'] === self::NONE) {
            return ['vault', ''];
        }

        $value = ImportIr::axisValue($entry, $config['vault_axis']);

        return [$config['vault_axis'].':'.$value, $value];
    }

    /**
     * @param  array<string, mixed>  $ir
     * @param  array<string, mixed>  $config
     */
    private function defaultVaultName(array $ir, array $config, string $axisValue): string
    {
        if ($config['vault_axis'] === self::NONE) {
            return (string) $config['single_vault_name'];
        }

        if ($axisValue === '') {
            $rootLabel = is_string($ir['root_label'] ?? null) ? trim((string) $ir['root_label']) : '';

            return $rootLabel !== '' ? $rootLabel : (string) __('imports.placeholders.untitled');
        }

        return ImportIr::axisLabel($ir, $config['vault_axis'], $axisValue) ?? (string) __('imports.placeholders.untitled');
    }

    /**
     * Merge admin-provided vault rows with defaults and uniquify colliding names.
     *
     * @param  array<string, array{axis_value: string, name: string}>  $vaultKeys
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $config
     * @return array<string, array{name: string, custodian_user_id: string, manager_user_id: string, entry_count: int, include: bool}>
     */
    private function resolveVaults(array $vaultKeys, array $input, array $config, User $admin): array
    {
        $used = [];
        $vaults = [];

        foreach ($vaultKeys as $key => $defaults) {
            $row = is_array($input[$key] ?? null) ? $input[$key] : [];
            $validated = Validator::make($row, [
                'name' => ['nullable', 'string', 'max:'.self::MAX_NAME],
                'custodian_user_id' => ['nullable', 'uuid', 'exists:users,id'],
                'manager_user_id' => ['nullable', 'uuid', 'exists:users,id'],
                'include' => ['nullable', 'boolean'],
            ])->validate();

            $name = trim((string) ($validated['name'] ?? '')) ?: $defaults['name'];
            $name = $this->uniqueName($name, $used);

            $vaults[$key] = [
                'name' => $name,
                'custodian_user_id' => (string) ($validated['custodian_user_id'] ?? $config['default_custodian_user_id'] ?? $admin->id),
                'manager_user_id' => (string) ($validated['manager_user_id'] ?? $config['default_manager_user_id'] ?? $admin->id),
                'entry_count' => 0,
                'include' => (bool) ($validated['include'] ?? true),
            ];
        }

        return $vaults;
    }

    /**
     * @param  array<string, mixed>  $ir
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $input
     * @param  array<string, true>  $includedVaultKeys
     * @return array{list: array<string, array{name: string, scope: string, entry_count: int, vault_count: int, include: bool}>, skipped: array<string, true>}
     */
    private function resolveTags(array $ir, array $entries, array $config, array $input, array $includedVaultKeys): array
    {
        $overrides = [];
        foreach ($input as $row) {
            if (! is_string($row['name'] ?? null)) {
                continue;
            }

            $scope = is_string($row['scope'] ?? null) ? (string) $row['scope'] : null;
            $include = array_key_exists('include', $row) ? (bool) $row['include'] : true;
            if ($scope === 'skip') {
                // Legacy plans used a skip scope instead of an include flag.
                $include = false;
            }

            $overrides[$this->normalizer->normalize((string) $row['name'])] = [
                'scope' => in_array($scope, ['global', 'local'], true) ? $scope : null,
                'include' => $include,
            ];
        }

        $found = [];
        foreach ($entries as $entry) {
            [$vaultKey] = $this->vaultKeyFor($entry, $config);
            if (! isset($includedVaultKeys[$vaultKey])) {
                continue;
            }

            foreach ($this->tagNamesForEntry($ir, $entry, $config) as $name) {
                $normalized = $this->normalizer->normalize($name);
                $found[$normalized] ??= ['name' => $name, 'vaults' => [], 'entries' => 0];
                $found[$normalized]['vaults'][$vaultKey] = true;
                $found[$normalized]['entries']++;
            }
        }

        $list = [];
        $skipped = [];
        foreach ($found as $normalized => $tag) {
            $defaultScope = count($tag['vaults']) > 1 ? 'global' : 'local';
            $override = $overrides[$normalized] ?? ['scope' => null, 'include' => true];
            $include = $override['include'];

            $list[$normalized] = [
                'name' => $tag['name'],
                'scope' => $override['scope'] ?? $defaultScope,
                'entry_count' => $tag['entries'],
                'vault_count' => count($tag['vaults']),
                'include' => $include,
            ];
            if (! $include) {
                $skipped[$normalized] = true;
            }
        }

        return ['list' => $list, 'skipped' => $skipped];
    }

    /**
     * One assignment per entry, in entry order, so the runner can pair an
     * entry with its destination by index. Entries that belong to an excluded
     * vault carry `include => false` and are not imported.
     *
     * @param  array<string, mixed>  $ir
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $config
     * @param  array{list: array<string, array{name: string, scope: string, entry_count: int, vault_count: int, include: bool}>, skipped: array<string, true>}  $tags
     * @param  array<string, true>  $includedVaultKeys
     * @return list<array{vault_key: string, include: bool, folder_source: string|null, tag_names: list<string>}>
     */
    private function resolveAssignments(array $ir, array $entries, array $config, array $tags, array $includedVaultKeys): array
    {
        $assignments = [];

        foreach ($entries as $entry) {
            [$vaultKey] = $this->vaultKeyFor($entry, $config);

            $folderSource = null;
            if ($config['folder_axis'] !== self::NONE) {
                $candidate = ImportIr::axisValue($entry, $config['folder_axis']);
                if ($candidate !== '' && ImportIr::axisLabel($ir, $config['folder_axis'], $candidate) !== null) {
                    $folderSource = $candidate;
                }
            }

            $names = [];
            foreach ($this->tagNamesForEntry($ir, $entry, $config) as $name) {
                $normalized = $this->normalizer->normalize($name);
                if ($normalized === '' || isset($tags['skipped'][$normalized]) || ! isset($tags['list'][$normalized])) {
                    continue;
                }
                $names[$normalized] = $tags['list'][$normalized]['name'];
            }

            $assignments[] = [
                'vault_key' => $vaultKey,
                'include' => isset($includedVaultKeys[$vaultKey]),
                'folder_source' => $folderSource,
                'tag_names' => array_values($names),
            ];
        }

        return $assignments;
    }

    /**
     * Count the entries that would land in each vault, including vaults the
     * admin has excluded, so the review table can show what is being skipped.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $config
     * @return array<string, int>
     */
    private function vaultEntryCounts(array $entries, array $config): array
    {
        $counts = [];
        foreach ($entries as $entry) {
            [$vaultKey] = $this->vaultKeyFor($entry, $config);
            $counts[$vaultKey] = ($counts[$vaultKey] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The tag labels an entry carries: its source tags plus the label of every
     * axis that is mapped to a tag.
     *
     * @param  array<string, mixed>  $ir
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function tagNamesForEntry(array $ir, array $entry, array $config): array
    {
        $names = [];
        foreach ((array) ($entry['tag_names'] ?? []) as $name) {
            if (is_string($name)) {
                $names[] = mb_substr(trim($name), 0, self::MAX_TAG_NAME);
            }
        }

        foreach ($config['destinations'] as $axis => $destination) {
            if ($destination !== 'tag') {
                continue;
            }
            $value = ImportIr::axisValue($entry, $axis);
            if ($value !== '') {
                $names[] = ImportIr::axisLabel($ir, $axis, $value) ?? '';
            }
        }

        return array_values(array_filter($names, fn (string $name): bool => $name !== ''));
    }

    /**
     * @param  array<string, bool>  $used
     */
    public function uniqueName(string $name, array &$used, int $max = self::MAX_NAME): string
    {
        $name = mb_substr(trim($name), 0, $max);

        $base = $name;
        $suffix = 2;
        while (isset($used[$this->normalizer->normalize($name)])) {
            $suffixLabel = ' ('.$suffix++.')';
            $name = mb_substr($base, 0, $max - mb_strlen($suffixLabel)).$suffixLabel;
        }
        $used[$this->normalizer->normalize($name)] = true;

        return $name;
    }
}
