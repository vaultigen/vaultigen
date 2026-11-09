<?php

use App\Filament\Pages\InstallationStatus;
use App\Models\User;
use App\Services\InstallationHealth;
use App\Services\Licensing\LicenseManager;
use App\Services\Licensing\LicenseState;
use App\Services\Licensing\TrustedKeys;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fixtures\LicenseVectorBuilder;

beforeEach(function () {
    vaultigen_trust_test_license_key();
});

/**
 * @return array<string, array{0: array{name: string, release_date: ?string, armored: string, expected: array{state: string, reason: ?string}}}>
 */
function licenseVectorCases(): array
{
    $vectors = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/licensing/vectors.json'), true, flags: JSON_THROW_ON_ERROR);

    return collect($vectors['cases'])->mapWithKeys(fn (array $case): array => [$case['name'] => [$case]])->all();
}

test('every shared test vector evaluates to its expected state and reason', function (array $case) {
    config(['vaultigen.license' => $case['armored'], 'vaultigen.release_date' => $case['release_date']]);

    $status = app(LicenseManager::class)->status();

    expect($status->state->value)->toBe($case['expected']['state'])
        ->and($status->invalidReason)->toBe($case['expected']['reason']);
})->with(licenseVectorCases(...));

test('a malformed release date is covered only by a lifetime license', function (mixed $releaseDate) {
    config(['vaultigen.release_date' => $releaseDate]);
    vaultigen_install_test_license(['updates_until' => '2099-12-31']);
    expect(app(LicenseManager::class)->state())->toBe(LicenseState::UpdatesExpired);

    vaultigen_install_test_license(['edition' => 'lifetime', 'updates_until' => null]);
    expect(app(LicenseManager::class)->state())->toBe(LicenseState::Licensed);
})->with([
    'empty' => [''],
    'sorts before every date' => ['0'],
    'not a calendar date' => ['2026-02-30'],
    'with a time' => ['2026-10-01T00:00:00Z'],
    'not a string' => [20261001],
]);

test('the source always carries a real release date', function () {
    $releaseDate = (require config_path('vaultigen.php'))['release_date'];
    $parsed = is_string($releaseDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $releaseDate) : false;

    expect($parsed)->not->toBeFalse()
        ->and($parsed->format('Y-m-d'))->toBe($releaseDate);
});

test('the test key is never trusted by production code, whatever the environment', function () {
    app()->forgetInstance(TrustedKeys::class);
    app()->forgetScopedInstances();
    config(['vaultigen.license' => LicenseVectorBuilder::testLicense()]);

    expect(app()->environment())->toBe('testing')
        ->and(app(LicenseManager::class)->status())
        ->state->toBe(LicenseState::Invalid)
        ->invalidReason->toBe('unknown_key')
        ->and((new TrustedKeys)->publicKey('2026-1'))->toBe(base64_decode('QsTLwLQmLP36Y7CE0sC83BKQkkCgUy2eFCSiYXRxMgs=', true));
});

test('a license on one line, as in an environment file, is accepted', function () {
    config(['vaultigen.license' => str_replace("\n", ' ', LicenseVectorBuilder::testLicense())]);

    expect(app(LicenseManager::class)->state())->toBe(LicenseState::Licensed);
});

test('without a license the installation is the free edition with ten seats', function () {
    expect(app(LicenseManager::class)->status())
        ->state->toBe(LicenseState::Free)
        ->source->toBeNull()
        ->and(app(LicenseManager::class)->seatLimit())->toBe(10);
});

test('the environment license takes precedence over the stored license', function () {
    vaultigen_install_test_license(['licensee' => 'Stored GmbH']);
    expect(app(LicenseManager::class)->status())->source->toBe('database')
        ->and(app(LicenseManager::class)->status()->license?->licensee)->toBe('Stored GmbH');

    config(['vaultigen.license' => LicenseVectorBuilder::testLicense(['licensee' => 'Environment AG'])]);
    app()->forgetScopedInstances();

    expect(app(LicenseManager::class)->status())->source->toBe('environment')
        ->and(app(LicenseManager::class)->status()->license?->licensee)->toBe('Environment AG');
});

test('the seat limit follows the license state', function (array $overrides, ?string $releaseDate, ?int $limit) {
    config(['vaultigen.release_date' => $releaseDate]);
    vaultigen_install_test_license($overrides);

    expect(app(LicenseManager::class)->seatLimit())->toBe($limit);
})->with([
    'licensed with a cap' => [['max_users' => 50], '2026-10-01', 50],
    'licensed without a cap' => [['max_users' => null], '2026-10-01', null],
    'update window expired' => [['max_users' => 50], '2027-10-02', 10],
    'invalid license' => [['max_users' => 5], '2026-10-01', 10],
]);

