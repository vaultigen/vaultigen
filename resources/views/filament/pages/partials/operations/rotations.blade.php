<x-filament::section
    heading="{{ __('operations.rotation.heading') }}"
    description="{{ __('operations.rotation.description') }}"
>
    @if (count($rotations) === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('operations.rotation.empty') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pe-4">{{ __('operations.rotation.columns.operation') }}</th>
                        <th class="py-2 pe-4">{{ __('operations.rotation.columns.status') }}</th>
                        <th class="py-2 pe-4">{{ __('operations.rotation.columns.progress') }}</th>
                        <th class="py-2 pe-4">{{ __('operations.rotation.columns.created_at') }}</th>
                        <th class="py-2">{{ __('operations.rotation.columns.action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rotations as $rotation)
                        <tr>
                            <td class="py-2 pe-4 text-gray-950 dark:text-white">{{ __('operations.rotation.operations.' . $rotation['operation']) }}</td>
                            <td class="py-2 pe-4">
                                {{ __('operations.states.' . $rotation['status']) }}
                                @if ($rotation['error_code'] !== null && isset($errors[$rotation['error_code']]))
                                    — {{ $errors[$rotation['error_code']] }}
                                @endif
                            </td>
                            <td class="py-2 pe-4">{{ $rotation['processed_count'] }} / {{ $rotation['failed_count'] }}</td>
                            <td class="py-2 pe-4">{{ $rotation['created_at'] }} UTC</td>
                            <td class="py-2">
                                @if ($rotation['status'] === 'failed')
                                    <form method="POST" action="{{ route('operations.rotations.resume', ['run' => $rotation['id']]) }}">
                                        @csrf
                                        <x-filament::button type="submit" size="xs" color="warning">
                                            {{ __('operations.rotation.resume') }}
                                        </x-filament::button>
                                    </form>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
