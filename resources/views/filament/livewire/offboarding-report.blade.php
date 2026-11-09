<div>
    @forelse ($entries as $entry)
        <div wire:key="reveal-{{ $entry['entry_id'] }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-3 last:border-b-0 dark:border-white/10">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $entry['title'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $entry['vault_name'] }} · {{ __('entries.types.'.$entry['type']) }}
                    @if ($entry['last_revealed_at'])
                        · {{ __('offboarding.report.last_revealed', ['time' => $entry['last_revealed_at']->diffForHumans()]) }}
                    @endif
                </p>
            </div>
            <x-filament::badge color="warning">
                {{ trans_choice('offboarding.report.reveal_count', $entry['reveal_count'], ['count' => $entry['reveal_count']]) }}
            </x-filament::badge>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('offboarding.report.empty') }}</p>
    @endforelse

    @if (! empty($entries))
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('offboarding.report.rotation_hint') }}</p>
    @endif
</div>
