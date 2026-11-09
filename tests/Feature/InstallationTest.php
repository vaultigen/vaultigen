<?php

use App\Auth\Login;
use App\Filament\Pages\Activity;
use App\Filament\Pages\InstallationStatus;
use App\Filament\Pages\Settings;
use App\Jobs\QueueHeartbeat;
use App\Mail\TestMail;
use App\Models\User;
use App\Services\InstallationHealth;
use App\Services\InstallationSettings;
use Filament\Forms\Components\Repeater;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('admins save reload and audit only allowed installation settings', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    Livewire::test(Settings::class)
        ->assertSet('data.inactivity_minutes', 15)
        ->assertSet('data.absolute_minutes', 720)
        ->assertSet('data.import_retention_days', 30)
        ->fillForm(['display_name' => 'Example team', 'inactivity_minutes' => 10, 'absolute_minutes' => 60, 'security_recipients' => [['email' => 'security@example.test']]])
        ->call('save')->assertHasNoFormErrors()->assertNotified('Settings saved');
    Livewire::test(Settings::class)->assertSet('data.display_name', 'Example team')->assertSet('data.inactivity_minutes', 10);
    app(InstallationSettings::class)->save($admin, [...app(InstallationSettings::class)->values(), 'audit_key' => 'untrusted-key', 'installation_id' => 'forged']);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'installation.settings_changed']);
    expect(DB::table('installation_settings')->value('installation_id'))->toBeNull();
    $this->get(Settings::getUrl())->assertOk()->assertSee('Example team');
    $this->get('/')->assertOk()->assertSee('Installation status');
    Livewire::test(Activity::class)->assertSee('installation.settings_changed')->assertSee('verified');
});

test('invalid settings show errors without writing or auditing', function (array $changes, string $field) {
    $undoRepeaterFake = Repeater::fake();
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $before = DB::table('audit_events')->count();
    $this->actingAs($admin);
    Livewire::test(Settings::class)->fillForm($changes)->call('save')->assertHasFormErrors([$field]);
    expect(app(InstallationSettings::class)->values()['inactivity_minutes'])->toBe(15);
    expect(DB::table('audit_events')->count())->toBe($before);
    $undoRepeaterFake();
})->with([
    [['display_name' => ''], 'display_name'],
    [['display_name' => str_repeat('x', 101)], 'display_name'],
    [['inactivity_minutes' => 0], 'inactivity_minutes'],
    [['inactivity_minutes' => 61], 'inactivity_minutes'],
    [['inactivity_minutes' => 1.5], 'inactivity_minutes'],
    [['absolute_minutes' => 721], 'absolute_minutes'],
    [['absolute_minutes' => 0], 'absolute_minutes'],
    [['absolute_minutes' => 5], 'inactivity_minutes'],
    [['security_recipients' => [['email' => 'bad-email']]], 'security_recipients.0.email'],
    [['vault_creation_policy' => 'everyone'], 'vault_creation_policy'],
    [['vault_creation_limit_per_user' => 0], 'vault_creation_limit_per_user'],
    [['vault_creation_limit_per_user' => 1001], 'vault_creation_limit_per_user'],
    [['vault_creation_limit_installation' => 0], 'vault_creation_limit_installation'],
    [['import_retention_days' => 0], 'import_retention_days'],
    [['import_retention_days' => 3651], 'import_retention_days'],
]);

test('admins configure the vault creation policy and quotas', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(Settings::class)
        ->assertSet('data.vault_creation_policy', 'vault_creators')
        ->assertSet('data.vault_creation_limit_per_user', 50)
        ->assertSet('data.vault_creation_limit_installation', 500)
        ->assertSee('Super Admins only')
        ->assertSeeHtml('fi-fo-radio')
        ->fillForm([
            'vault_creation_policy' => 'any_active_user',
            'vault_creation_limit_per_user' => 7,
            'vault_creation_limit_installation' => 42,
        ])
        ->call('save')->assertHasNoFormErrors();

    $values = app(InstallationSettings::class)->values();
    expect($values['vault_creation_policy'])->toBe('any_active_user')
        ->and($values['vault_creation_limit_per_user'])->toBe(7)
        ->and($values['vault_creation_limit_installation'])->toBe(42);
});

test('settings validation rejects duplicate or too many security recipients', function (array $recipients) {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    expect(fn () => app(InstallationSettings::class)->save($admin, [...app(InstallationSettings::class)->values(), 'security_recipients' => $recipients]))->toThrow(ValidationException::class);
})->with([
    [['one@example.test', 'ONE@example.test']],
    [array_map(fn (int $i): string => "security{$i}@example.test", range(1, 11))],
]);

test('ordinary users cannot read admin screens or submit settings', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    foreach ([Settings::class, Activity::class, InstallationStatus::class] as $page) {
        $this->get($page::getUrl())->assertForbidden();
        Livewire::test($page)->assertForbidden();
    }
    expect(fn () => app(InstallationSettings::class)->save($user, app(InstallationSettings::class)->values()))->toThrow(AuthorizationException::class);
});

