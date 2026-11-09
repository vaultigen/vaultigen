<x-filament-panels::page>
    <div @if ($isRunning) wire:poll.keep-alive.2s="refresh" @endif class="space-y-6">
        <x-filament::section>
            <div class="space-y-4">
                <div>
                    <x-filament::badge
                        :color="match ($status) {
                            'completed' => 'success',
                            'failed' => 'danger',
                            'cancelled' => 'gray',
                            'importing' => 'info',
                            'queued' => 'warning',
                            default => 'warning',
                        }"
                    >
                        {{ $statusLabel }}
                    </x-filament::badge>
                </div>

                <p class="text-sm text-gray-950 dark:text-white">{{ $summary }}</p>
                @if ($attachmentSummary !== null)
                    <p class="text-sm text-warning-600 dark:text-warning-400">{{ $attachmentSummary }}</p>
                @endif

                @if ($isRunning)
                    <div class="space-y-2">
                        <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                            <span>{{ __('imports.report.progress', ['done' => $progressDone, 'total' => $progressTotal]) }}</span>
                            <span>{{ $progressPercent }}%</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                            <div class="h-full rounded-full bg-primary-600 transition-all duration-500" style="width: {{ $progressPercent }}%"></div>
                        </div>
                    </div>

                    @if ($isQueued && ! $queueWorkerRunning)
                        <x-filament::callout
                            color="warning"
                            icon="heroicon-o-clock"
                            :heading="__('imports.report.queue_unavailable_heading')"
                            :description="__('imports.report.queue_unavailable')"
                        />
                    @endif

                    <form method="POST" action="{{ $cancelUrl }}">
                        @csrf
                        <x-filament::button type="submit" color="danger" icon="heroicon-o-x-circle">
                            {{ __('imports.actions.cancel') }}
                        </x-filament::button>
                    </form>
                @endif

                @if ($errorLabel !== null)
                    <x-filament::callout
                        color="danger"
                        icon="heroicon-o-exclamation-triangle"
                        :heading="__('imports.report.reason')"
                        :description="$errorLabel"
                    />
                @endif

                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-6">
                    @foreach (['vaults', 'folders', 'tags', 'entries', 'grants', 'attachments'] as $counter)
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('imports.report.counters.'.$counter) }}</dt>
                            <dd class="text-sm text-gray-950 dark:text-white">{{ $counters[$counter] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </x-filament::section>

        @if (count($vaults) > 0)
            <x-filament::section :heading="__('imports.report.vaults_heading')">
                <ul class="list-inside list-disc space-y-1 text-sm">
                    @foreach ($vaults as $vault)
                        <li><x-filament::link :href="$vault['url']">{{ $vault['name'] }}</x-filament::link></li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($showFailures)
            <x-filament::section
                :heading="__('imports.report.failures_heading')"
                :description="$failuresTruncated ? __('imports.report.failures_truncated') : null"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pe-4">{{ __('imports.fields.entry_title') }}</th>
                                <th class="py-2 pe-4">{{ $contextLabel }}</th>
                                <th class="py-2">{{ __('imports.fields.reason') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($failures as $failure)
                                <tr>
                                    <td class="py-2 pe-4">
                                        @if ($failure['entry_url'] !== null)
                                            <x-filament::link :href="$failure['entry_url']">{{ $failure['title'] }}</x-filament::link>
                                        @else
                                            {{ $failure['title'] }}
                                        @endif
                                        @if ($failure['attachment'] !== null)
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $failure['attachment'] }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pe-4">{{ $failure['context'] !== '' ? $failure['context'] : __('imports.values.none') }}</td>
                                    <td class="py-2">{{ $failure['error'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @if ($isStaged)
            <x-filament::section>
                <x-filament::link :href="$reviewUrl">{{ __('imports.report.continue') }}</x-filament::link>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
