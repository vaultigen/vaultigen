<x-filament-panels::page>
    <form wire:submit="move" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button
                type="submit"
                icon="heroicon-o-arrows-right-left"
                wire:confirm="{{ __('entries.move.confirm') }}"
            >
                {{ __('entries.move.submit') }}
            </x-filament::button>
            <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\EntryDetail::getUrl(['entry' => $entryId])">
                {{ __('entries.move.cancel') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
