<x-filament-panels::page>
    <x-filament::section :heading="__('dashboard.welcome_heading')" :description="__('dashboard.welcome_description')">
        <div class="space-y-4">
            <p>{{ __('dashboard.trust_notice') }}</p>
            @can('manage-installation')
                <div class="flex flex-wrap gap-3">
                    <x-filament::button :href="\App\Filament\Pages\Settings::getUrl()" tag="a">{{ __('dashboard.actions.settings') }}</x-filament::button>
                    <x-filament::button :href="\App\Filament\Pages\InstallationStatus::getUrl()" tag="a" color="gray">{{ __('dashboard.actions.installation_status') }}</x-filament::button>
                    <x-filament::button :href="\App\Filament\Pages\Activity::getUrl()" tag="a" color="gray">{{ __('dashboard.actions.activity') }}</x-filament::button>
                </div>
            @else
                <p>{{ __('dashboard.restricted_notice') }}</p>
            @endcan
            @can('view-activity')
                <a href="{{ \App\Filament\Pages\Activity::getUrl() }}">{{ __('dashboard.review_activity') }}</a>
            @endcan
        </div>
    </x-filament::section>

    @if ($favoriteEntries->isNotEmpty())
        <x-filament::section :heading="__('dashboard.favorites.entries_heading')" :description="__('dashboard.favorites.entries_description')">
            @component('filament.partials.secret-copy')
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">{{ __('dashboard.favorites.columns.title') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('dashboard.favorites.columns.type') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('dashboard.favorites.columns.vault') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('entries.common.folder') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('entries.list.column.host') }}</th>
                            <th class="py-2 font-medium"><span class="sr-only">{{ __('dashboard.favorites.columns.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($favoriteEntries as $entry)
                            @php
                                $usernameHandler = $entry->type === \App\Services\EntryPayload::TYPE_LOGIN
                                    ? 'copySecret('.\Illuminate\Support\Js::from(route('entries.reveal', ['entry' => $entry->id, 'field' => 'username'])).', '.\Illuminate\Support\Js::from(__('entries.fields.login.username')).')'
                                    : null;
                                $passwordHandler = $entry->type === \App\Services\EntryPayload::TYPE_LOGIN
                                    ? 'copySecret('.\Illuminate\Support\Js::from(route('entries.reveal', ['entry' => $entry->id, 'field' => 'password'])).', '.\Illuminate\Support\Js::from(__('entries.fields.login.password')).')'
                                    : null;
                                $passphraseHandler = $entry->type === \App\Services\EntryPayload::TYPE_SSH_KEY
                                    ? 'copySecret('.\Illuminate\Support\Js::from(route('entries.reveal', ['entry' => $entry->id, 'field' => 'passphrase'])).', '.\Illuminate\Support\Js::from(__('entries.fields.ssh_key.passphrase')).')'
                                    : null;
                            @endphp
                            <tr>
                                <td class="py-2 pr-4">
                                    <div class="flex items-center gap-2">
                                        <img src="{{ app(\App\Services\SiteIcons\SiteIcons::class)->imageUrl($entry) }}" alt="" loading="lazy" class="size-6 shrink-0 rounded">
                                        <a href="{{ \App\Filament\Pages\EntryDetail::getUrl(['entry' => $entry->id]) }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $entry->title }}
                                        </a>
                                    </div>
                                </td>
                                <td class="py-2 pr-4">
                                    <x-filament::badge :color="match ($entry->type) { \App\Services\EntryPayload::TYPE_LOGIN => 'primary', \App\Services\EntryPayload::TYPE_NOTE => 'info', \App\Services\EntryPayload::TYPE_SSH_KEY => 'warning', \App\Services\EntryPayload::TYPE_TOTP => 'success', default => 'gray' }">
                                        {{ \App\Services\EntryPayload::typeLabels()[$entry->type] ?? ucfirst(str_replace('_', ' ', $entry->type)) }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2 pr-4">
                                    <div class="flex items-center gap-2">
                                        @if ($entry->vault)
                                            @include('filament.partials.vault-avatar', ['vault' => $entry->vault])
                                        @endif
                                        <a href="{{ \App\Filament\Pages\VaultEntries::getUrl(['vault' => $entry->vault_id]) }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $entry->vault?->name }}
                                        </a>
                                    </div>
                                </td>
                                <td class="py-2 pr-4">{{ $entry->folder?->name ?? '—' }}</td>
                                <td class="py-2 pr-4">
                                    @php($hostUrl = filled($entry->url_host) ? \Illuminate\Support\Str::sanitizeUrl('https://'.$entry->url_host) : null)
                                    @if ($hostUrl)
                                        <a href="{{ $hostUrl }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">{{ $entry->url_host }}</a>
                                    @else
                                        {{ $entry->url_host ?? '—' }}
                                    @endif
                                </td>
                                <td class="py-2">
                                    <div class="flex items-center gap-1">
                                        @if ($usernameHandler)
                                            <x-filament::icon-button icon="heroicon-o-user" color="gray" size="sm" :tooltip="__('entries.list.action.copy_username')" x-on:click="{!! $usernameHandler !!}" />
                                        @endif
                                        @if ($passwordHandler)
                                            <x-filament::icon-button icon="heroicon-o-key" color="gray" size="sm" :tooltip="__('entries.list.action.copy_password')" x-on:click="{!! $passwordHandler !!}" />
                                        @endif
                                        @if ($passphraseHandler)
                                            <x-filament::icon-button icon="heroicon-o-key" color="gray" size="sm" :tooltip="__('entries.list.action.copy_passphrase')" x-on:click="{!! $passphraseHandler !!}" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endcomponent
        </x-filament::section>
    @endif

    @if ($favoriteVaults->isNotEmpty())
        <x-filament::section :heading="__('dashboard.favorites.vaults_heading')" :description="__('dashboard.favorites.vaults_description')">
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($favoriteVaults as $vault)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="flex items-center gap-2">
                            @include('filament.partials.vault-avatar', ['vault' => $vault])
                            <a href="{{ \App\Filament\Pages\VaultEntries::getUrl(['vault' => $vault->id]) }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $vault->name }}
                            </a>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-filament::badge :color="$vault->kind === \App\Models\Vault::KIND_PRIVATE ? 'info' : 'primary'">
                                {{ $vault->kind === \App\Models\Vault::KIND_PRIVATE ? __('vaults.kinds.personal') : __('vaults.kinds.shared') }}
                            </x-filament::badge>
                            <x-filament::badge :color="match ($vault->state) { 'active' => 'success', 'read_only' => 'warning', 'pending_purge' => 'danger', default => 'gray' }">
                                {{ __('vaults.states.'.$vault->state) }}
                            </x-filament::badge>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
