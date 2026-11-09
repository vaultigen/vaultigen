<?php

namespace App\Filament\Livewire;

use App\Models\User;
use App\Services\Offboarding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only rotation report for the offboarding screen. Lists credentials the
 * selected user revealed, as metadata only; no secret value is ever rendered.
 */
class OffboardingReport extends Component
{
    #[Locked]
    public ?string $user = null;

    public function mount(?string $user = null): void
    {
        Gate::authorize('manage-installation');
        $this->user = $user;
    }

    public function hydrate(): void
    {
        Gate::authorize('manage-installation');
    }

    public function render(): View
    {
        $target = $this->user === null ? null : User::query()->find($this->user);

        return view('filament.livewire.offboarding-report', [
            'entries' => $target === null ? [] : app(Offboarding::class)->report($target),
        ]);
    }
}
