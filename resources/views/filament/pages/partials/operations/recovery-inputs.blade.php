<x-filament::section
    heading="{{ __('operations.recovery.heading') }}"
    description="{{ __('operations.recovery.description') }}"
>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="py-2 pe-4">{{ __('operations.recovery.columns.input') }}</th>
                    <th class="py-2 pe-4">{{ __('operations.recovery.columns.identifier') }}</th>
                    <th class="py-2">{{ __('operations.recovery.columns.status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($inputs as $input)
                    <tr>
                        <td class="py-2 pe-4 text-gray-950 dark:text-white">{{ $input['label'] }}</td>
                        <td class="py-2 pe-4 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $input['value'] }}</td>
                        <td class="py-2">
                            {{ $input['present'] ? __('operations.values.present') : __('operations.values.missing') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('operations.recovery.notice') }}</p>
</x-filament::section>
