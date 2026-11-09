<?php

namespace App\Services\Licensing;

use App\Models\User;
use App\Services\AuditWriter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Evaluates, installs and removes this installation's license.
 *
 * A license comes from VAULTIGEN_LICENSE when set (the settings UI is then
 * read-only) and otherwise from the installation settings row. Evaluation
 * never contacts the vendor and never uses the current time: it compares the
 * license's update window with this build's release date. The instance is
 * request-scoped, so the license is verified at most once per request.
 */
class LicenseManager
{
    /**
     * Users allowed without a valid license covering this build.
     */
    public const FREE_SEATS = 10;

    private ?LicenseStatus $status = null;

    public function __construct(private readonly LicenseVerifier $verifier, private readonly AuditWriter $audit) {}

    public function status(): LicenseStatus
    {
        return $this->status ??= $this->evaluate();
    }

    public function state(): LicenseState
    {
        return $this->status()->state;
    }

    /**
     * The maximum number of seats, or null for unlimited.
     */
    public function seatLimit(): ?int
    {
        return $this->status()->seatLimit();
    }

    /**
     * Verify and store a license pasted by an administrator.
     *
     * @throws ValidationException when the license is invalid or managed by the environment
     */
    public function install(User $actor, #[\SensitiveParameter] string $armored): VerifiedLicense
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $this->ensureNotManagedByEnvironment();

        try {
            $license = $this->verifier->verify($armored);
        } catch (InvalidLicense $exception) {
            throw ValidationException::withMessages(['license' => (string) __('licensing.errors.'.$exception->reason)]);
        }

        $this->audit->transaction(function () use ($actor, $armored, $license): void {
            DB::table('installation_settings')->where('id', 1)->update(['license' => trim($armored)]);
            $this->audit->record('license.installed', $actor->id, attributes: ['sanitized_context' => $this->auditContext($license)]);
        });
        $this->status = null;

        return $license;
    }

    /**
     * Remove the stored license; the installation returns to the free edition.
     *
     * @throws ValidationException when the license is managed by the environment
     */
    public function remove(User $actor): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $this->ensureNotManagedByEnvironment();

        $this->audit->transaction(function () use ($actor): void {
            $stored = DB::table('installation_settings')->where('id', 1)->lockForUpdate()->value('license');
            if (! is_string($stored) || $stored === '') {
                return;
            }
            DB::table('installation_settings')->where('id', 1)->update(['license' => null]);

            try {
                $context = $this->auditContext($this->verifier->verify($stored));
            } catch (InvalidLicense) {
                $context = ['license_id' => null];
            }
            $this->audit->record('license.removed', $actor->id, attributes: ['sanitized_context' => $context]);
        });
        $this->status = null;
    }

    private function evaluate(): LicenseStatus
    {
        $fromEnvironment = config('vaultigen.license');
        [$armored, $source] = is_string($fromEnvironment) && trim($fromEnvironment) !== ''
            ? [$fromEnvironment, 'environment']
            : [DB::table('installation_settings')->where('id', 1)->value('license'), 'database'];

        if (! is_string($armored) || trim($armored) === '') {
            return new LicenseStatus(LicenseState::Free);
        }

        try {
            $license = $this->verifier->verify($armored);
        } catch (InvalidLicense $exception) {
            return new LicenseStatus(LicenseState::Invalid, invalidReason: $exception->reason, source: $source);
        }

        $state = $license->coversRelease($this->releaseDate())
            ? LicenseState::Licensed
            : LicenseState::UpdatesExpired;

        return new LicenseStatus($state, $license, source: $source);
    }

    /**
     * This build's release date, or null when it is missing or not a real
     * YYYY-MM-DD date, so a broken build is never covered by an update window.
     */
    private function releaseDate(): ?string
    {
        $releaseDate = config('vaultigen.release_date');
        if (! is_string($releaseDate)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $releaseDate, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $releaseDate ? $releaseDate : null;
    }

    private function ensureNotManagedByEnvironment(): void
    {
        if ($this->status()->isManagedByEnvironment()) {
            throw ValidationException::withMessages(['license' => (string) __('licensing.errors.managed_by_environment')]);
        }
    }

    /**
     * Identifying license facts for the audit log; never the license text itself.
     *
     * @return array{license_id: string, edition: string, max_users: int|null, updates_until: string|null}
     */
    private function auditContext(VerifiedLicense $license): array
    {
        return [
            'license_id' => $license->licenseId,
            'edition' => $license->edition,
            'max_users' => $license->maxUsers,
            'updates_until' => $license->updatesUntil,
        ];
    }
}
