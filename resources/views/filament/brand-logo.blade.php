@php
    $brandName = filament()->getBrandName();
@endphp

<span class="fi-vaultigen-brand inline-flex h-full items-center gap-2">
    <img
        src="{{ asset('vaultigen-icon.svg') }}"
        alt=""
        class="fi-vaultigen-mark size-7 shrink-0"
        aria-hidden="true"
    />

    @if (filled($brandName))
        <span class="truncate text-base font-semibold text-gray-950 dark:text-white">
            {{ $brandName }}
        </span>
    @endif
</span>
