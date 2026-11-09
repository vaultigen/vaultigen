<?php

namespace App\Services\Operations;

use App\Contracts\KekProvider;
use App\Models\BackupRun;
use App\Models\KeyRotationRun;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Builds the read-only snapshot shown by the Operations page.
 *
 * It reports only sanitized facts: readiness checks, the identifiers of the
 * separately escrowed recovery inputs (never their values), the latest backup
 * result, key health, and any active maintenance or rotation progress.
 */
final class OperationsStatus
{
    public function __construct(
        private readonly BackupKeyring $backupKeys,
        private readonly KekProvider $kek,
        private readonly DatabaseSnapshot $snapshot,
        private readonly MaintenanceWindow $maintenance,
        private readonly ExecutableFinder $executables,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return [
            'readiness' => $this->readiness(),
            'backup_health' => $this->backupHealth(),
            'recovery_inputs' => $this->recoveryInputs(),
            'latest_backup' => $this->latestBackup(),
            'rotations' => $this->rotationHistory(),
            'key_health' => $this->keyHealth(),
            'maintenance' => $this->maintenance->current(),
        ];
    }

    /**
     * @return list<array{label: string, status: string, state: 'healthy'|'warning'|'error'}>
     */
    public function readiness(): array
    {
        $driver = $this->snapshot->driver();
        $backupKey = $this->backupKeys->available();
        $writable = $this->backupDiskWritable();
        $tool = $this->toolAvailable($driver);
        $conflict = $this->conflictingOperation();

        return [
            [
                'label' => (string) __('operations.readiness.backup_key.label'),
                'status' => (string) __($backupKey ? 'operations.readiness.backup_key.ok' : 'operations.readiness.backup_key.error'),
                'state' => $backupKey ? 'healthy' : 'error',
            ],
            [
                'label' => (string) __('operations.readiness.disk.label'),
                'status' => (string) __($writable ? 'operations.readiness.disk.ok' : 'operations.readiness.disk.error'),
                'state' => $writable ? 'healthy' : 'error',
            ],
            [
                'label' => (string) __('operations.readiness.tool.label'),
                'status' => (string) __($tool ? 'operations.readiness.tool.ok' : 'operations.readiness.tool.error', ['driver' => $driver]),
                'state' => $tool ? 'healthy' : 'error',
            ],
            [
                'label' => (string) __('operations.readiness.window.label'),
                'status' => $conflict === null
                    ? (string) __('operations.readiness.window.ok')
                    : (string) __('operations.readiness.window.busy', ['operation' => $conflict]),
                'state' => $conflict === null ? 'healthy' : 'warning',
            ],
        ];
    }

