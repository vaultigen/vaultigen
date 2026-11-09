<?php

namespace App\Services\Licensing;

/**
 * The evaluated license of this installation for the running build.
 */
final readonly class LicenseStatus
{
    /**
     * @param  'environment'|'database'|null  $source
     */
    public function __construct(
        public LicenseState $state,
        public ?VerifiedLicense $license = null,
        public ?string $invalidReason = null,
        public ?string $source = null,
    ) {}

    /**
     * The maximum number of seats, or null for unlimited.
     */
    public function seatLimit(): ?int
    {
        return $this->state === LicenseState::Licensed ? $this->license?->maxUsers : LicenseManager::FREE_SEATS;
    }

    public function isManagedByEnvironment(): bool
    {
        return $this->source === 'environment';
    }
}
