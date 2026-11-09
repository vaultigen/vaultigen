<?php

use App\Auth\EditProfile;
use App\Auth\Login;
use App\Mail\AdminRecovered;
use App\Models\User;
use App\Services\AdminRecovery;
use App\Services\AuditWriter;
use App\Services\InstallationSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('bootstrap creates administrators with UUID principals and requires enrollment outside local development', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    $this->artisan('vaultigen:bootstrap', ['email' => 'admin@example.test'])
        ->expectsQuestion('Administrator password', 'a long synthetic passphrase')
        ->assertSuccessful();
    $admin = User::sole();
    expect($admin->isSuperAdmin())->toBeTrue();
    expect(Str::isUuid($admin->id))->toBeTrue();
    $this->assertDatabaseHas('principals', ['id' => $admin->principal_id, 'kind' => 'user']);
    $this->assertDatabaseHas('installation_settings', ['installation_id' => config('vaultigen.installation_id')]);
    $this->artisan('vaultigen:bootstrap', ['email' => 'another@example.test'])
        ->expectsQuestion('Administrator password', 'another synthetic passphrase')
        ->assertSuccessful();
    expect(User::count())->toBe(2);
    expect(User::where('email', 'another@example.test')->sole()->isSuperAdmin())->toBeTrue();
    $this->actingAs($admin)->get('/')->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
});

test('bootstrap and recovery refuse noninteractive or weak passwords', function () {
    $this->artisan('vaultigen:bootstrap', ['email' => 'admin@example.test', '--no-interaction' => true])->assertFailed();
    $this->artisan('vaultigen:recover-admin', ['email' => 'admin@example.test', '--no-interaction' => true])->assertFailed();
    $this->artisan('vaultigen:bootstrap', ['email' => 'bad'])
        ->expectsQuestion('Administrator password', 'weak')->assertFailed();
    $this->artisan('vaultigen:recover-admin', ['email' => 'admin@example.test'])
        ->expectsQuestion('Temporary password', 'weak')->assertFailed();
    expect(User::count())->toBe(0);
});

test('local recovery revokes sessions forces password change then enrollment and durably notifies', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    app(InstallationSettings::class)->save($admin, [...app(InstallationSettings::class)->values(), 'security_recipients' => ['security@example.test']]);
    DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    DB::table('password_reset_tokens')->insert(['email' => $admin->email, 'token' => 'old-token']);
    $oldVersion = $admin->auth_version;
    $this->artisan('vaultigen:recover-admin', ['email' => $admin->email])
        ->expectsQuestion('Temporary password', 'temporary synthetic password')->assertSuccessful();
    $admin->refresh();
    expect($admin->app_authentication_secret)->toBeNull();
    expect($admin->app_authentication_recovery_codes)->toBeNull();
    expect($admin->remember_token)->toBeNull();
    expect($admin->must_reset_password)->toBeTrue();
    expect($admin->auth_version)->toBeGreaterThan($oldVersion);
    $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
    expect(DB::table('security_notification_deliveries')->count())->toBe(2);
    Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'temporary synthetic password'])
        ->call('authenticate')->assertHasNoErrors();
    $this->get('/')->assertRedirect(route('filament.app.auth.profile'));
    $this->get(route('filament.app.auth.profile'))->assertOk();
    expect(fn () => $admin->saveAppAuthenticationSecret('NOTALLOWED'))->toThrow(HttpException::class);
    Livewire::test(EditProfile::class)->fillForm([
        'password' => 'replacement synthetic password', 'passwordConfirmation' => 'replacement synthetic password',
        'currentPassword' => 'temporary synthetic password',
    ], 'passwordForm')->call('savePassword')->assertHasNoErrors()->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
    $admin->refresh();
    expect($admin->must_reset_password)->toBeFalse();
    expect(Hash::check('replacement synthetic password', $admin->password))->toBeTrue();
    $component = Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
        ->callAction(TestAction::make('setUpAppAuthentication')->schemaComponent('app', 'content'));
    $arguments = decrypt($component->get('mountedActions')[0]['arguments']['encrypted']);
    $code = AppAuthentication::make()->getCurrentCode($admin, $arguments['secret']);
    $component->fillForm(['code' => $code, 'password' => 'replacement synthetic password'])->callMountedAction()->assertHasNoFormErrors();
    expect(session('vaultigen.auth_version'))->toBe(auth()->user()->fresh()->auth_version);
    expect(Hash::check('replacement synthetic password', auth()->user()->password))->toBeTrue();
    expect(session('password_hash_web') === auth()->user()->password || session('password_hash_web') === auth()->guard()->hashPasswordForCookie(auth()->user()->password))->toBeTrue();
    $response = $this->get('/');
    expect($response->headers->get('Location'))->toBeNull();
    $response->assertOk();
    expect(app(AuditWriter::class)->verify())->toBeTrue();
});

