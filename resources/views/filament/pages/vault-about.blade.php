<div class="space-y-5">
    @if ($selected->description)
        <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $selected->description }}</p>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <x-filament::badge :color="$selected->kind === \App\Models\Vault::KIND_PRIVATE ? 'info' : 'primary'">
            {{ $selected->kind === \App\Models\Vault::KIND_PRIVATE ? __('vaults.kinds.personal') : __('vaults.kinds.shared') }}
        </x-filament::badge>
        <x-filament::badge color="gray">
            {{ __('vaults.states.'.$selected->state) }}
        </x-filament::badge>
        @if ($isOwner)
            <x-filament::badge color="warning">{{ __('vaults.labels.owner') }}</x-filament::badge>
        @endif
        @if ($authority !== null)
            <x-filament::badge :color="$authority['permission'] === 'manager' ? 'success' : ($authority['permission'] === 'editor' ? 'info' : 'gray')">
                {{ __('vaults.about.your_access', ['permission' => __('vaults.permissions.'.$authority['permission'])]) }}
            </x-filament::badge>
            @if ($authority['expires_at'] !== null)
                <x-filament::badge color="warning">
                    {{ __('vaults.about.expires_badge', ['date' => $authority['expires_at']->toDayDateTimeString()]) }}
                </x-filament::badge>
            @endif
        @else
            <x-filament::badge color="gray">{{ __('vaults.about.scoped_access') }}</x-filament::badge>
        @endif
    </div>

    <x-filament::callout
        color="warning"
        icon="heroicon-o-shield-exclamation"
        :heading="__('vaults.about.callout.heading')"
        :description="__('vaults.about.callout.description')"
    />

    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('vaults.about.why_heading') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('vaults.about.why_description') }}
        </p>
        <ul role="list" class="space-y-3">
            @foreach ($explanation as $grant)
                <li wire:key="grant-{{ $grant['scope'] }}-{{ $grant['scope_id'] }}-{{ $grant['principal_id'] }}" class="flex flex-wrap items-center gap-2 text-sm">
                    <x-filament::badge :color="$grant['kind'] === 'team' ? 'info' : 'gray'">
                        {{ ucfirst(__('vaults.principals.'.$grant['kind'])) }}
                    </x-filament::badge>
                    <x-filament::badge color="gray">{{ ucfirst(__('vaults.scopes.'.$grant['scope'])) }}</x-filament::badge>
                    <span class="font-medium text-gray-950 dark:text-white">{{ $grant['label'] }}</span>
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ __('vaults.permissions.'.$grant['permission']) }} ·
                        {{ $grant['scope_label'] }} ·
                        {{ $grant['expires_at'] ? __('vaults.grant_trail.expires', ['date' => $grant['expires_at']->toDayDateTimeString()]) : __('vaults.grant_trail.no_expiry') }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
</div>
