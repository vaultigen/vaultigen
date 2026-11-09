<x-filament-panels::page>
    @if ($kind === 'folder')
        <x-filament::section
            :heading="__('vaults.scoped_access.folder_section.heading')"
            :description="__('vaults.scoped_access.folder_section.description')"
        >
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[16rem] flex-1">
                    <x-filament::input.wrapper :valid="! $errors->has('folderName')">
                        <x-filament::input type="text" wire:model="folderName" maxlength="100" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button wire:click="renameFolder" wire:loading.attr="disabled">
                    {{ __('vaults.scoped_access.folder_section.save_name') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        {{ $this->getTable() }}
    </x-filament::section>

    <x-filament::section
        :heading="__('vaults.scoped_access.vault_access.heading')"
        :description="__('vaults.scoped_access.vault_access.description', ['kind' => __('vaults.scopes.'.$kind)])"
    >
        @if (empty($vaultAccess))
            <x-filament::empty-state
                icon="heroicon-o-shield-check"
                :heading="__('vaults.scoped_access.vault_access.empty_heading')"
                :description="__('vaults.scoped_access.vault_access.empty_description', ['kind' => __('vaults.scopes.'.$kind)])"
            />
        @else
            <ul role="list" class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($vaultAccess as $grant)
                    <li wire:key="vault-access-{{ $grant['principal_id'] }}" class="flex flex-wrap items-center gap-2 py-3 text-sm">
                        <x-filament::badge :color="$grant['kind'] === 'team' ? 'info' : 'gray'">
                            {{ ucfirst(__('vaults.principals.'.$grant['kind'])) }}
                        </x-filament::badge>
                        <span class="font-medium text-gray-950 dark:text-white">{{ $grant['label'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400">
                            {{ __('vaults.permissions.'.$grant['permission']) }} ·
                            {{ $grant['scope_label'] }} ·
                            {{ $grant['expires_at'] ? __('vaults.grant_trail.expires', ['date' => $grant['expires_at']->toDayDateTimeString()]) : __('vaults.grant_trail.no_expiry') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