test('administrators install a license and the audit log records it without the license text', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $armored = LicenseVectorBuilder::testLicense(['max_users' => 50]);
    $signature = explode('.', (string) preg_replace('/\s+/', '', $armored))[1];

    $license = app(LicenseManager::class)->install($admin, "\n  ".$armored);

    expect($license->licensee)->toBe('ACME GmbH')
        ->and(app(LicenseManager::class)->state())->toBe(LicenseState::Licensed)
        ->and(DB::table('installation_settings')->where('id', 1)->value('license'))->toBe(trim($armored));

    $event = DB::table('audit_events')->where('action', 'license.installed')->sole();
    expect($event->actor_id)->toBe($admin->id)
        ->and(json_decode((string) $event->sanitized_context, true))->toBe([
            'license_id' => 'lic_01J9ZQ3V6K8M2N4P5R7S9T1W3X', 'edition' => 'pro', 'max_users' => 50, 'updates_until' => '2027-10-01',
        ])
        ->and($event->canonical_event)->not->toContain(substr($signature, 0, 16));
});

test('installing rejects invalid licenses without storing or auditing them', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $tampered = collect(licenseVectorCases())->get('bad-signature-tampered-payload')[0]['armored'];

    expect(fn () => app(LicenseManager::class)->install($admin, $tampered))
        ->toThrow(ValidationException::class, __('licensing.errors.bad_signature'))
        ->and(DB::table('installation_settings')->where('id', 1)->value('license'))->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'license.installed')->exists())->toBeFalse();
});

test('only administrators can install or remove a license', function () {
    $user = User::factory()->withTwoFactor()->create();

    expect(fn () => app(LicenseManager::class)->install($user, LicenseVectorBuilder::testLicense()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(LicenseManager::class)->remove($user))->toThrow(AuthorizationException::class)
        ->and(DB::table('installation_settings')->where('id', 1)->value('license'))->toBeNull();
});

test('a license from the environment cannot be replaced or removed in the application', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    config(['vaultigen.license' => LicenseVectorBuilder::testLicense()]);

    expect(fn () => app(LicenseManager::class)->install($admin, LicenseVectorBuilder::testLicense(['licensee' => 'Other AG'])))
        ->toThrow(ValidationException::class, __('licensing.errors.managed_by_environment'))
        ->and(fn () => app(LicenseManager::class)->remove($admin))
        ->toThrow(ValidationException::class, __('licensing.errors.managed_by_environment'));
});

test('removing a license returns to the free edition and is audited once', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    app(LicenseManager::class)->install($admin, LicenseVectorBuilder::testLicense());

    app(LicenseManager::class)->remove($admin);
    app(LicenseManager::class)->remove($admin);

    $removed = DB::table('audit_events')->where('action', 'license.removed')->sole();
    expect(app(LicenseManager::class)->state())->toBe(LicenseState::Free)
        ->and(DB::table('installation_settings')->where('id', 1)->value('license'))->toBeNull()
        ->and(json_decode((string) $removed->sanitized_context, true)['license_id'])->toBe('lic_01J9ZQ3V6K8M2N4P5R7S9T1W3X');
});

test('an invalid stored license can still be removed', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    vaultigen_install_test_license(['max_users' => 5]);
    expect(app(LicenseManager::class)->state())->toBe(LicenseState::Invalid);

    app(LicenseManager::class)->remove($admin);

    $removed = DB::table('audit_events')->where('action', 'license.removed')->sole();
    expect(app(LicenseManager::class)->state())->toBe(LicenseState::Free)
        ->and(json_decode((string) $removed->sanitized_context, true))->toBe(['license_id' => null]);
});

test('the installation status reports the license state', function (array $overrides, ?string $releaseDate, string $state, string $text) {
    config(['vaultigen.release_date' => $releaseDate]);
    vaultigen_install_test_license($overrides);

    $check = collect(app(InstallationHealth::class)->report())->keyBy('label')['License'];

    expect($check['state'])->toBe($state)->and($check['status'])->toContain($text);
})->with([
    'licensed' => [['max_users' => 50], '2026-10-01', 'healthy', 'Licensed to ACME GmbH (lic_01J9ZQ3V6K8M2N4P5R7S9T1W3X). Updates until 2027-10-01. Up to 50 users. 0 of 50 seats in use'],
    'lifetime' => [['edition' => 'lifetime', 'updates_until' => null], '2026-10-01', 'healthy', 'Lifetime updates. Unlimited users.'],
    'expired' => [[], '2027-10-02', 'warning', 'covers versions released until 2027-10-01; this version was released on 2027-10-02. Up to 10 users'],
    'invalid' => [['product' => 'other'], '2026-10-01', 'warning', 'This license is not for Vaultigen. The installation runs as the free edition with up to 10 users.'],
]);

test('the installation status page escapes the licensee name', function () {
    vaultigen_install_test_license(['licensee' => '<script>alert(1)</script>']);
    $this->actingAs(User::factory()->superAdmin()->withTwoFactor()->create());

    Livewire::test(InstallationStatus::class)
        ->assertSee('Licensed to <script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false);
});
