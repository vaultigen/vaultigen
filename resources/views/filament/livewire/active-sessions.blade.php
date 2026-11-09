<div class="space-y-4">
    <div>
        {{ $this->revokeOthersAction }}
    </div>

    @forelse ($sessions as $session)
        <div wire:key="session-{{ $session->id }}" class="flex items-center justify-between gap-4">
            <p>{{ $session->ip_address }} · {{ $session->user_agent }} · {{ \Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)->diffForHumans() }}</p>
            @if ($session->id === session()->getId())
                <p>{{ __('devices.sessions.this_session') }}</p>
            @else
                {{ ($this->revokeAction)(['id' => $session->id]) }}
            @endif
        </div>
    @empty
        <p>{{ __('devices.sessions.none') }}</p>
    @endforelse
</div>