test('revoking admin authority blocks an already open settings form', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $component = Livewire::test(Settings::class);
    DB::table('user_platform_roles')->where('user_id', $admin->id)->delete();
    $component->call('save')->assertForbidden();
});

test('guests cannot open settings and public registration is disabled', function () {
    $this->get(Settings::getUrl())->assertRedirect(route('filament.app.auth.login'));
    $this->get('/register')->assertNotFound();
    Livewire::test(Login::class)->assertSee('host operator');
});

test('status reports sanitized readiness and live worker observations', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    Livewire::test(InstallationStatus::class)->assertSee(DB::connection()->getDriverName())->assertSee('Not verified')->assertSee('Review recommended')->assertSee('server-trusted')->assertDontSee(config('vaultigen.audit_key'));
    $this->artisan('vaultigen:heartbeat')->assertSuccessful();
    config(['queue.default' => 'database', 'mail.default' => 'smtp']);
    $status = app(InstallationHealth::class)->checks();
    expect($status['Scheduler'])->toBe('Recent heartbeat');
    expect($status['Queue'])->toBe('Recent worker heartbeat');
    expect($status['Mail'])->toContain('Configured');
    $report = collect(app(InstallationHealth::class)->report())->keyBy('label');
    expect($report['Scheduler']['state'])->toBe('healthy');
    expect($report['Queue']['state'])->toBe('healthy');
    expect($report['Mail']['state'])->toBe('healthy');
    $this->travel(4)->minutes();
    expect(app(InstallationHealth::class)->checks()['Scheduler'])->toContain('Not verified');
    expect(collect(app(InstallationHealth::class)->report())->keyBy('label')['Scheduler']['state'])->toBe('warning');
});

test('storage headroom warns when space is low or the path is missing', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    $storage = fn (): array => collect(app(InstallationHealth::class)->report())->keyBy('label')['Storage headroom'];

    expect($storage()['state'])->toBe('healthy');

    config(['vaultigen.storage.min_free_bytes' => PHP_INT_MAX]);
    expect($storage()['state'])->toBe('warning');

    config(['vaultigen.storage.headroom_path' => '/nonexistent/vaultigen-storage']);
    expect($storage()['state'])->toBe('warning');
    expect($storage()['status'])->toContain('unavailable');
});

test('admins send a test email from the mail check', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(InstallationStatus::class)
        ->mountAction('testMail')
        ->assertSchemaStateSet(['email' => $admin->email])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Test email sent');

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasTo($admin->email));
});

test('test email warns when the mail transport does not deliver', function () {
    Mail::fake();
    config(['mail.default' => 'log']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(InstallationStatus::class)
        ->callAction('testMail', ['email' => 'ops@example.test'])
        ->assertHasNoActionErrors()
        ->assertNotified('Mail transport is not delivering');

    Mail::assertNothingSent();
});

test('test email requires a valid recipient address', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(InstallationStatus::class)
        ->callAction('testMail', ['email' => 'not-an-email'])
        ->assertHasActionErrors(['email']);

    Mail::assertNothingSent();
});

test('revoked admins cannot send a test email from an open modal', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $component = Livewire::test(InstallationStatus::class)->mountAction('testMail');
    DB::table('user_platform_roles')->where('user_id', $admin->id)->delete();
    $component->callMountedAction()->assertForbidden();
    Mail::assertNothingSent();
});

test('heartbeat queues a probe and flags missing configuration', function () {
    Queue::fake([QueueHeartbeat::class]);
    $this->artisan('vaultigen:heartbeat')->assertSuccessful();
    Queue::assertPushed(QueueHeartbeat::class);
    config(['vaultigen.installation_id' => null, 'vaultigen.audit_key' => null, 'app.key' => null]);
    $status = app(InstallationHealth::class)->checks();
    expect($status['Installation identity'])->toContain('Missing');
    expect($status['Application key'])->toContain('Missing');
    expect($status['Independent security keys'])->toContain('Missing');
    $report = collect(app(InstallationHealth::class)->report())->keyBy('label');
    expect($report['Installation identity']['state'])->toBe('error');
    expect($report['Application key']['state'])->toBe('error');
    expect($report['Independent security keys']['state'])->toBe('error');
});

test('duplicate security recipients show field errors in the settings form', function () {
    $undoRepeaterFake = Repeater::fake();
    $this->actingAs(User::factory()->superAdmin()->withTwoFactor()->create());
    Livewire::test(Settings::class)->fillForm(['security_recipients' => [['email' => 'one@example.test'], ['email' => 'ONE@example.test']]])
        ->call('save')->assertHasFormErrors(['security_recipients.0.email']);
    $undoRepeaterFake();
});
