<?php

use App\Filament\Pages\ManageTeam;
use App\Filament\Pages\Teams;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamAdministration;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('admins create rename and manage team membership through the pages', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $member = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test(Teams::class)->assertSee('No teams yet')
        ->callAction('create', ['name' => ' Operations '])
        ->assertHasNoActionErrors();

    $team = DB::table('teams')->sole();
    $this->assertDatabaseHas('principals', ['id' => $team->principal_id, 'kind' => 'team']);
    $this->assertDatabaseHas('teams', ['id' => $team->id, 'normalized_name' => 'operations']);

    $page = Livewire::test(ManageTeam::class, ['team' => $team->id])
        ->assertSee('Team · Operations')
        ->set('teamName', 'Support')
        ->call('renameTeam')
        ->assertNotified('Team renamed')
        ->assertSet('teamName', 'Support');

    expect(DB::table('teams')->where('id', $team->id)->value('name'))->toBe('Support');

    $page->callAction(TestAction::make('addMember')->table(), ['user_id' => $member->id])
        ->assertHasNoActionErrors();
    expect(DB::table('team_memberships')->count())->toBe(1);

    Livewire::test(Teams::class)
        ->assertSee('Support')
        ->assertTableColumnStateSet('members_count', 1, $team->id);

    $page->callAction(TestAction::make('removeMember')->table($member))
        ->assertHasNoActionErrors()
        ->assertSee('No members yet');
    $this->assertDatabaseEmpty('team_memberships');
    $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'team.member_removed']);
});

test('normalized team names are unique and ordinary users cannot mutate teams', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    app(TeamAdministration::class)->save($admin, null, 'Operations');
    $team = DB::table('teams')->sole();

    expect(fn () => app(TeamAdministration::class)->save($admin, null, ' OPERATIONS '))->toThrow(ValidationException::class);

    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->get(Teams::getUrl())->assertForbidden();
    $this->actingAs($user)->get(ManageTeam::getUrl(['team' => $team->id]))->assertForbidden();
    expect(fn () => app(TeamAdministration::class)->save($user, null, 'Escalated'))->toThrow(AuthorizationException::class);
    expect(fn () => app(TeamAdministration::class)->membership($user, $team->id, $user->id, true))->toThrow(AuthorizationException::class);
    expect(DB::table('teams')->count())->toBe(1);
});

test('the teams table shows a sortable member count', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $quiet = Team::factory()->create(['name' => 'Quiet']);
    $busy = Team::factory()->create(['name' => 'Busy']);
    $members = User::factory()->count(3)->create();

    $this->actingAs($admin);
    $administration = app(TeamAdministration::class);
    foreach ($members as $member) {
        $administration->membership($admin, $busy->id, $member->id, true);
    }

    Livewire::test(Teams::class)
        ->assertCanSeeTableRecords([$quiet, $busy])
        ->assertTableColumnStateSet('members_count', 0, $quiet)
        ->assertTableColumnStateSet('members_count', 3, $busy)
        ->sortTable('members_count')
        ->assertCanSeeTableRecords([$quiet, $busy], inOrder: true)
        ->sortTable('members_count', 'desc')
        ->assertCanSeeTableRecords([$busy, $quiet], inOrder: true);
});

test('the manage team page surfaces a validation failure when renaming', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    app(TeamAdministration::class)->save($admin, null, 'Operations');

    $this->actingAs($admin);
    $team = DB::table('teams')->sole();

    Livewire::test(ManageTeam::class, ['team' => $team->id])
        ->set('teamName', '   ')
        ->call('renameTeam')
        ->assertNotified('Team not renamed');

    expect(DB::table('teams')->where('id', $team->id)->value('name'))->toBe('Operations');
});
