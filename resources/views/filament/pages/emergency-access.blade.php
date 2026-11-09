<x-filament-panels::page>
    @if ($active)
        <x-filament::section
            icon="heroicon-o-shield-exclamation"
            icon-color="danger"
            heading="{{ __('emergency.active.heading') }}"
            description="{{ __('emergency.active.description') }}"
        >
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('emergency.fields.vault') }}</dt>
                    <dd class="text-sm text-gray-950 dark:text-white">
                        <x-filament::link :href="$active['vault_url']">{{ $active['vault'] }}</x-filament::link>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('emergency.fields.expires_at') }}</dt>
                    <dd class="text-sm text-gray-950 dark:text-white">{{ $active['expires_at'] }} UTC</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('emergency.fields.reason') }}</dt>
                    <dd class="text-sm text-gray-950 dark:text-white">{{ $active['reason'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('emergency.fields.incident_reference') }}</dt>
                    <dd class="text-sm text-gray-950 dark:text-white">{{ $active['incident_reference'] ?? __('emergency.values.none') }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">{{ __('emergency.active.scope_notice') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('emergency.empty') }}</p>
        </x-filament::section>
    @endif

    <x-filament::section heading="{{ __('emergency.recent.heading') }}" description="{{ __('emergency.recent.description') }}">
        @if (count($recent) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('emergency.recent.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pe-4">{{ __('emergency.columns.vault') }}</th>
                            <th class="py-2 pe-4">{{ __('emergency.columns.actor') }}</th>
                            <th class="py-2 pe-4">{{ __('emergency.columns.reason') }}</th>
                            <th class="py-2 pe-4">{{ __('emergency.columns.started_at') }}</th>
                            <th class="py-2 pe-4">{{ __('emergency.columns.ended_at') }}</th>
                            <th class="py-2">{{ __('emergency.columns.notification_status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($recent as $session)
                            <tr>
                                <td class="py-2 pe-4">{{ $session['vault'] }}</td>
                                <td class="py-2 pe-4">{{ $session['actor'] }}</td>
                                <td class="py-2 pe-4">{{ $session['reason'] }}</td>
                                <td class="py-2 pe-4">{{ $session['started_at'] }} UTC</td>
                                <td class="py-2 pe-4">{{ $session['closed_at'] !== null ? $session['closed_at'].' UTC' : __('emergency.values.active') }}</td>
                                <td class="py-2">
                                    @forelse ($session['deliveries'] as $delivery)
                                        <div>{{ $delivery['recipient'] }} — {{ __('emergency.statuses.'.$delivery['status']) }}</div>
                                    @empty
                                        {{ __('emergency.statuses.none') }}
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
