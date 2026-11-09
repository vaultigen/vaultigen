<?php

use App\Filament\Pages\License;
use App\Models\User;
use App\Services\Licensing\LicenseManager;
use App\Services\Licensing\LicenseState;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\LicenseVectorBuilder;

beforeEach(function () {
    vaultigen_trust_test_license_key();
});

function licenseAdmin(): User
{
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    test()->actingAs($admin);

    return $admin;
}

test('only administrators can open the license page', function () {
    $this->get(License::getUrl())->assertRedirect(route('filament.app.auth.login'));

    $this->actingAs(User::factory()->withTwoFactor()->create())->get(License::getUrl())->assertForbidden();

    $this->actingAs(User::factory()->superAdmin()->withTwoFactor()->create())->get(License::getUrl())
        ->assertOk()
        ->assertSee('Free edition')
        ->assertSee(License::BUY_URL)
        ->assertSee(License::MANAGE_URL);
});

test('administrators install a pasted license', function () {
    licenseAdmin();

    Livewire::test(License::class)
        ->callAction('install', ['license' => LicenseVectorBuilder::testLicense(['max_users' => 50])])
        ->assertHasNoActionErrors()
        ->assertNotified('License for ACME GmbH installed')
        ->assertSee('Licensed to ACME GmbH')
        ->assertSee('lic_01J9ZQ3V6K8M2N4P5R7S9T1W3X')
        ->assertActionVisible('remove');

    expect(app(LicenseManager::class)->state())->toBe(LicenseState::Licensed);
});

test('an invalid license is explained and the form stays open', function () {
    licenseAdmin();

    Livewire::test(License::class)
        ->mountAction('install')
        ->fillForm(['license' => 'not a license'])
        ->callMountedAction()
        ->assertNotified('License not installed')
        ->assertActionMounted('install');

    Livewire::test(License::class)
        ->callAction('install', ['license' => ''])
        ->assertHasActionErrors(['license' => 'required']);

    expect(DB::table('installation_settings')->where('id', 1)->value('license'))->toBeNull();
});

test('administrators remove an installed license', function () {
    licenseAdmin();
    vaultigen_install_test_license();

    Livewire::test(License::class)
        ->callAction('remove')
        ->assertNotified('License removed')
        ->assertSee('Free edition')
        ->assertActionHidden('remove');

    expect(app(LicenseManager::class)->state())->toBe(LicenseState::Free);
});

test('a license from the environment is shown read-only', function () {
    licenseAdmin();
    config(['vaultigen.license' => LicenseVectorBuilder::testLicense()]);

    Livewire::test(License::class)
        ->assertSee('VAULTIGEN_LICENSE environment variable')
        ->assertActionHidden('install')
        ->assertActionHidden('remove');
});

test('the license page explains each license state', function (array $overrides, ?string $releaseDate, string $heading, string $detail) {
    licenseAdmin();
    config(['vaultigen.release_date' => $releaseDate]);
    vaultigen_install_test_license($overrides);

    Livewire::test(License::class)->assertSee($heading)->assertSee($detail);
})->with([
    'licensed' => [['max_users' => 50], '2026-10-01', 'Licensed to ACME GmbH', 'Until 2027-10-01'],
    'lifetime' => [['edition' => 'lifetime', 'updates_until' => null], '2026-10-01', 'Licensed to ACME GmbH', 'Unlimited'],
    'expired' => [[], '2027-10-02', 'The license does not cover this version', 'this version was released on 2027-10-02'],
    'invalid' => [['product' => 'other'], '2026-10-01', 'The installed license is not valid', 'This license is not for Vaultigen.'],
]);

test('the sidebar note opens a license dialog for every user, with the license link only for administrators', function (array|false $overrides, ?string $releaseDate, string $text, string $message, ?string $link, ?string $adminLink) {
    config(['vaultigen.release_date' => $releaseDate]);
    if ($overrides !== false) {
        vaultigen_install_test_license($overrides);
    }
    $adminHref = 'href="'.License::getUrl().'"';

    $user = $this->actingAs(User::factory()->withTwoFactor()->create())->get('/')
        ->assertOk()->assertSee($text)->assertSee('vaultigen-license-info')->assertSee($message)->assertDontSee($adminHref, false);
    $admin = $this->actingAs(User::factory()->superAdmin()->withTwoFactor()->create())->get('/')
        ->assertOk()->assertSee($text)->assertSee($message);

    foreach ([$user, $admin] as $response) {
        $link === null ? $response->assertDontSee(License::BUY_URL) : $response->assertSee($link);
    }
    $adminLink === null ? $admin->assertDontSee($adminHref, false) : $admin->assertSee($adminLink)->assertSee($adminHref, false);
})->with([
    'free edition' => [false, '2026-10-01', 'Free edition · up to 10 users', 'You are using the unlicensed version of Vaultigen, which is free for up to 10 users.', License::BUY_URL, 'Add a license'],
    'invalid license' => [['product' => 'other'], '2026-10-01', 'Free edition · up to 10 users', 'You are using the unlicensed version of Vaultigen', License::BUY_URL, 'Add a license'],
    'update window expired' => [[], '2027-10-02', 'License does not cover this version', 'The license for ACME GmbH does not cover this Vaultigen version', License::MANAGE_URL, 'Install the renewed license'],
    'licensed' => [[], '2026-10-01', 'Licensed to ACME GmbH', 'This application is licensed to ACME GmbH.', null, null],
]);

test('the licensee name is escaped on the license page and in the sidebar', function () {
    licenseAdmin();
    vaultigen_install_test_license(['licensee' => '<script>alert(1)</script>']);

    Livewire::test(License::class)->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    $this->get('/')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
});

test('the sidebar note is translated', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create(['locale' => 'de']))->get('/')
        ->assertOk()->assertSee('Kostenlose Edition · bis zu 10 Benutzer');
});
