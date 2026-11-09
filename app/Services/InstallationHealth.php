<?php

namespace App\Services;

use App\Services\Licensing\LicenseManager;
use App\Services\Licensing\LicenseState;
use App\Services\Licensing\SeatLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallationHealth
{
    public function __construct(private readonly StorageHeadroom $headroom, private readonly LicenseManager $licenses, private readonly SeatLimit $seats) {}

    /**
     * @return array<int, array{label: string, status: string, state: 'healthy'|'warning'|'error'}>
     */
    public function report(): array
    {
        $id = config('vaultigen.installation_id');
        $boundId = DB::table('installation_settings')->where('id', 1)->value('installation_id');
        $auditKey = base64_decode((string) config('vaultigen.audit_key'), true);
        $passkeyKey = base64_decode((string) config('vaultigen.passkey_secret'), true);
        $mailer = config('mail.default');
        $heartbeat = fn (string $name): bool => DB::table('operational_heartbeats')->where('name', $name)->where('observed_at', '>=', now()->subMinutes(3))->exists();

        $sodium = extension_loaded('sodium');
        $identityValid = Str::isUuid($id) && ($boundId === null || $boundId === $id);
        $appKeyPresent = filled(config('app.key'));
        $keysValid = is_string($auditKey) && strlen($auditKey) === 32 && is_string($passkeyKey) && strlen($passkeyKey) === 32 && $auditKey !== $passkeyKey;
        $mailDelivering = in_array($mailer, ['log', 'array'], true);
        $schedulerRunning = $heartbeat('scheduler');
        $queueRunning = $this->queueWorkerRunning();
        $pendingNotifications = (int) DB::table('security_notification_deliveries')->whereNull('delivered_at')->count();
        $storagePath = (string) config('vaultigen.storage.headroom_path');
        $freeBytes = $this->headroom->freeBytes($storagePath);
        $storageLow = $this->headroom->belowMinimum($storagePath, (int) config('vaultigen.storage.min_free_bytes'));
        $freeLabel = $freeBytes === null ? null : number_format($freeBytes / 1048576);

        return [
            [
                'label' => (string) __('installation.checks.database.label'),
                'status' => (string) __('installation.checks.database.status', ['driver' => DB::connection()->getDriverName()]),
                'state' => 'healthy',
            ],
            [
                'label' => (string) __('installation.checks.sodium.label'),
                'status' => (string) __($sodium ? 'installation.checks.sodium.ok' : 'installation.checks.sodium.error'),
                'state' => $sodium ? 'healthy' : 'error',
            ],
            [
                'label' => (string) __('installation.checks.installation_identity.label'),
                'status' => (string) __($identityValid ? 'installation.checks.installation_identity.ok' : 'installation.checks.installation_identity.error'),
                'state' => $identityValid ? 'healthy' : 'error',
            ],
            [
                'label' => (string) __('installation.checks.application_key.label'),
                'status' => (string) __($appKeyPresent ? 'installation.checks.application_key.ok' : 'installation.checks.application_key.error'),
                'state' => $appKeyPresent ? 'healthy' : 'error',
            ],
            [
                'label' => (string) __('installation.checks.security_keys.label'),
                'status' => (string) __($keysValid ? 'installation.checks.security_keys.ok' : 'installation.checks.security_keys.error'),
                'state' => $keysValid ? 'healthy' : 'error',
            ],
            [
                'label' => (string) __('installation.checks.storage.label'),
                'status' => $freeLabel === null
                    ? (string) __('installation.checks.storage.unknown')
                    : (string) __($storageLow ? 'installation.checks.storage.low' : 'installation.checks.storage.ok', ['free' => (string) $freeLabel]),
                'state' => $freeLabel === null || $storageLow ? 'warning' : 'healthy',
            ],
            [
                'label' => (string) __('installation.checks.mail.label'),
                'status' => (string) __($mailDelivering ? 'installation.checks.mail.warning' : 'installation.checks.mail.ok'),
                'state' => $mailDelivering ? 'warning' : 'healthy',
            ],
            [
                'label' => (string) __('installation.checks.scheduler.label'),
                'status' => (string) __($schedulerRunning ? 'installation.checks.scheduler.ok' : 'installation.checks.scheduler.warning'),
                'state' => $schedulerRunning ? 'healthy' : 'warning',
            ],
            [
                'label' => (string) __('installation.checks.queue.label'),
                'status' => (string) __($queueRunning ? 'installation.checks.queue.ok' : 'installation.checks.queue.warning'),
                'state' => $queueRunning ? 'healthy' : 'warning',
            ],
            [
                'label' => (string) __('installation.checks.security_notifications.label'),
                'status' => (string) __('installation.checks.security_notifications.status', ['count' => $pendingNotifications]),
                'state' => $pendingNotifications > 0 ? 'warning' : 'healthy',
            ],
            $this->licenseCheck(),
        ];
    }

    /**
     * @return array{label: string, status: string, state: 'healthy'|'warning'|'error'}
     */
    private function licenseCheck(): array
    {
        $status = $this->licenses->status();
        $license = $status->license;
        $free = ['limit' => LicenseManager::FREE_SEATS];

        [$text, $state] = match (true) {
            $status->state === LicenseState::Licensed && $license !== null => [
                implode(' ', [
                    __('installation.checks.license.licensed', ['licensee' => $license->licensee, 'license_id' => $license->licenseId]),
                    $license->updatesUntil === null
                        ? __('installation.checks.license.lifetime')
                        : __('installation.checks.license.updates_until', ['date' => $license->updatesUntil]),
                    $license->maxUsers === null
                        ? __('installation.checks.license.users_unlimited')
                        : __('installation.checks.license.users_limited', ['count' => $license->maxUsers]),
                ]),
                'healthy',
            ],
            $status->state === LicenseState::UpdatesExpired && $license !== null => [
                __('installation.checks.license.updates_expired', $free + [
                    'licensee' => $license->licensee,
                    'date' => (string) $license->updatesUntil,
                    'release' => (string) config('vaultigen.release_date'),
                ]),
                'warning',
            ],
            $status->state === LicenseState::Invalid => [
                __('installation.checks.license.invalid', $free + ['message' => __('licensing.errors.'.$status->invalidReason)]),
                'warning',
            ],
            default => [__('installation.checks.license.free', $free), 'healthy'],
        };

        return ['label' => (string) __('installation.checks.license.label'), 'status' => ((string) $text).' '.$this->seats->summary(), 'state' => $state];
    }

    /**
     * Whether a queue worker has recorded a heartbeat recently. A `sync` queue
     * has no worker, so it is never considered running.
     */
    public function queueWorkerRunning(): bool
    {
        return config('queue.default') !== 'sync'
            && DB::table('operational_heartbeats')
                ->where('name', 'queue')
                ->where('observed_at', '>=', now()->subMinutes(3))
                ->exists();
    }

    /** @return array<string, string> */
    public function checks(): array
    {
        return collect($this->report())
            ->mapWithKeys(fn (array $check): array => [$check['label'] => $check['status']])
            ->all();
    }
}
