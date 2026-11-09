<?php

use App\Auth\EditProfile;
use App\Filament\Livewire\ActiveSessions;
use App\Filament\Pages\Activity;
use App\Filament\Pages\Offboarding;
use App\Filament\Pages\Settings;
use App\Filament\Pages\Users;
use App\Mail\FactorsReset;
use App\Models\User;
use App\Services\AccountAdministration;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('platform role controls permit redacted auditor activity but no administration', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $auditor = User::factory()->withTwoFactor()->create();
    $this->actingAs($admin);
    Livewire::test(Users::class)->callAction('roles', ['roles' => ['auditor']], arguments: ['id' => $auditor->id])->assertHasNoActionErrors();
    expect($auditor->isAuditor())->toBeTrue();
    $this->actingAs($auditor)->get(Activity::getUrl())->assertSee('account.roles_changed')->assertDontSee($admin->email);
    $this->get(Settings::getUrl())->assertForbidden();
    $this->get(Users::getUrl())->assertForbidden();
    $this->get('/')->assertSee('Find your personal work vault and any shared vaults under My vaults');
    $this->actingAs($admin);
    Livewire::test(Users::class)->callAction('roles', ['roles' => ['super_admin', 'auditor']], arguments: ['id' => $auditor->id])->assertHasNoActionErrors();
    expect($auditor->isSuperAdmin())->toBeTrue();
    expect(fn () => app(AccountAdministration::class)->roles($admin, $auditor->id, ['owner']))->toThrow(ValidationException::class);
});

test('vault creator is assignable and grants only the creation ability', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(Users::class)->callAction('roles', ['roles' => ['vault_creator']], arguments: ['id' => $user->id])->assertHasNoActionErrors();

    $user->refresh();
    expect($user->isVaultCreator())->toBeTrue()
        ->and($user->isSuperAdmin())->toBeFalse()
        ->and($user->isAuditor())->toBeFalse()
        ->and($user->hasPlatformRole('vault_creator'))->toBeTrue();
});

test('admin factor reset requires fresh credentials and records durable notification and reason', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $user = User::factory()->withTwoFactor()->create(['remember_token' => 'remember']);
    DB::table('sessions')->insert(['id' => 'victim-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $oldVersion = $user->auth_version;
    $this->actingAs($admin);
    expect(fn () => app(AccountAdministration::class)->resetFactors($admin, $user->id, '', 'password', '000000'))->toThrow(ValidationException::class);
    expect(fn () => app(AccountAdministration::class)->resetFactors($admin, $user->id, 'Lost phone', 'wrong', '000000'))->toThrow(ValidationException::class);
    expect($user->fresh()->app_authentication_secret)->not->toBeNull();
    Livewire::test(Users::class)->callAction('resetFactors', ['reason' => 'Lost phone', 'password' => 'password', 'code' => AppAuthentication::make()->getCurrentCode($admin)], arguments: ['id' => $user->id])->assertHasNoActionErrors()->assertSee('Pending');
    expect($user->fresh()->app_authentication_secret)->toBeNull();
    expect($user->fresh()->app_authentication_recovery_codes)->toBeNull();
    expect($user->fresh()->remember_token)->toBeNull();
    $this->assertDatabaseMissing('sessions', ['id' => 'victim-session']);
    $this->assertDatabaseHas('account_factor_resets', ['user_id' => $user->id, 'actor_id' => $admin->id, 'reason' => 'Lost phone']);
    $this->artisan('vaultigen:deliver-security-notifications')->assertSuccessful();
    $this->artisan('vaultigen:deliver-security-notifications')->assertSuccessful();
    Mail::assertSent(FactorsReset::class, 1);
    expect((new FactorsReset(10))->render())->toContain('enroll your authenticator again');
    $this->actingAs($user->fresh())->withSession(['vaultigen.auth_version' => $oldVersion])->get('/')->assertRedirect(route('filament.app.auth.login'));
    $this->actingAs($user->fresh())->get('/')->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
});

test('account security revokes only owned sessions and can invalidate all other sessions', function () {
    $user = User::factory()->withTwoFactor()->create(['remember_token' => 'remember']);
    $other = User::factory()->withTwoFactor()->create();
    foreach (['owned' => $user, 'foreign' => $other] as $id => $owner) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    }
    $this->actingAs($user);
    $this->get(EditProfile::getUrl())->assertSee('Active sessions')->assertSee('Back');
    Livewire::test(ActiveSessions::class)->callAction('revoke', arguments: ['id' => 'owned']);
    $this->assertDatabaseMissing('sessions', ['id' => 'owned']);
    $this->assertDatabaseHas('sessions', ['id' => 'foreign']);
    expect($user->fresh()->remember_token)->toBeNull();
    $oldVersion = $user->auth_version;
    Livewire::test(ActiveSessions::class)->callAction('revokeOthers');
    expect($user->fresh()->auth_version)->toBeGreaterThan($oldVersion);
    expect(session('vaultigen.auth_version'))->toBe($user->fresh()->auth_version);
});

test('the user list links to offboarding with the account preselected', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $user = User::factory()->withTwoFactor()->create();
    $inactive = User::factory()->withTwoFactor()->create(['is_active' => false]);
    $this->actingAs($admin);

    Livewire::test(Users::class)
        ->assertTableActionHasUrl('offboard', Offboarding::getUrl(['user' => $user->id]), $user)
        ->assertTableActionHidden('offboard', $admin)
        ->assertTableActionHidden('offboard', $inactive);
});
