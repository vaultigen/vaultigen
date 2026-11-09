<?php

use App\Filament\Livewire\EmergencyBanner;
use App\Filament\Pages\EmergencyAccess as EmergencyAccessPage;
use App\Filament\Pages\VaultEntries;
use App\Mail\EmergencyAccessStarted;
use App\Models\Entry;
use App\Models\User;
use App\Models\Vault;
use App\Notifications\VerifyEmailAuthenticationCode;
use App\Services\AccountAdministration;
use App\Services\AdminRecovery;
use App\Services\AuditWriter;
use App\Services\EmergencyAccess;
use App\Services\EntryVault;
use App\Services\InstallationSettings;
use App\Services\Offboarding;
use App\Services\VaultAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @return array{admin: User, otherAdmin: User, alice: User, vault: Vault, entry: Entry}
 */
function vaultigen_emergency_fixture(): array
{
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $otherAdmin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice, ['name' => 'Operations']);
    $entry = vaultigen_make_entry($alice, $vault);

    return compact('admin', 'otherAdmin', 'alice', 'vault', 'entry');
}

function vaultigen_emergency_start(User $admin, Vault $vault, string $reason = 'Vendor incident'): string
{
    $code = AppAuthentication::make()->getCurrentCode($admin, $admin->app_authentication_secret);

    return app(EmergencyAccess::class)->start($admin, $vault->id, $reason, 'INC-1', 'password', $code);
}

test('emergency access is read-only, scoped, and audited while active', function () {
    ['admin' => $admin, 'otherAdmin' => $otherAdmin, 'alice' => $alice, 'vault' => $vault, 'entry' => $entry] = vaultigen_emergency_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Servers');

    $sessionId = vaultigen_emergency_start($admin, $vault);
    $session = DB::table('emergency_access_sessions')->where('id', $sessionId)->sole();

    expect($session->super_admin_user_id)->toBe($admin->id)
        ->and($session->vault_id)->toBe($vault->id)
        ->and($session->closed_at)->toBeNull()
        ->and((string) $session->reason)->toBe('Vendor incident')
        ->and((string) $session->incident_reference)->toBe('INC-1')
        ->and($session->notification_sequence)->not->toBeNull();

    $access = app(VaultAccess::class);
    expect($access->hasEmergencyAccess($admin, $vault->id))->toBeTrue()
        ->and($access->allows($admin, $vault->id, 'metadata.view'))->toBeTrue()
        ->and($access->allows($admin, $vault->id, 'secret.reveal'))->toBeTrue()
        ->and($access->allowsOnEntry($admin, $entry, 'metadata.view'))->toBeTrue()
        ->and($access->allowsOnEntry($admin, $entry, 'secret.reveal'))->toBeTrue()
        ->and($access->allowsOnEntry($admin, $entry, 'entry.update'))->toBeFalse()
        ->and($access->allowsOnEntry($admin, $entry, 'entry.delete'))->toBeFalse()
        ->and($access->visibleVaults($admin)->pluck('id')->all())->toContain($vault->id)
        ->and($access->visibleEntries($admin, $vault->id)->pluck('id')->all())->toContain($entry->id)
        ->and($access->visibleFolders($admin, $vault->id)->pluck('id')->all())->toContain($folder->id);

    // Reveal succeeds without any ordinary grant and is audited.
    expect(app(EntryVault::class)->reveal($admin, $entry->id, 'password'))->toBe('correct-horse-battery-staple-42');
    $this->assertDatabaseHas('audit_events', ['action' => 'vault.emergency_started', 'vault_id' => $vault->id]);
    $this->assertDatabaseHas('security_notification_deliveries', ['recipient' => $otherAdmin->email]);

    app(EmergencyAccess::class)->end($admin, $sessionId);
    expect($access->hasEmergencyAccess($admin, $vault->id))->toBeFalse()
        ->and($access->allows($admin, $vault->id, 'secret.reveal'))->toBeFalse()
        ->and(fn () => app(EntryVault::class)->reveal($admin, $entry->id, 'password'))->toThrow(NotFoundHttpException::class);
    $this->assertDatabaseHas('audit_events', ['action' => 'vault.emergency_ended', 'vault_id' => $vault->id]);
    expect(app(AuditWriter::class)->verify())->toBeTrue();
});

