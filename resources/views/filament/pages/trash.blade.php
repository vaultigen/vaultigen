<x-filament-panels::page>
    <x-filament::section
        :heading="__('trash.entries.heading')"
        :description="__('trash.retention_notice', ['days' => $retentionDays])"
        icon="heroicon-o-document-text"
    >
        <livewire:trash-entries :vault="$vaultId" />
    </x-filament::section>

    <x-filament::section
        :heading="__('trash.folders.heading')"
        :description="__('trash.folders.description')"
        icon="heroicon-o-folder-open"
    >
        <livewire:trash-folders :vault="$vaultId" />
    </x-filament::section>
</x-filament-panels::page>
