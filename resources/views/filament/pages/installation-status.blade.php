<x-filament-panels::page>
    @php
        $stateIcons = [
            'healthy' => 'heroicon-o-check-circle',
            'warning' => 'heroicon-o-exclamation-triangle',
            'error' => 'heroicon-o-x-circle',
        ];

        $stateColors = [
            'healthy' => 'success',
            'warning' => 'warning',
            'error' => 'danger',
        ];
    @endphp

    <x-filament::callout
        :color="$summary['color']"
        :icon="$summary['icon']"
        :heading="$summary['heading']"
        :description="$summary['description']"
    />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($report as $check)
            <x-filament::section
                wire:key="health-{{ $loop->index }}"
                :heading="$check['label']"
                :description="$check['status']"
                :icon="$stateIcons[$check['state']]"
                :icon-color="$stateColors[$check['state']]"
                heading-tag="h2"
                compact
            >
                @if ($check['label'] === __('installation.checks.mail.label'))
                    <x-slot name="afterHeader">
                        {{ $this->testMailAction }}
                    </x-slot>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section :heading="__('installation.trust.heading')">
        <div class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            @foreach ($trustNotes as $note)
                <div class="flex gap-3">
                    <x-filament::icon :icon="$note['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $note['heading'] }}</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $note['body'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