test('starting emergency access requires valid input and strong reauthentication', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_emergency_fixture();
    $service = app(EmergencyAccess::class);
    $validCode = AppAuthentication::make()->getCurrentCode($admin, $admin->app_authentication_secret);

    expect(fn () => $service->start($admin, $vault->id, '', null, 'password', $validCode))->toThrow(ValidationException::class);
    expect(fn () => $service->start($admin, 'not-a-uuid', 'Reason', null, 'password', $validCode))->toThrow(ValidationException::class);
    expect(fn () => $service->start($admin, (string) Str::uuid(), 'Reason', null, 'password', $validCode))->toThrow(ValidationException::class);
    expect(fn () => $service->start($admin, $vault->id, str_repeat('r', 1001), null, 'password', $validCode))->toThrow(ValidationException::class);
    expect(fn () => $service->start($admin, $vault->id, 'Reason', str_repeat('i', 256), 'password', $validCode))->toThrow(ValidationException::class);
    expect(fn () => $service->start($admin, $vault->id, 'Reason', null, 'wrong', $validCode))->toThrow(ValidationException::class);
    expect(fn () => $service->start($alice, $vault->id, 'Reason', null, 'password', $validCode))->toThrow(AuthorizationException::class);

    expect(DB::table('emergency_access_sessions')->whereNull('closed_at')->count())->toBe(0);
});

test('a new session closes the previous active session and an unknown end is a 404', function () {
    ['admin' => $admin, 'vault' => $vault] = vaultigen_emergency_fixture();
    $service = app(EmergencyAccess::class);

    $first = vaultigen_emergency_start($admin, $vault, 'First');
    Cache::flush();
    $second = vaultigen_emergency_start($admin, $vault, 'Second');

    expect(DB::table('emergency_access_sessions')->where('id', $first)->value('closed_at'))->not->toBeNull()
        ->and(DB::table('emergency_access_sessions')->where('id', $second)->value('closed_at'))->toBeNull();

    expect(fn () => $service->end($admin, (string) Str::uuid()))->toThrow(NotFoundHttpException::class);

    $service->end($admin, $second);
    $events = DB::table('audit_events')->where('action', 'vault.emergency_ended')->count();
    $service->end($admin, $second);
    expect(DB::table('audit_events')->where('action', 'vault.emergency_ended')->count())->toBe($events);
});

test('sole administrator notifies configured recipients and delivers mail', function () {
    ['admin' => $admin, 'vault' => $vault] = vaultigen_emergency_fixture();
    User::query()->whereKeyNot($admin->id)->update(['is_active' => false]);
    app(InstallationSettings::class)->save($admin, [...app(InstallationSettings::class)->values(), 'security_recipients' => ['security@example.test']]);

    $sessionId = vaultigen_emergency_start($admin, $vault);

    expect(DB::table('emergency_access_sessions')->where('id', $sessionId)->value('notification_sequence'))->not->toBeNull()
        ->and(DB::table('security_notification_deliveries')->where('recipient', 'security@example.test')->count())->toBe(1);

    Mail::fake();
    config(['mail.default' => 'smtp']);
    $this->artisan('vaultigen:deliver-security-notifications')->assertSuccessful();
    Mail::assertSent(EmergencyAccessStarted::class, fn (EmergencyAccessStarted $mail): bool => $mail->hasTo('security@example.test'));

    $mail = new EmergencyAccessStarted(7);
    $mail->assertHasSubject('Vaultigen: emergency administrator access started');
    $mail->assertSeeInText('Audit event: 7');
});

test('a sole administrator without recipients records no notification target', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);

    $sessionId = vaultigen_emergency_start($admin, $vault);

    expect(DB::table('emergency_access_sessions')->where('id', $sessionId)->value('notification_sequence'))->not->toBeNull()
        ->and(DB::table('security_notification_deliveries')->count())->toBe(0);
});

