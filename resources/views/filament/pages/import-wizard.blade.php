<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">
                {{ __('imports.wizard.save_plan') }}
            </x-filament::button>
        </div>
    </form>

    {{-- Execution credentials (such as the sysPass master password) are
         associated with this plain form, not the Livewire form, so a secret
         never enters a Livewire snapshot. --}}
    <form id="execute-import-form" method="POST" action="{{ $executeUrl }}" class="hidden">
        @csrf
    </form>
</x-filament-panels::page>
