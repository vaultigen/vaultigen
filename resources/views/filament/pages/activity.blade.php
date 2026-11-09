<x-filament-panels::page>
    <p role="status" class="mb-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
        @if ($verified['verified'])
            <x-filament::badge color="success">{{ __('activity.verified') }}</x-filament::badge>
            <span>{{ __('activity.verified_note', ['events' => $verified['events'], 'checkpoints' => $verified['checkpoints']]) }}</span>
        @else
            <x-filament::badge color="danger">{{ __('activity.failed') }}</x-filament::badge>
            <span>{{ __('activity.failed_note') }}</span>
        @endif
        @if ($verified['pruned'] > 0 && $verified['last_prune'] !== null)
            <x-filament::badge color="gray">{{ __('activity.pruned_badge') }}</x-filament::badge>
            <span>{{ __('activity.pruned_note', ['first' => $verified['last_prune']['first_sequence'], 'last' => $verified['last_prune']['last_sequence']]) }}</span>
        @endif
    </p>

    {{ $this->getTable() }}
</x-filament-panels::page>