test('expired sessions stop granting access and are closed by the scheduler', function () {
    ['admin' => $admin, 'vault' => $vault, 'entry' => $entry] = vaultigen_emergency_fixture();
    $this->freezeTime();
    $service = app(EmergencyAccess::class);
    $sessionId = vaultigen_emergency_start($admin, $vault);

    expect($service->activeFor($admin)?->id)->toBe($sessionId);
    expect(app(VaultAccess::class)->allowsOnEntry($admin, $entry, 'secret.reveal'))->toBeTrue();

    $this->travel(31)->minutes();

    expect($service->activeFor($admin))->toBeNull()
        ->and(app(VaultAccess::class)->allowsOnEntry($admin, $entry, 'secret.reveal'))->toBeFalse();

    $this->artisan('vaultigen:expire-emergency-access')->assertSuccessful();
    expect(DB::table('emergency_access_sessions')->where('id', $sessionId)->value('closed_at'))->not->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'vault.emergency_ended')->where('sanitized_context', 'like', '%expired%')->count())->toBe(1);

    $this->artisan('vaultigen:expire-emergency-access')->assertSuccessful();
    expect(DB::table('audit_events')->where('action', 'vault.emergency_ended')->where('sanitized_context', 'like', '%expired%')->count())->toBe(1);
});

test('emergency access guards inactive and malformed actors', function () {
    ['admin' => $admin, 'vault' => $vault] = vaultigen_emergency_fixture();
    $access = app(VaultAccess::class);

    expect($access->hasEmergencyAccess($admin, 'not-a-uuid'))->toBeFalse();

    $inactive = User::factory()->superAdmin()->inactive()->create();
    expect($access->hasEmergencyAccess($inactive, $vault->id))->toBeFalse();

    expect(app(EmergencyAccess::class)->activeFor($admin))->toBeNull();
});

test('non- administrators cannot end emergency sessions', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_emergency_fixture();
    $sessionId = vaultigen_emergency_start($admin, $vault);

    expect(fn () => app(EmergencyAccess::class)->end($alice, $sessionId))->toThrow(AuthorizationException::class);
});

test('the emergency page starts and ends a session through the UI', function () {
    ['admin' => $admin, 'vault' => $vault] = vaultigen_emergency_fixture();
    $this->actingAs($admin);
    $code = AppAuthentication::make()->getCurrentCode($admin, $admin->app_authentication_secret);

    Livewire::test(EmergencyAccessPage::class)
        ->assertOk()
        ->callAction('startEmergency', [
            'vault_id' => $vault->id,
            'reason' => 'Vendor incident',
            'incident_reference' => 'INC-2',
            'password' => 'password',
            'code' => $code,
        ])
        ->assertNotified(__('emergency.notifications.started'))
        ->assertSee('Operations');

    Livewire::test(EmergencyAccessPage::class)->callAction('endEmergency')
        ->assertNotified(__('emergency.notifications.ended'));

    expect(DB::table('emergency_access_sessions')->whereNull('closed_at')->count())->toBe(0);
});

test('the emergency start form can request an email authentication code', function () {
    vaultigen_configure_mfa(['email']);
    vaultigen_fake_email_code();
    Notification::fake();

    $admin = User::factory()->superAdmin()->withEmailTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    vaultigen_make_vault($admin, $alice);
    $this->actingAs($admin);

    Livewire::test(EmergencyAccessPage::class)
        ->mountAction('startEmergency')
        ->assertActionExists(TestAction::make('codeSendCode')->schemaComponent('code', 'mountedActionSchema0'))
        ->callAction(TestAction::make('codeSendCode')->schemaComponent('code', 'mountedActionSchema0'));

    Notification::assertSentTo($admin, VerifyEmailAuthenticationCode::class);
});

