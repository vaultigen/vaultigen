<x-filament::section
    heading="{{ __('operations.key_health.heading') }}"
    description="{{ __('operations.key_health.description') }}"
>
    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operations.key_health.active_kek') }}</dt>
            <dd class="font-mono text-xs text-gray-950 dark:text-white">{{ $health['active_kek'] }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operations.key_health.key_ids') }}</dt>
            <dd class="font-mono text-xs text-gray-950 dark:text-white">{{ implode(', ', $health['key_ids']) }}</dd>
        </div>
        <div>
            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operations.key_health.versions') }}</dt>
            <dd class="text-sm text-gray-950 dark:text-white">{{ $health['key_versions'] }} ({{ __('operations.key_health.retired', ['count' => $health['retired_versions']]) }})</dd>
        </div>
    </dl>
    @if (! $health['healthy'])
        <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">
            {{ __('operations.key_health.problem', ['keys' => implode(', ', $health['missing_key_ids']), 'vaults' => $health['vaults_without_key']]) }}
        </p>
    @else
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('operations.key_health.ok') }}</p>
    @endif
</x-filament::section>
