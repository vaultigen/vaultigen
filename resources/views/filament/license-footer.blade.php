@php
    $textClasses = match ($tone) {
        'warning' => 'text-warning-600 dark:text-warning-400',
        'muted' => 'text-gray-400 dark:text-gray-500',
        default => 'text-gray-500 dark:text-gray-400',
    };
    $iconClasses = match ($tone) {
        'warning', 'unlicensed' => 'text-warning-500 dark:text-warning-400',
        default => '',
    };
@endphp

<div class="border-t border-gray-200 px-4 py-3 dark:border-white/10" data-license-footer>
    <x-filament::modal id="vaultigen-license-info" teleport="body" width="md" aria-labelledby="vaultigen-license-info-title">
        <x-slot name="trigger">
            <button
                type="button"
                aria-label="{{ __('licensing.footer.open') }}"
                x-data="{ tooltip: false }"
                x-effect="tooltip = $store.sidebar.isOpen ? false : { content: @js($text), placement: document.dir === 'rtl' ? 'left' : 'right', theme: $store.theme }"
                x-tooltip="tooltip"
                x-bind:class="$store.sidebar.isOpen ? 'justify-start' : 'justify-center'"
                @class(['flex w-full cursor-pointer items-center gap-2 text-xs hover:underline', $textClasses])
            >
                <x-filament::icon :icon="$icon" @class(['h-4 w-4 shrink-0', $iconClasses]) />
                <span x-show="$store.sidebar.isOpen" class="min-w-0 truncate text-start" title="{{ $text }}">{{ $text }}</span>
            </button>
        </x-slot>

        <div class="flex flex-col items-center gap-4 py-2 text-center" data-license-dialog>
            <img src="{{ asset('vaultigen-icon.svg') }}" alt="" class="size-14" aria-hidden="true" />
            <h2 id="vaultigen-license-info-title" class="text-xl font-semibold text-gray-950 dark:text-white">Vaultigen</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $message }}</p>

            @if ($links !== [])
                <div class="flex flex-wrap justify-center gap-3">
                    @foreach ($links as $link)
                        <x-filament::button
                            tag="a"
                            :href="$link['url']"
                            :color="$loop->first ? 'primary' : 'gray'"
                            :target="$link['external'] ? '_blank' : null"
                            :rel="$link['external'] ? 'noopener noreferrer' : null"
                        >
                            {{ $link['label'] }}
                        </x-filament::button>
                    @endforeach
                </div>
            @endif
        </div>
    </x-filament::modal>
</div>
