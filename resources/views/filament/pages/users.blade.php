<x-filament-panels::page>
    {{ $this->getTable() }}

    <x-filament::section
        :heading="__('users.blade.recent_invitations')"
        :description="__('users.blade.recent_invitations_description')"
        icon="heroicon-o-envelope"
    >
        <livewire:recent-invitations />
    </x-filament::section>

    <x-filament::section
        :heading="__('users.blade.delivery')"
        :description="__('users.blade.delivery_description')"
        icon="heroicon-o-paper-airplane"
    >
        @forelse ($deliveries as $delivery)
            <div wire:key="delivery-{{ $delivery->id }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-3 last:border-b-0 dark:border-white/10">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $delivery->recipient }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('users.blade.attempts', ['count' => $delivery->attempts]) }}</p>
                </div>
                <x-filament::badge :color="$delivery->delivered_at ? 'success' : ($delivery->failure_code ? 'danger' : 'warning')">
                    {{ $delivery->delivered_at ? __('users.blade.delivered') : ($delivery->failure_code ? __('users.blade.failed') : __('users.blade.pending')) }}
                </x-filament::badge>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('users.blade.none_pending') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
