<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InstallationSettings
{
    public function __construct(private AuditWriter $audit) {}

    /** @return array{display_name: string, inactivity_minutes: int, absolute_minutes: int, security_recipients: list<string>, vault_creation_policy: string, vault_creation_limit_per_user: int, vault_creation_limit_installation: int, mfa_methods: list<string>, remember_device_days: int, trash_retention_days: int, vault_purge_retention_days: int, vault_purge_notifications_enabled: bool, revision_retention_count: int, revision_retention_days: int, audit_retention_days: int, audit_ip_retention_days: int, import_retention_days: int, site_icons_enabled: bool} */
    public function values(): array
    {
        $row = DB::table('installation_settings')->where('id', 1)->firstOrFail();

        $mfaMethods = json_decode((string) ($row->mfa_methods ?? '["app"]'), true, flags: JSON_THROW_ON_ERROR);
        $mfaMethods = is_array($mfaMethods) ? array_values(array_filter($mfaMethods, 'is_string')) : [];

        return [
            'display_name' => $row->display_name,
            'inactivity_minutes' => (int) $row->inactivity_minutes,
            'absolute_minutes' => (int) $row->absolute_minutes,
            'security_recipients' => json_decode($row->security_recipients, true, flags: JSON_THROW_ON_ERROR),
            'vault_creation_policy' => (string) $row->vault_creation_policy,
            'vault_creation_limit_per_user' => (int) $row->vault_creation_limit_per_user,
            'vault_creation_limit_installation' => (int) $row->vault_creation_limit_installation,
            'mfa_methods' => $mfaMethods === [] ? ['app'] : $mfaMethods,
            'remember_device_days' => max(0, (int) ($row->remember_device_days ?? 0)),
            'trash_retention_days' => max(1, (int) ($row->trash_retention_days ?? 30)),
            'vault_purge_retention_days' => max(1, (int) ($row->vault_purge_retention_days ?? 30)),
            'vault_purge_notifications_enabled' => (bool) ($row->vault_purge_notifications_enabled ?? true),
            'revision_retention_count' => max(0, (int) ($row->revision_retention_count ?? 50)),
            'revision_retention_days' => max(0, (int) ($row->revision_retention_days ?? 365)),
            'audit_retention_days' => max(1, (int) ($row->audit_retention_days ?? 365)),
            'audit_ip_retention_days' => max(0, (int) ($row->audit_ip_retention_days ?? 30)),
            'import_retention_days' => max(1, (int) ($row->import_retention_days ?? 30)),
            'site_icons_enabled' => (bool) ($row->site_icons_enabled ?? true),
        ];
    }

    public function trashRetentionDays(): int
    {
        return $this->values()['trash_retention_days'];
    }

    public function importRetentionDays(): int
    {
        return $this->values()['import_retention_days'];
    }

    /**
     * The installation's public brand name, safe to resolve from views even
     * before the settings row exists (e.g. rendering a mailable in a test).
     */
    public function displayName(): string
    {
        $name = DB::table('installation_settings')->where('id', 1)->value('display_name');

        return is_string($name) && $name !== ''
            ? $name
            : (string) config('app.name', 'Vaultigen');
    }

    /** @param array<string, mixed> $input */
    public function save(User $actor, array $input): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $data = Validator::make($input, [
            'display_name' => ['required', 'string', 'max:100'],
            'inactivity_minutes' => ['required', 'integer', 'between:1,60', 'lte:absolute_minutes'],
            'absolute_minutes' => ['required', 'integer', 'between:1,720'],
            'security_recipients' => ['present', 'array', 'max:10'],
            'security_recipients.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'vault_creation_policy' => ['required', Rule::in(VaultCreationPolicy::POLICIES)],
            'vault_creation_limit_per_user' => ['required', 'integer', 'between:1,1000'],
            'vault_creation_limit_installation' => ['required', 'integer', 'between:1,100000'],
            'mfa_methods' => ['required', 'array', 'min:1'],
            'mfa_methods.*' => ['string', Rule::in(MfaPolicy::METHODS), 'distinct'],
            'remember_device_days' => ['required', 'integer', 'between:0,365'],
            'trash_retention_days' => ['required', 'integer', 'between:1,365'],
            'vault_purge_retention_days' => ['required', 'integer', 'between:1,365'],
            'vault_purge_notifications_enabled' => ['required', 'boolean'],
            'revision_retention_count' => ['required', 'integer', 'between:0,1000'],
            'revision_retention_days' => ['required', 'integer', 'between:0,3650'],
            'audit_retention_days' => ['required', 'integer', 'between:1,3650'],
            'audit_ip_retention_days' => ['required', 'integer', 'between:0,3650'],
            'import_retention_days' => ['required', 'integer', 'between:1,3650'],
            'site_icons_enabled' => ['required', 'boolean'],
        ])->validate();
        $this->audit->transaction(function () use ($data, $actor): void {
            DB::table('installation_settings')->where('id', 1)->update([
                ...Arr::except($data, ['security_recipients', 'mfa_methods']),
                'security_recipients' => json_encode($data['security_recipients'], JSON_THROW_ON_ERROR),
                'mfa_methods' => json_encode(array_values($data['mfa_methods']), JSON_THROW_ON_ERROR),
            ]);
            // Turning the feature off is final: re-enabling it must not revive old devices.
            if ((int) $data['remember_device_days'] === 0 && DB::table('trusted_devices')->delete() > 0) {
                $this->audit->record('auth.trusted_device_revoked', $actor->id);
            }
            $this->audit->record('installation.settings_changed', $actor->id);
        });
    }
}