test('local recovery refuses installations with multiple super admins', function () {
    $admin = User::factory()->superAdmin()->create();
    User::factory()->superAdmin()->create();
    expect(fn () => app(AdminRecovery::class)->recover($admin->email, 'temporary synthetic password'))->toThrow(ValidationException::class);
    expect($admin->fresh()->must_reset_password)->toBeFalse();
});

test('security delivery retries sanitized failures and skips delivered messages', function () {
    $admin = User::factory()->superAdmin()->create();
    app(AdminRecovery::class)->recover($admin->email, 'temporary synthetic password');
    config(['mail.default' => 'smtp']);
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SECRET transport password'));
    $this->artisan('vaultigen:deliver-security-notifications')->assertSuccessful();
    $this->assertDatabaseHas('security_notification_deliveries', ['attempts' => 1, 'failure_code' => 'mail_delivery_failed', 'delivered_at' => null]);
    Mail::swap(new MailManager($this->app));
    Mail::fake();
    $this->artisan('vaultigen:deliver-security-notifications')->assertSuccessful();
    $this->artisan('vaultigen:deliver-security-notifications')->assertSuccessful();
    Mail::assertSent(AdminRecovered::class, fn (AdminRecovered $mail): bool => $mail->hasTo($admin->email));
    Mail::assertSentCount(1);
    $mail = new AdminRecovered(42);
    $mail->assertSeeInText('Audit event: 42');
    $mail->assertHasSubject('Vaultigen: local administrator recovery');
    $delivery = DB::table('security_notification_deliveries')->sole();
    expect($delivery->attempts)->toBe(2);
    expect($delivery->delivered_at)->not->toBeNull();
    expect($delivery->failure_code)->toBeNull();
});

test('initialization creates separate protected secrets once without printing them', function () {
    $directory = sys_get_temp_dir().'/vaultigen-'.bin2hex(random_bytes(8));
    $path = $directory.'/installation.json';
    config(['vaultigen.installation_file' => $path, 'vaultigen.installation_id' => null]);
    try {
        $this->artisan('vaultigen:initialize')->assertSuccessful();
        $configuration = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($configuration['audit_key'])->not->toBe($configuration['passkey_secret']);
        expect(strlen(base64_decode($configuration['audit_key'])))->toBe(32);
        expect(fileperms($path) & 0777)->toBe(0600);
        $this->artisan('vaultigen:initialize')->assertFailed();
        expect(json_decode(file_get_contents($path), true))->toBe($configuration);
    } finally {
        @unlink($path);
        @rmdir($directory);
    }
});

test('non delivering mail transports leave security notifications pending', function () {
    $admin = User::factory()->superAdmin()->create();
    app(AdminRecovery::class)->recover($admin->email, 'temporary synthetic password');
    config(['mail.default' => 'log']);
    Mail::fake();
    $this->artisan('vaultigen:deliver-security-notifications')->assertSuccessful();
    Mail::assertNothingSent();
    $this->assertDatabaseHas('security_notification_deliveries', ['delivered_at' => null, 'failure_code' => 'mail_transport_unconfigured']);
});

test('a notification claimed by another worker is not delivered twice', function () {
    $admin = User::factory()->superAdmin()->create();
    app(AdminRecovery::class)->recover($admin->email, 'temporary synthetic password');
    Mail::fake();
    $lock = Mockery::mock(Lock::class);
    Cache::shouldReceive('lock')->once()->andReturn($lock);
    $lock->shouldReceive('get')->once()->andReturnUsing(function (Closure $callback): void {
        DB::table('security_notification_deliveries')->update(['delivered_at' => now()]);
        $callback();
    });
    $this->artisan('vaultigen:deliver-security-notifications')->assertSuccessful();
    Mail::assertNothingSent();
});

test('initialization reports a protected file creation failure without exposing secrets', function () {
    $path = sys_get_temp_dir().'/vaultigen-directory-'.bin2hex(random_bytes(8));
    mkdir($path);
    config(['vaultigen.installation_file' => $path, 'vaultigen.installation_id' => null]);
    try {
        $this->artisan('vaultigen:initialize')->expectsOutput('Unable to create protected installation configuration.')->assertFailed();
    } finally {
        rmdir($path);
    }
});
