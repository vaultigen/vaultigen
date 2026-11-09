<x-filament-panels::page>
    <x-filament::section
        :heading="__('teams.manage.team_name')"
        :description="__('teams.manage.team_name_description')"
    >
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[16rem] flex-1">
                <x-filament::input.wrapper :valid="! $errors->has('teamName')">
                    <x-filament::input type="text" wire:model="teamName" maxlength="255" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button wire:click="renameTeam" wire:loading.attr="disabled">
                {{ __('teams.manage.save_name') }}
            </x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section
        :heading="__('teams.manage.members')"
        :description="__('teams.manage.members_description')"
    >
        {{ $this->getTable() }}
    </x-filament::section>
</x-filament-panels::page>
