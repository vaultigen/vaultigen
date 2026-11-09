<?php

namespace App\Services\SiteIcons;

use App\Jobs\FetchPendingSiteIcons;
use App\Models\Entry;
use App\Models\SiteIcon;
use App\Services\Avatars;
use Illuminate\Support\Facades\DB;

/**
 * The site icon queue: which hosts need a fetch, and what a fetch recorded.
 *
 * Saving an entry only marks its host `pending` and dispatches the single
 * draining job; the fetch itself never runs in the request. The scheduled
 * refresh re-queues stale and failed hosts, recovers claims abandoned by a
 * crashed worker, backfills hosts without a row, and drops icons no entry
 * references any more.
 */
class SiteIcons
{
    public const REFRESH_AFTER_DAYS = 30;

    public const STALE_CLAIM_MINUTES = 10;

    /** @var list<int> */
    public const RETRY_AFTER_DAYS = [1, 7, 30];

    public function enabled(): bool
    {
        return (bool) (DB::table('installation_settings')->where('id', 1)->value('site_icons_enabled') ?? true);
    }

    /**
     * The image source for an entry: its fetched icon, or a letter avatar
     * whose colour is derived from the host (or title) so it stays stable.
     * Expects `siteIcon` to be eager-loaded with at least its checksum.
     */
    public function imageUrl(Entry $entry): string
    {
        $checksum = $entry->siteIcon?->checksum;
        if ($checksum !== null) {
            return route('entries.icon', ['entry' => $entry->id, 'v' => substr($checksum, 0, 12)]);
        }

        $label = (string) ($entry->url_host !== null ? preg_replace('/^www\./', '', $entry->url_host) : $entry->title);

        return app(Avatars::class)->letter($label);
    }

    /**
     * Queue a fetch for a host that has never been fetched.
     */
    public function request(?string $host): void
    {
        if ($host === null || $host === '' || ! $this->enabled()) {
            return;
        }

        $inserted = DB::table('site_icons')->insertOrIgnore([
            'host' => $host,
            'state' => SiteIcon::STATE_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted > 0) {
            DB::afterCommit(fn () => FetchPendingSiteIcons::dispatch());
        }
    }

    /**
     * Queue a fetch now, even for a host that already has an icon.
     */
    public function refresh(string $host): void
    {
        if (! $this->enabled()) {
            return;
        }

        DB::table('site_icons')->upsert(
            [['host' => $host, 'state' => SiteIcon::STATE_PENDING, 'next_check_at' => null, 'created_at' => now(), 'updated_at' => now()]],
            ['host'],
            ['state', 'next_check_at', 'updated_at'],
        );

        DB::afterCommit(fn () => FetchPendingSiteIcons::dispatch());
    }

    /**
     * Atomically claim the next pending host, or null when none is left.
     */
    public function claimNext(): ?string
    {
        while (true) {
            $host = DB::table('site_icons')
                ->where('state', SiteIcon::STATE_PENDING)
                ->orderBy('updated_at')
                ->value('host');
            if (! is_string($host)) {
                return null;
            }

            $claimed = DB::table('site_icons')
                ->where('host', $host)
                ->where('state', SiteIcon::STATE_PENDING)
                ->update(['state' => SiteIcon::STATE_FETCHING, 'claimed_at' => now(), 'updated_at' => now()]);

            if ($claimed === 1) {
                return $host;
            }
        }
    }

    /**
     * Store a fetch result. A failure keeps any icon from an earlier fetch and
     * backs off before the next attempt.
     *
     * @param  array{mime: string, bytes: string}|null  $icon
     */
    public function record(string $host, ?array $icon): void
    {
        $row = DB::table('site_icons')->where('host', $host)->first(['failures']);
        if ($row === null) {
            return;
        }

        if ($icon !== null) {
            DB::table('site_icons')->where('host', $host)->update([
                'state' => SiteIcon::STATE_FETCHED,
                'mime' => $icon['mime'],
                'data' => base64_encode($icon['bytes']),
                'checksum' => hash('sha256', $icon['bytes']),
                'failures' => 0,
                'claimed_at' => null,
                'checked_at' => now(),
                'next_check_at' => now()->addDays(self::REFRESH_AFTER_DAYS),
                'updated_at' => now(),
            ]);

            return;
        }

        $failures = (int) $row->failures + 1;
        $retryDays = self::RETRY_AFTER_DAYS[min($failures, count(self::RETRY_AFTER_DAYS)) - 1];

        DB::table('site_icons')->where('host', $host)->update([
            'state' => SiteIcon::STATE_FAILED,
            'failures' => $failures,
            'claimed_at' => null,
            'checked_at' => now(),
            'next_check_at' => now()->addDays($retryDays),
            'updated_at' => now(),
        ]);
    }

    public function hasPending(): bool
    {
        return DB::table('site_icons')->where('state', SiteIcon::STATE_PENDING)->exists();
    }

    public function dispatchIfPending(): void
    {
        if ($this->enabled() && $this->hasPending()) {
            DB::afterCommit(fn () => FetchPendingSiteIcons::dispatch());
        }
    }

    /**
     * The scheduled maintenance pass. `$requeueFailed` retries every failed
     * host now instead of waiting for its back-off; `$requeueAll` re-fetches
     * every host, including those with a current icon.
     *
     * @return array{queued: int, recovered: int, backfilled: int, deleted: int}
     */
    public function maintain(bool $requeueFailed = false, bool $requeueAll = false): array
    {
        $deleted = DB::table('site_icons')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('entries')->whereColumn('entries.url_host', 'site_icons.host'))
            ->delete();

        if (! $this->enabled()) {
            return ['queued' => 0, 'recovered' => 0, 'backfilled' => 0, 'deleted' => $deleted];
        }

        $recovered = DB::table('site_icons')
            ->where('state', SiteIcon::STATE_FETCHING)
            ->where('claimed_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
            ->update(['state' => SiteIcon::STATE_PENDING, 'claimed_at' => null, 'updated_at' => now()]);

        $queued = DB::table('site_icons')
            ->where('state', '!=', SiteIcon::STATE_PENDING)
            ->where('state', '!=', SiteIcon::STATE_FETCHING)
            ->when(! $requeueAll, fn ($query) => $query->where(fn ($due) => $due
                ->where('next_check_at', '<=', now())
                ->when($requeueFailed, fn ($failed) => $failed->orWhere('state', SiteIcon::STATE_FAILED))))
            ->update(['state' => SiteIcon::STATE_PENDING, 'next_check_at' => null, 'updated_at' => now()]);

        $missing = DB::table('entries')
            ->whereNotNull('url_host')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('site_icons')->whereColumn('site_icons.host', 'entries.url_host'))
            ->distinct()
            ->pluck('url_host');
        $backfilled = 0;
        foreach ($missing->chunk(200) as $chunk) {
            $backfilled += DB::table('site_icons')->insertOrIgnore($chunk->map(fn (string $host): array => [
                'host' => $host,
                'state' => SiteIcon::STATE_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ])->values()->all());
        }

        $this->dispatchIfPending();

        return ['queued' => $queued, 'recovered' => $recovered, 'backfilled' => $backfilled, 'deleted' => $deleted];
    }
}