test('the emergency start form reports a throttled email code request', function () {
    vaultigen_configure_mfa(['email']);
    vaultigen_fake_email_code();
    Notification::fake();

    $admin = User::factory()->superAdmin()->withEmailTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    vaultigen_make_vault($admin, $alice);
    $this->actingAs($admin);

    $send = fn () => TestAction::make('codeSendCode')->schemaComponent('code', 'mountedActionSchema0');

    Livewire::test(EmergencyAccessPage::class)->mountAction('startEmergency')->callAction($send())->assertNotified('A fresh code has been emailed to you.');
    Livewire::test(EmergencyAccessPage::class)->mountAction('startEmergency')->callAction($send())->assertNotified('A fresh code has been emailed to you.');
    Livewire::test(EmergencyAccessPage::class)->mountAction('startEmergency')->callAction($send())->assertNotified('A code was sent recently. Try again in a minute.');
});

test('the emergency page reports a failed strong-authentication attempt', function () {
    ['admin' => $admin, 'vault' => $vault] = vaultigen_emergency_fixture();
    $this->actingAs($admin);

    Livewire::test(EmergencyAccessPage::class)
        ->callAction('startEmergency', [
            'vault_id' => $vault->id,
            'reason' => 'Vendor incident',
            'incident_reference' => null,
            'password' => 'wrong',
            'code' => '000000',
        ])
        ->assertNotified(__('emergency.notifications.failed'));

    expect(DB::table('emergency_access_sessions')->count())->toBe(0);
});

test('the emergency banner appears only while a session is active', function () {
    ['admin' => $admin, 'vault' => $vault] = vaultigen_emergency_fixture();
    $this->actingAs($admin);

    $this->get('/')->assertOk()->assertDontSee('Emergency access active.');

    vaultigen_emergency_start($admin, $vault);
    $this->get('/')->assertOk()->assertSee('Emergency access active.');
});

test('the emergency banner ends an active session in place', function () {
    ['admin' => $admin, 'vault' => $vault] = vaultigen_emergency_fixture();
    $this->actingAs($admin);
    $sessionId = vaultigen_emergency_start($admin, $vault);

    Livewire::test(EmergencyBanner::class)
        ->assertSee('Emergency access active.')
        ->assertSee('End access')
        ->call('end')
        ->assertRedirect(EmergencyAccessPage::getUrl());

    expect(DB::table('emergency_access_sessions')->where('id', $sessionId)->value('closed_at'))->not->toBeNull();
});

test('the active emergency vault links to its entries', function () {
    ['admin' => $admin, 'vault' => $vault] = vaultigen_emergency_fixture();
    $this->actingAs($admin);
    vaultigen_emergency_start($admin, $vault);

    Livewire::test(EmergencyAccessPage::class)
        ->assertSee(VaultEntries::getUrl(['vault' => $vault->id]));
});

test('the emergency banner is inert without an active session', function () {
    ['admin' => $admin] = vaultigen_emergency_fixture();
    $this->actingAs($admin);

    Livewire::test(EmergencyBanner::class)
        ->assertDontSee('Emergency access active.')
        ->call('end')
        ->assertDontSee('Emergency access active.');
});

test('the emergency banner does nothing for a signed-out visitor', function () {
    Livewire::test(EmergencyBanner::class)
        ->call('end')
        ->assertDontSee('Emergency access active.');
});

test('the emergency page shows notification delivery state including failures', function () {
    ['admin' => $admin, 'otherAdmin' => $otherAdmin, 'vault' => $vault] = vaultigen_emergency_fixture();
    $this->actingAs($admin);

    $first = vaultigen_emergency_start($admin, $vault, 'One');
    Cache::flush();
    $second = vaultigen_emergency_start($admin, $vault, 'Two');

    $firstSequence = DB::table('emergency_access_sessions')->where('id', $first)->value('notification_sequence');
    $secondSequence = DB::table('emergency_access_sessions')->where('id', $second)->value('notification_sequence');
    DB::table('security_notification_deliveries')->where('audit_sequence', $firstSequence)->update(['failure_code' => 'mail_delivery_failed', 'attempts' => 1]);
    DB::table('security_notification_deliveries')->where('audit_sequence', $secondSequence)->update(['delivered_at' => now()]);

    Livewire::test(EmergencyAccessPage::class)
        ->assertSee($otherAdmin->email)
        ->assertSee('Failed')
        ->assertSee('Sent');
});

