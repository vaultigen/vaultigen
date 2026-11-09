<div class="space-y-4">
    @if ($devices->isNotEmpty())
        <div class="flex justify-end">
            {{ $this->revokeAllAction }}
        </div>
    @endif

    @forelse ($devices as $device)
        <div wire:key="trusted-device-{{ $device->id }}" class="flex items-center justify-between gap-4">
            <div class="min-w-0">
                <p class="font-medium">
                    {{ $this->deviceLabel($device->user_agent) }}
                    @if ($device->selector === $currentSelector)
                        <x-filament::badge color="gray" class="ms-2">{{ __('devices.trusted.this_device') }}</x-filament::badge>
                    @endif
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('devices.trusted.added', ['time' => \Illuminate\Support\Carbon::parse($device->created_at)->diffForHumans()]) }}
                    @if ($device->last_used_at)
                        · {{ __('devices.trusted.last_used', ['time' => \Illuminate\Support\Carbon::parse($device->last_used_at)->diffForHumans()]) }}
                    @endif
                    · {{ __('devices.trusted.expires', ['time' => \Illuminate\Support\Carbon::parse($device->expires_at)->diffForHumans()]) }}
                </p>
            </div>
            {{ ($this->revokeAction)(['id' => $device->id]) }}
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('devices.trusted.none') }}</p>
    @endforelse
</div>
