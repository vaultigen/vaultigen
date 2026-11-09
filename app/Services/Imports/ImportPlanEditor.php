<?php

namespace App\Services\Imports;

use App\Contracts\ImportDriver;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Normalizer;

/**
 * Reads, resolves, and persists the non-secret import plan stored on a run.
 *
 * The wizard owns the layout/axis/access configuration; the vault and tag
 * tables own the per-row overrides. Both funnel through here so a change is
 * always re-resolved by the planner against the run's own driver and
 * persisted as one plan.
 */
final class ImportPlanEditor
{
    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly ImportPlanner $planner,
        private readonly Normalizer $normalizer,
    ) {}

    public function driver(ImportRun $run): ImportDriver
    {
        return $this->registry->resolve((string) $run->source_format);
    }

    /**
     * Layout and axis defaults for the run's source.
     *
     * @return array<string, mixed>
     */
    public function defaultPlan(ImportRun $run): array
    {
        $driver = $this->driver($run);

        return $this->planner->defaults($driver, $driver->openIr($run), $this->actor($run));
    }

    /**
     * Validate and persist the admin's plan (layout, axes, vaults, grants, tags).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function plan(ImportRun $run, array $input): array
    {
        $driver = $this->driver($run);
        $plan = $this->planner->resolve($driver, $driver->openIr($run), $input, $this->actor($run));

        $run->forceFill([
            'layout' => (string) ($input['layout'] ?? ''),
            'axis_config' => is_array($plan['axes'] ?? null) ? $plan['axes'] : null,
            'plan' => $plan,
            'updated_at' => now(),
        ])->save();

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $grants
     * @return array<string, mixed>
     */
    public function applyConfigAndGrants(ImportRun $run, array $config, array $grants): array
    {
        $input = $this->inputFromPlan($run);

        foreach ([...$this->configKeys($this->driver($run)), 'single_vault_name', 'default_custodian_user_id', 'default_manager_user_id'] as $key) {
            if (array_key_exists($key, $config)) {
                $input[$key] = $config[$key];
            }
        }

        $input['grants'] = $grants;

        return $this->plan($run, $input);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function applyVaultChange(ImportRun $run, string $key, array $changes): array
    {
        $input = $this->inputFromPlan($run);
        $input['vaults'][$key] = array_merge($input['vaults'][$key] ?? [], $changes);

        return $this->plan($run, $input);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function applyTagChange(ImportRun $run, string $key, array $changes): array
    {
        $input = $this->inputFromPlan($run);

        $tags = [];
        foreach ($input['tags'] as $tag) {
            $tags[$this->normalizer->normalize((string) $tag['name'])] = $tag;
        }
        $tags[$key] = array_merge($tags[$key] ?? ['name' => (string) ($changes['name'] ?? $key)], $changes);
        $input['tags'] = array_values($tags);

        return $this->plan($run, $input);
    }

    /**
     * The planner input rebuilt from the run's stored plan.
     *
     * @return array<string, mixed>
     */
    public function inputFromPlan(ImportRun $run): array
    {
        $driver = $this->driver($run);
        $plan = $this->currentPlan($run);
        $axes = is_array($plan['axes'] ?? null) ? $plan['axes'] : [];
        $defaults = is_array($plan['defaults'] ?? null) ? $plan['defaults'] : [];

        return [
            ...$this->planner->normalize($driver, [
                ...$axes,
                'layout' => $plan['layout'] ?? $run->layout ?? ImportPlanner::SINGLE_VAULT,
            ]),
            'single_vault_name' => $this->singleVaultName($plan),
            'default_custodian_user_id' => $this->nonEmpty($defaults['custodian_user_id'] ?? null),
            'default_manager_user_id' => $this->nonEmpty($defaults['manager_user_id'] ?? null),
            'vaults' => $this->vaultOverrides($plan),
            'tags' => array_values($this->tagOverrides($plan)),
            'grants' => array_values((array) ($plan['grants'] ?? [])),
        ];
    }

    /**
     * Flat vault rows for the review table, in plan order.
     *
     * @return list<array<string, mixed>>
     */
    public function vaultRows(ImportRun $run): array
    {
        $rows = [];
        foreach ((array) ($this->currentPlan($run)['vaults'] ?? []) as $key => $vault) {
            if (! is_array($vault)) {
                continue;
            }

            $rows[] = [
                'key' => (string) $key,
                'name' => (string) ($vault['name'] ?? ''),
                'custodian_user_id' => (string) ($vault['custodian_user_id'] ?? ''),
                'manager_user_id' => (string) ($vault['manager_user_id'] ?? ''),
                'entry_count' => (int) ($vault['entry_count'] ?? 0),
                'include' => (bool) ($vault['include'] ?? true),
            ];
        }

        return $rows;
    }

    /**
     * Flat tag rows for the review table, in plan order.
     *
     * @return list<array<string, mixed>>
     */
    public function tagRows(ImportRun $run): array
    {
        $rows = [];
        foreach ((array) ($this->currentPlan($run)['tags'] ?? []) as $tag) {
            if (! is_array($tag) || ! is_string($tag['name'] ?? null)) {
                continue;
            }

            $name = (string) $tag['name'];
            $rows[] = [
                'key' => $this->normalizer->normalize($name),
                'name' => $name,
                'scope' => (string) ($tag['scope'] ?? 'local'),
                'entry_count' => (int) ($tag['entry_count'] ?? 0),
                'vault_count' => (int) ($tag['vault_count'] ?? 0),
                'include' => (bool) ($tag['include'] ?? true),
            ];
        }

        return $rows;
    }

    /**
     * The layout and axis keys the wizard form owns for a driver.
     *
     * @return list<string>
     */
    public function configKeys(ImportDriver $driver): array
    {
        return ['layout', 'vault_axis', 'folder_axis', ...array_keys($driver->axes())];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentPlan(ImportRun $run): array
    {
        return is_array($run->plan) ? $run->plan : $this->defaultPlan($run);
    }

    private function actor(ImportRun $run): User
    {
        return User::query()->findOrFail($run->requested_by_user_id);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function singleVaultName(array $plan): string
    {
        $axes = is_array($plan['axes'] ?? null) ? $plan['axes'] : [];
        if (($axes['vault_axis'] ?? ImportPlanner::NONE) === ImportPlanner::NONE) {
            $name = trim((string) ($plan['vaults']['vault']['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return (string) __('imports.defaults.single_vault_name');
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, array<string, mixed>>
     */
    private function vaultOverrides(array $plan): array
    {
        $defaults = is_array($plan['defaults'] ?? null) ? $plan['defaults'] : [];
        $defaultCustodian = $this->nonEmpty($defaults['custodian_user_id'] ?? null);
        $defaultManager = $this->nonEmpty($defaults['manager_user_id'] ?? null);

        $overrides = [];
        foreach ((array) ($plan['vaults'] ?? []) as $key => $vault) {
            if (! is_array($vault)) {
                continue;
            }

            $custodian = $this->nonEmpty($vault['custodian_user_id'] ?? null);
            $manager = $this->nonEmpty($vault['manager_user_id'] ?? null);

            $override = [
                'name' => (string) ($vault['name'] ?? ''),
                'include' => (bool) ($vault['include'] ?? true),
            ];

            // Only persist a per-vault value when it differs from the plan
            // default, so changing the default still reaches untouched vaults.
            if ($custodian !== null && $custodian !== $defaultCustodian) {
                $override['custodian_user_id'] = $custodian;
            }
            if ($manager !== null && $manager !== $defaultManager) {
                $override['manager_user_id'] = $manager;
            }

            $overrides[(string) $key] = $override;
        }

        return $overrides;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, array<string, mixed>>
     */
    private function tagOverrides(array $plan): array
    {
        $overrides = [];
        foreach ((array) ($plan['tags'] ?? []) as $tag) {
            if (! is_array($tag) || ! is_string($tag['name'] ?? null)) {
                continue;
            }

            $name = (string) $tag['name'];
            $overrides[$this->normalizer->normalize($name)] = [
                'name' => $name,
                'scope' => (string) ($tag['scope'] ?? 'local'),
                'include' => (bool) ($tag['include'] ?? true),
            ];
        }

        return $overrides;
    }

    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
