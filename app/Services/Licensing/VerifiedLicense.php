<?php

namespace App\Services\Licensing;

/**
 * The payload of a license whose signature and schema have been verified.
 */
final readonly class VerifiedLicense
{
    public function __construct(
        public string $kid,
        public string $licenseId,
        public string $licensee,
        public string $email,
        public string $edition,
        public ?int $maxUsers,
        public ?string $updatesUntil,
        public string $issuedAt,
    ) {}

    /**
     * Whether the update window covers a build released on the given date.
     * Lifetime licenses cover every build; a build without a release date is
     * covered by no dated update window.
     */
    public function coversRelease(?string $releaseDate): bool
    {
        return $this->updatesUntil === null || ($releaseDate !== null && $releaseDate <= $this->updatesUntil);
    }
}
