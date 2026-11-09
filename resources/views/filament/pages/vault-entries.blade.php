<x-filament-panels::page>
    @if ($vaultState !== 'active')
        <x-filament::section
            :heading="__('entries.state.'.$vaultState.'.heading')"
            :description="__('entries.state.'.$vaultState.'.description')"
            icon="heroicon-o-lock-closed"
        />
    @endif

    @component('filament.partials.secret-copy')
        {{ $this->getTable() }}
    @endcomponent
</x-filament-panels::page>
