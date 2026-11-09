<x-filament-panels::page>
    <x-filament::callout
        :color="$summary['color']"
        :icon="$summary['icon']"
        :heading="$summary['heading']"
        :description="$summary['description']"
    />

    @if ($status->isManagedByEnvironment())
        <x-filament::callout
            color="gray"
            icon="heroicon-o-command-line"
            :description="__('licensing.page.managed_by_environment')"
        />
    @endif

    @if ($license)
        <x-filament::section :heading="__('licensing.page.details.heading')" icon="heroicon-o-document-check">
            <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                @foreach ([
                    'licensee' => $license->licensee,
                    'email' => $license->email,
                    'license_id' => $license->licenseId,
                    'edition' => __('licensing.page.details.editions.'.$license->edition),
                    'updates' => $license->updatesUntil === null
                        ? __('licensing.page.details.updates_lifetime')
                        : __('licensing.page.details.updates_until', ['date' => $license->updatesUntil]),
                    'user_limit' => $license->maxUsers ?? __('licensing.page.details.users_unlimited'),
                    'issued_at' => substr($license->issuedAt, 0, 10),
                    'source' => __('licensing.page.details.sources.'.$status->source),
                ] as $key => $value)
                    <div wire:key="license-detail-{{ $key }}" class="min-w-0">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('licensing.page.details.'.$key) }}</dt>
                        <dd class="mt-1 break-words text-sm font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('licensing.page.seats_heading')" :description="$seats" icon="heroicon-o-users" compact />

    <x-filament::section :heading="__('licensing.page.pricing.heading')" icon="heroicon-o-shopping-cart">
        <ul class="space-y-2 text-sm text-gray-950 dark:text-white">
            @foreach (['pro', 'renewal', 'lifetime', 'lifetime_upgrade'] as $plan)
                <li>{{ __('licensing.page.pricing.'.$plan, ['currency' => config('vaultigen.pricing.currency'), 'price' => config('vaultigen.pricing.'.$plan), 'regular' => config('vaultigen.pricing.lifetime_regular')]) }}</li>
            @endforeach
        </ul>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('licensing.page.pricing.perpetual') }}</p>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('licensing.page.pricing.taxes') }}</p>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-filament::button tag="a" :href="$buyUrl" target="_blank" rel="noopener noreferrer" color="gray" icon="heroicon-o-shopping-cart">
                {{ __('licensing.page.buy') }}
            </x-filament::button>
            <x-filament::button tag="a" :href="$manageUrl" target="_blank" rel="noopener noreferrer" color="gray" icon="heroicon-o-arrow-top-right-on-square">
                {{ __('licensing.page.manage') }}
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
