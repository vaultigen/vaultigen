<x-filament::section
    heading="{{ __('operations.backup.heading') }}"
    description="{{ __('operations.backup.description') }}"
>
    @if (($health['state'] ?? 'healthy') !== 'healthy')
        <p role="status">{{ trans()->has('operations.errors.' . $health['code']) ? __('operations.errors.' . $health['code']) : __('operations.errors.generic') }}</p>
    @endif
    @if ($backup === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('operations.backup.empty') }}</p>
    @else
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operations.backup.state') }}</dt>
                <dd class="text-sm text-gray-950 dark:text-white">
                    {{ __('operations.states.' . $backup['state']) }}
                    @if ($backup['error_code'] !== null)
                        — {{ trans()->has('operations.errors.' . $backup['error_code']) ? __('operations.errors.' . $backup['error_code']) : __('operations.errors.generic') }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operations.backup.objects') }}</dt>
                <dd class="text-sm text-gray-950 dark:text-white">{{ $backup['object_count'] }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operations.backup.bytes') }}</dt>
                <dd class="text-sm text-gray-950 dark:text-white">{{ number_format($backup['total_bytes']) }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operations.backup.created_at') }}</dt>
                <dd class="text-sm text-gray-950 dark:text-white">{{ $backup['created_at'] }} UTC</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operations.backup.finished_at') }}</dt>
                <dd class="text-sm text-gray-950 dark:text-white">{{ $backup['completed_at'] ?? $backup['failed_at'] ?? __('operations.values.pending') }} UTC</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('operations.backup.schema') }}</dt>
                <dd class="text-sm text-gray-950 dark:text-white">{{ $backup['schema_version'] ?? __('operations.values.unknown') }}</dd>
            </div>
        </dl>
    @endif
    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('operations.backup.offsite') }}</p>
</x-filament::section>
