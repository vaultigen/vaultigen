<x-filament::section
    icon="heroicon-o-pause-circle"
    icon-color="warning"
    :heading="$maintenance !== null ? __('operations.maintenance.heading') : __('operations.active.heading')"
    :description="$maintenance !== null ? __('operations.maintenance.description') : __('operations.active.description')"
>
    @if ($maintenance !== null)
        <p class="text-sm text-gray-950 dark:text-white">
            {{ __('operations.maintenance.operation', ['operation' => $maintenance['operation'] ?? __('operations.values.unknown')]) }}
            @if ($maintenance['started_at'] !== null)
                — {{ $maintenance['started_at'] }} UTC
            @endif
        </p>
    @endif
    @if ($active !== null)
        <div class="mt-3">
            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                <div class="h-full w-1/3 animate-pulse rounded-full bg-primary-500"></div>
            </div>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ __('operations.active.' . $active['kind']) }}
                @if ($active['progress'] !== null)
                    — {{ $active['progress'] }}
                @endif
            </p>
        </div>
    @endif
</x-filament::section>
