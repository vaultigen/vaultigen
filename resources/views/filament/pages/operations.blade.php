<x-filament-panels::page>
    @if ($active !== null || $maintenance !== null)
        <meta http-equiv="refresh" content="5">
    @endif

    {{ $this->content }}
</x-filament-panels::page>
