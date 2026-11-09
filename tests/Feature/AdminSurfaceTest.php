<?php

use App\Filament\Pages\Activity;
use App\Filament\Pages\GlobalTags;
use App\Filament\Pages\InstallationStatus;
use App\Filament\Pages\ManageTeam;
use App\Filament\Pages\MyVaults;
use App\Filament\Pages\Teams;
use App\Filament\Pages\Users;
use App\Mail\TestMail;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\TeamAdministration;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;

test('the activity table renders and humanizes every recorded action', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $inactive = User::factory()->inactive()->create();
    $actions = [
        'account.activated', 'account.deactivated', 'account.roles_changed',
        'auth.admin_factor_reset', 'auth.local_recovery', 'auth.login', 'auth.logout',
        'auth.multi_factor', 'auth.other_sessions_revoked', 'auth.reauthentication',
        'auth.session_revoked', 'grant.created', 'grant.revoked', 'installation.bootstrapped',
        'installation.local_seeded', 'installation.settings_changed', 'invitation.accepted',
        'invitation.cancelled', 'invitation.created', 'license.installed', 'license.removed', 'seat_limit.reached', 'team.created', 'team.member_added',
        'team.member_removed', 'team.renamed', 'user.administrator_created', 'user.created',
        'user.updated', 'vault.created', 'vault.updated', 'custom.unknown_action',
        'import.started', 'import.completed', 'import.failed', 'import.cancelled',
    ];
    $audit = app(AuditWriter::class);
    foreach ($actions as $action) {
        $audit->record($action, $inactive->id);
    }

    $this->actingAs($admin);
    $page = Livewire::test(Activity::class)->set('tableRecordsPerPage', 100);

    foreach ($actions as $action) {
        $page->assertSee($action);
    }
    $page->assertSee('Vault grant created')
        ->assertSee('Vault updated')
        ->assertSee('Import completed')
        ->assertSee('License installed')
        ->assertSee('Custom Unknown Action')
        ->assertSee('Deactivated account')
        ->assertSee($inactive->name);
});

test('installation status shows failed required checks and a fully healthy summary', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    config(['vaultigen.installation_id' => null]);
    Livewire::test(InstallationStatus::class)
        ->assertSee('Installation needs attention')
        ->assertSee('required configuration check failed')
        ->assertSee('still need review');

    config(['vaultigen.installation_id' => '550e8400-e29b-41d4-a716-446655440000']);
    $this->artisan('vaultigen:heartbeat')->assertSuccessful();
    config(['queue.default' => 'database', 'mail.default' => 'smtp']);
    Livewire::test(InstallationStatus::class)->assertSee('All checks passing');

    config(['vaultigen.installation_id' => null]);
    Livewire::test(InstallationStatus::class)
        ->assertSee('required configuration check failed.')
        ->assertDontSee('still need');
});

test('the manage team page removes a member through the record action', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $member = User::factory()->withTwoFactor()->create();
    $team = Team::factory()->create(['name' => 'Operations']);
    app(TeamAdministration::class)->membership($admin, $team->id, $member->id, true);
    $this->actingAs($admin);

    Livewire::test(ManageTeam::class, ['team' => $team->id])
        ->callTableAction('removeMember', $member)
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseEmpty('team_memberships');
});

test('the installation status test mail action reports failures and success', function () {
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('transport secret'));
    Livewire::test(InstallationStatus::class)
        ->callAction('testMail', ['email' => 'ops@example.test'])
        ->assertNotified('Test email was not sent');

    Mail::swap(new MailManager($this->app));
    Mail::fake();
    Livewire::test(InstallationStatus::class)
        ->callAction('testMail', ['email' => 'ops@example.test'])
        ->assertNotified('Test email sent');
    Mail::assertSent(TestMail::class);
    expect((new TestMail)->render())->toContain('This is a test email from Vaultigen');
});

test('the manage team page filters by platform role and removes members in bulk', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $auditor = User::factory()->auditor()->withTwoFactor()->create();
    $member = User::factory()->withTwoFactor()->create();
    $team = Team::factory()->create(['name' => 'Operations']);
    app(TeamAdministration::class)->membership($admin, $team->id, $auditor->id, true);
    app(TeamAdministration::class)->membership($admin, $team->id, $member->id, true);
    $this->actingAs($admin);

    $page = Livewire::test(ManageTeam::class, ['team' => $team->id]);
    $page->set('tableFilters', ['platform_role' => ['value' => 'auditor']]);
    expect($page->instance()->getFilteredTableQuery()->pluck('email')->all())->toBe([$auditor->email]);

    Livewire::test(ManageTeam::class, ['team' => $team->id])
        ->callTableBulkAction('removeMembers', [$auditor, $member]);
    $this->assertDatabaseEmpty('team_memberships');
});

test('the users page filters by platform role and runs bulk activation actions', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $auditor = User::factory()->auditor()->withTwoFactor()->create();
    $this->actingAs($admin);
    $page = Livewire::test(Users::class);

    $page->set('tableFilters', ['platform_role' => ['value' => 'auditor']]);
    expect($page->instance()->getFilteredTableQuery()->pluck('email')->all())->toBe([$auditor->email]);

    $page->set('tableFilters', ['platform_role' => ['value' => null]]);
    expect($page->instance()->getFilteredTableQuery()->count())->toBe(2);

    $page->callTableBulkAction('deactivate', [$auditor]);
    expect($auditor->fresh()->is_active)->toBeFalse();

    $page->callTableBulkAction('activate', [$auditor->fresh()]);
    expect($auditor->fresh()->is_active)->toBeTrue();

    $this->assertDatabaseHas('audit_events', ['action' => 'account.deactivated']);
    $this->assertDatabaseHas('audit_events', ['action' => 'account.activated']);
});

test('invitations that cannot be delivered show a warning notification', function () {
    config(['mail.default' => 'log']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(Users::class)
        ->callAction('invite', ['email' => 'pending@example.test'])
        ->assertHasNoActionErrors()
        ->assertNotified('Invitation created, but the email was not sent');

    $this->assertDatabaseHas('invitations', ['intended_normalized_email' => 'pending@example.test', 'failure_code' => 'mail_transport_unconfigured']);
});

test('navigation entries show the number of their records as a badge', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    vaultigen_make_vault($admin, $alice);
    vaultigen_make_global_tag($admin, 'Compliance');
    Team::factory()->count(2)->create();

    $this->actingAs($alice);

    expect(MyVaults::getNavigationBadge())->toBe('1')
        ->and(Teams::getNavigationBadge())->toBe('2')
        ->and(Users::getNavigationBadge())->toBe('2')
        ->and(GlobalTags::getNavigationBadge())->toBe('1');
});

test('navigation badges are hidden from signed out visitors', function () {
    expect(MyVaults::getNavigationBadge())->toBeNull();
});

test('admin pages require authentication before any vault data is queried', function () {
    $this->get(Users::getUrl())->assertRedirect(route('filament.app.auth.login'));
    $this->get(Teams::getUrl())->assertRedirect(route('filament.app.auth.login'));
    $this->get(InstallationStatus::getUrl())->assertRedirect(route('filament.app.auth.login'));

    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    expect(DB::table('vaults')->count())->toBe(0);
});