test('ordinary users cannot open the emergency page', function () {
    ['alice' => $alice] = vaultigen_emergency_fixture();
    $this->actingAs($alice);

    $this->get(EmergencyAccessPage::getUrl())->assertForbidden();
    Livewire::test(EmergencyAccessPage::class)->assertForbidden();
});

test('losing Super Admin ends emergency access and regaining it does not revive it', function () {
    ['admin' => $admin, 'otherAdmin' => $otherAdmin, 'vault' => $vault, 'entry' => $entry] = vaultigen_emergency_fixture();
    vaultigen_emergency_start($admin, $vault);

    app(AccountAdministration::class)->roles($otherAdmin, $admin->id, []);
    $admin = $admin->fresh();

    expect(fn () => app(EntryVault::class)->reveal($admin, $entry->id, 'password'))->toThrow(NotFoundHttpException::class);
    expect(DB::table('emergency_access_sessions')->whereNull('closed_at')->count())->toBe(0);
    $this->assertDatabaseHas('emergency_access_sessions', ['super_admin_user_id' => $admin->id, 'closed_by_user_id' => $otherAdmin->id]);
    expect(DB::table('audit_events')->where('action', 'vault.emergency_ended')->where('sanitized_context', 'like', '%revoked%')->count())->toBe(1);

    app(AccountAdministration::class)->roles($otherAdmin, $admin->id, ['super_admin']);

    expect(app(VaultAccess::class)->hasEmergencyAccess($admin->fresh(), $vault->id))->toBeFalse();
});

test('an open session grants nothing once its holder no longer qualifies', function () {
    ['admin' => $admin, 'vault' => $vault, 'entry' => $entry] = vaultigen_emergency_fixture();
    vaultigen_emergency_start($admin, $vault);
    $access = app(VaultAccess::class);

    expect($access->hasEmergencyAccess($admin, $vault->id))->toBeTrue()
        ->and($access->visibleVaults($admin)->whereKey($vault->id)->exists())->toBeTrue();

    // A role removed outside the service must fail closed on every read path.
    DB::table('user_platform_roles')->where('user_id', $admin->id)->delete();

    expect($access->hasEmergencyAccess($admin, $vault->id))->toBeFalse()
        ->and($access->visibleVaults($admin)->whereKey($vault->id)->exists())->toBeFalse()
        ->and($access->visibleEntries($admin, $vault->id)->whereKey($entry->id)->exists())->toBeFalse();
});

test('other withdrawals of authority also close the session', function (Closure $withdraw) {
    ['admin' => $admin, 'otherAdmin' => $otherAdmin, 'vault' => $vault] = vaultigen_emergency_fixture();
    vaultigen_emergency_start($admin, $vault);

    $withdraw($otherAdmin, $admin);

    expect(DB::table('emergency_access_sessions')->where('super_admin_user_id', $admin->id)->whereNull('closed_at')->count())->toBe(0);
})->with([
    'deactivation' => fn ($actor, $target) => app(AccountAdministration::class)->activate($actor, $target->id, false),
    'factor reset' => fn ($actor, $target) => app(AccountAdministration::class)->resetFactors($actor, $target->id, 'Lost phone', 'password', AppAuthentication::make()->getCurrentCode($actor, $actor->app_authentication_secret)),
    'offboarding' => fn ($actor, $target) => app(Offboarding::class)->offboard($actor, $target, []),
]);

test('admin recovery closes the recovered administrator session', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, User::factory()->withTwoFactor()->create());
    vaultigen_emergency_start($admin, $vault);

    app(AdminRecovery::class)->recover($admin->email, 'A-Temporary-Password-1');

    expect(DB::table('emergency_access_sessions')->whereNull('closed_at')->count())->toBe(0);
});
