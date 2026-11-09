<div>
    @if ($vault !== null)
        <div class="fi-section m-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-400 dark:ring-danger-500/30">
            <p>
                <strong>{{ __('emergency.banner.title') }}</strong>
                {{ __('emergency.banner.body', ['vault' => $vault, 'expires' => $expiresAt]) }}
            </p>
            <x-filament::button
                wire:click="end"
                wire:confirm="{{ __('emergency.banner.end_confirm') }}"
                color="danger"
                size="sm"
                icon="heroicon-o-x-circle"
            >
                {{ __('emergency.actions.end') }}
            </x-filament::button>
        </div>
    @endif
</div>
