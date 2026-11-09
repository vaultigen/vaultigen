<?php

namespace App\Services\Imports;

use App\Models\ImportRun;
use App\Services\InstallationSettings;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Retention cleanup for staged uploads and finished import runs.
 *
 * Staging objects are encrypted, but they still hold an entire export, so an
 * abandoned `staging`/`staged` run is cancelled and its object purged once it
 * ages out. A `queued` run that no worker picks up, or an `importing` run whose
 * worker died, is failed and its wrapped credentials purged rather than left
 * holding a secret forever. Terminal runs are kept for reporting until the
 * installation retention window passes, then deleted. Failure lists are capped
 * defensively.
 */
final class ImportRetention
{
    public function __construct(
        private readonly ImportSourceStore $store,
        private readonly ImportCredentialStore $credentials,
        private readonly InstallationSettings $settings,
    ) {}

    /**
     * @return array{staged: int, stalled: int, deleted: int, trimmed: int}
     */
    public function purgeDue(int $limit = 200): array
    {
        $limit = max(1, $limit);
        $cutoff = now()->subDays($this->settings->importRetentionDays());

        $stalled = $this->failStaleInFlight($limit);

        $staged = 0;
        foreach ($this->expiredStaging($cutoff, $limit) as $run) {
            $this->purgeStaging($run);
            $staged++;
        }

        $deleted = $this->deleteTerminal($cutoff, $limit);

        return ['staged' => $staged, 'stalled' => $stalled, 'deleted' => $deleted, 'trimmed' => $this->trimFailureLists()];
    }

    /**
     * Fail runs that are stuck between the browser and the worker. A queued run
     * no worker claimed, or an importing run whose worker died, would otherwise
     * keep its KEK-wrapped credentials indefinitely.
     */
    private function failStaleInFlight(int $limit): int
    {
        $queuedBefore = now()->subSeconds(max(60, (int) config('vaultigen.import_queue_timeout', 3600)));
        $importingBefore = now()->subSeconds(max(120, (int) config('vaultigen.import_job_timeout', 3600) + 300));

        $stale = ImportRun::query()
            ->where('status', ImportRun::STATUS_QUEUED)
            ->whereNotNull('queued_at')
            ->where('queued_at', '<', $queuedBefore)
            ->orderBy('queued_at')
            ->limit($limit)
            ->get()
            ->merge(
                ImportRun::query()
                    ->where('status', ImportRun::STATUS_IMPORTING)
                    ->whereNotNull('started_at')
                    ->where('started_at', '<', $importingBefore)
                    ->orderBy('started_at')
                    ->limit($limit)
                    ->get()
            );

        $count = 0;
        foreach ($stale as $run) {
            $error = $run->status === ImportRun::STATUS_QUEUED ? 'queue_unavailable' : 'import_timeout';

            $this->store->purge($run);
            $this->credentials->purge($run);
            $run->forceFill([
                'status' => ImportRun::STATUS_FAILED,
                'error_code' => $error,
                'failed_at' => now(),
                'updated_at' => now(),
            ])->save();
            $count++;
        }

        return $count;
    }

    /**
     * @return Collection<int, ImportRun>
     */
    private function expiredStaging(DateTimeInterface $cutoff, int $limit): Collection
    {
        return ImportRun::query()
            ->whereIn('status', [ImportRun::STATUS_STAGING, ImportRun::STATUS_STAGED])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
    }

    private function purgeStaging(ImportRun $run): void
    {
        $this->store->purge($run);
        $this->credentials->purge($run);
        $run->forceFill(['status' => ImportRun::STATUS_CANCELLED, 'updated_at' => now()])->save();
    }

    private function deleteTerminal(DateTimeInterface $cutoff, int $limit): int
    {
        return ImportRun::query()
            ->whereIn('status', [ImportRun::STATUS_COMPLETED, ImportRun::STATUS_FAILED, ImportRun::STATUS_CANCELLED])
            ->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (ImportRun $run): void {
                // The row is the only record of where a leftover object is stored.
                $this->store->purge($run);
                $run->delete();
            })
            ->count();
    }

    /**
     * Keep at most {@see ImportRunner::MAX_FAILURES} stored failures on
     * every run while leaving the exact `failure_count` untouched.
     */
    private function trimFailureLists(): int
    {
        return ImportRun::query()->where('failure_count', '>', ImportRunner::MAX_FAILURES)->get()
            ->filter(fn (ImportRun $run): bool => count((array) $run->failures) > ImportRunner::MAX_FAILURES)
            ->each(function (ImportRun $run): void {
                $run->forceFill(['failures' => array_slice((array) $run->failures, 0, ImportRunner::MAX_FAILURES)])->save();
            })
            ->count();
    }
}
