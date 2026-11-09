<x-filament-panels::page>
    <form wire:submit="offboard">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" color="danger" icon="heroicon-o-user-minus">
                {{ __('offboarding.actions.offboard') }}
            </x-filament::button>
        </div>
    </form>

    <x-filament::section
        :heading="__('offboarding.report.heading')"
        :description="__('offboarding.report.description')"
        icon="heroicon-o-arrow-path"
    >
        <livewire:offboarding-report :user="$target?->id" />
    </x-filament::section>
</x-filament-panels::page>
