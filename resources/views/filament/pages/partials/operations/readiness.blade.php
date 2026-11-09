<x-filament::section
    icon="heroicon-o-server-stack"
    heading="{{ __('operations.readiness.heading') }}"
    description="{{ __('operations.readiness.description') }}"
>
    <ul class="space-y-3">
        @foreach ($checks as $check)
            <li class="flex items-start gap-3">
                <x-filament::icon
                    :icon="$check['state'] === 'healthy' ? 'heroicon-o-check-circle' : ($check['state'] === 'warning' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-x-circle')"
                    @class([
                        'mt-0.5 h-5 w-5 shrink-0',
                        'text-success-500' => $check['state'] === 'healthy',
                        'text-warning-500' => $check['state'] === 'warning',
                        'text-danger-500' => $check['state'] === 'error',
                    ])
                />
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $check['label'] }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $check['status'] }}</p>
                </div>
            </li>
        @endforeach
    </ul>
</x-filament::section>