    /**
     * Identifiers and presence flags for the separately escrowed recovery
     * inputs. These are names, never values.
     *
     * @return list<array{label: string, value: string, present: bool}>
     */
    public function recoveryInputs(): array
    {
        $kekIds = $this->kek->keyIds();

        return [
            ['label' => (string) __('operations.recovery.installation_id'), 'value' => (string) config('vaultigen.installation_id'), 'present' => filled(config('vaultigen.installation_id'))],
            ['label' => (string) __('operations.recovery.kek'), 'value' => implode(', ', $kekIds), 'present' => $kekIds !== []],
            ['label' => (string) __('operations.recovery.app_key'), 'value' => filled(config('app.key')) ? (string) __('operations.values.present') : (string) __('operations.values.missing'), 'present' => filled(config('app.key'))],
            ['label' => (string) __('operations.recovery.passkey_secret'), 'value' => filled(config('vaultigen.passkey_secret')) ? (string) __('operations.values.present') : (string) __('operations.values.missing'), 'present' => filled(config('vaultigen.passkey_secret'))],
            ['label' => (string) __('operations.recovery.audit_key'), 'value' => (string) config('vaultigen.audit_key_id'), 'present' => filled(config('vaultigen.audit_key'))],
            ['label' => (string) __('operations.recovery.backup_key'), 'value' => $this->backupKeys->available() ? implode(', ', $this->backupKeys->keyIds()) : (string) __('operations.values.missing'), 'present' => $this->backupKeys->available()],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestBackup(): ?array
    {
        $run = BackupRun::query()->orderByDesc('created_at')->first();
        if ($run === null) {
            return null;
        }

        return [
            'id' => (string) $run->id,
            'state' => (string) $run->state,
            'trigger' => (string) $run->trigger,
            'error_code' => $run->error_code,
            'object_count' => $run->object_count,
            'total_bytes' => $run->total_bytes,
            'schema_version' => $run->schema_version,
            'created_at' => $run->created_at?->toDateTimeString(),
            'completed_at' => $run->completed_at?->toDateTimeString(),
            'failed_at' => $run->failed_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rotationHistory(): array
    {
        return KeyRotationRun::query()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (KeyRotationRun $run): array => [
                'id' => (string) $run->id,
                'operation' => (string) $run->operation,
                'vault_id' => $run->vault_id,
                'status' => (string) $run->status,
                'error_code' => $run->error_code,
                'processed_count' => $run->processed_count,
                'failed_count' => $run->failed_count,
                'created_at' => $run->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function keyHealth(): array
    {
        $activeKek = $this->kek->activeKeyId();
        $keyIds = $this->kek->keyIds();
        $referenced = DB::table('vault_key_wrappings')->whereNull('retired_at')->distinct()->pluck('recipient_key_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')->values();
        $missing = $referenced->reject(fn (string $id): bool => in_array($id, $keyIds, true))->values();
        $vaultsWithoutKey = DB::table('vaults')->whereNotExists(fn ($query) => $query->from('vault_active_keys')->whereColumn('vault_active_keys.vault_id', 'vaults.id'))->count();

        return [
            'active_kek' => $activeKek,
            'key_ids' => $keyIds,
            'referenced_key_ids' => $referenced->all(),
            'missing_key_ids' => $missing->all(),
            'vaults_without_key' => $vaultsWithoutKey,
            'key_versions' => (int) DB::table('vault_key_versions')->count(),
            'retired_versions' => (int) DB::table('vault_key_versions')->whereNotNull('retired_at')->count(),
            'healthy' => $missing->isEmpty() && $vaultsWithoutKey === 0,
        ];
    }

    /** @return array{state: string, code: string} */
    public function backupHealth(): array
    {
        try {
            app(BackupPolicy::class)->validate();
        } catch (Throwable) {
            return ['state' => 'error', 'code' => 'backup_configuration_invalid'];
        }
        $active = BackupRun::query()->whereIn('state', [BackupRun::STATE_PENDING, BackupRun::STATE_RUNNING])->orderBy('created_at')->first();
        if ($active !== null && $active->created_at?->lt(now()->subSeconds(max(300, (int) config('vaultigen.backup_job_timeout')) + 60))) {
            return ['state' => 'error', 'code' => 'backup_stale'];
        }
        $latest = BackupRun::query()->orderByDesc('created_at')->first();
        if ($latest?->state === BackupRun::STATE_FAILED) {
            return ['state' => 'error', 'code' => (string) $latest->error_code];
        }
        $success = BackupRun::query()->where('state', BackupRun::STATE_COMPLETED)->whereNotNull('path')->orderByDesc('completed_at')->first();
        if ($success === null || $success->completed_at?->lt(now()->subHours((int) config('vaultigen.backup_max_age_hours')))) {
            return ['state' => 'warning', 'code' => 'backup_overdue'];
        }

        return ['state' => 'healthy', 'code' => 'backup_current'];
    }

    private function backupDiskWritable(): bool
    {
        try {
            return app(BackupStorage::class)->writable();
        } catch (Throwable) {
            return false;
        }
    }

    private function toolAvailable(string $driver): bool
    {
        return match ($driver) {
            'sqlite' => true,
            'mysql' => $this->executables->find('mysqldump') !== null,
            'mariadb' => $this->executables->find('mariadb-dump') !== null || $this->executables->find('mysqldump') !== null,
            'pgsql' => $this->executables->find('pg_dump') !== null,
            default => false,
        };
    }

    private function conflictingOperation(): ?string
    {
        if ($this->maintenance->active()) {
            return (string) __('operations.readiness.operations.maintenance');
        }
        if (BackupRun::query()->whereIn('state', [BackupRun::STATE_PENDING, BackupRun::STATE_RUNNING])->exists()) {
            return (string) __('operations.readiness.operations.backup');
        }
        if (KeyRotationRun::query()->whereIn('status', [KeyRotationRun::STATUS_PENDING, KeyRotationRun::STATUS_RUNNING])->exists()) {
            return (string) __('operations.readiness.operations.rotation');
        }

        return null;
    }
}
