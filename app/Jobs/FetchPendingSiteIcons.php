<?php

namespace App\Jobs;

use App\Services\Operations\MaintenanceWindow;
use App\Services\SiteIcons\SiteIconFetcher;
use App\Services\SiteIcons\SiteIcons;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Drains the pending site icons in short, time-boxed slices.
 *
 * It fetches one host after another for at most `SLICE_SECONDS`, then
 * re-dispatches itself while work remains, so the shared queue's heartbeat,
 * backups, and imports are never stuck behind a long backlog. The uniqueness
 * lock is released when processing starts, which lets the job queue its own
 * successor while still collapsing a burst of dispatches into one queued job.
 * If the chain breaks, the scheduled `vaultigen:refresh-site-icons` restarts it.
 */
class FetchPendingSiteIcons implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const SLICE_SECONDS = 30;

    /** @var int */
    public $tries = 1;

    /** @var int */
    public $timeout = 180;

    /** @var int */
    public $uniqueFor = 600;

    public function handle(SiteIcons $icons, SiteIconFetcher $fetcher, MaintenanceWindow $maintenance): void
    {
        if ($maintenance->active() || ! $icons->enabled()) {
            return;
        }

        $deadline = now()->addSeconds(self::SLICE_SECONDS);

        while (now()->lt($deadline) && ($host = $icons->claimNext()) !== null) {
            try {
                $icon = $fetcher->fetch($host);
            } catch (Throwable $exception) {
                report($exception);
                $icon = null;
            }

            $icons->record($host, $icon);
        }

        $icons->dispatchIfPending();
    }
}
