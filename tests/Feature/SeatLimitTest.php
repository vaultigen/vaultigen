<?php

use App\Filament\Pages\Users;
use App\Mail\InvitationMail;
use App\Models\User;
use App\Services\AccountAdministration;
use App\Services\AuditWriter;
use App\Services\Invitations;
use App\Services\Licensing\LicenseManager;
use App\Services\Licensing\SeatLimit;
use App\Services\Licensing\SeatLimitReached;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * An administrator plus active users so that $active seats are in use.
 */
function seatAdmin(int $active = 1): User
{
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    User::factory()->count($active - 1)->create();

    return $admin;
}

function seatInvitationToken(User $admin, string $email): string
{
    Mail::fake();
    config(['mail.default' => 'smtp']);
    app(Invitations::class)->invite($admin, $email);

    return Mail::sent(InvitationMail::class)->last()->token;
}

/**
 * @return array<string, string>
 */
function seatAcceptance(string $token, string $email): array
{
    return ['token' => $token, 'email' => $email, 'name' => 'New User', 'password' => 'a long synthetic password', 'password_confirmation' => 'a long synthetic password', 'policy' => '1'];
}

test('the free edition allows ten seats and counts open invitations but not closed ones or inactive users', function () {
    $admin = seatAdmin(8);
    User::factory()->inactive()->count(3)->create();
    app(Invitations::class)->invite($admin, 'expired@example.test');
    app(Invitations::class)->invite($admin, 'cancelled@example.test');
    DB::table('invitations')->where('intended_normalized_email', 'expired@example.test')->update(['expires_at' => now()->subMinute()]);
    DB::table('invitations')->where('intended_normalized_email', 'cancelled@example.test')->update(['cancelled_at' => now()]);

    app(Invitations::class)->invite($admin, 'ninth@example.test');
    app(Invitations::class)->invite($admin, 'tenth@example.test');

    expect(app(SeatLimit::class)->inUse())->toBe(10)
        ->and(fn () => app(Invitations::class)->invite($admin, 'eleventh@example.test'))
        ->toThrow(SeatLimitReached::class, __('licensing.seats.full_free', ['limit' => 10]))
        ->and(DB::table('invitations')->where('intended_normalized_email', 'eleventh@example.test')->exists())->toBeFalse();
});

test('resending an invitation never needs an extra seat', function () {
    $admin = seatAdmin(9);
    app(Invitations::class)->invite($admin, 'tenth@example.test');

    app(Invitations::class)->invite($admin, 'tenth@example.test');

    expect(app(SeatLimit::class)->inUse())->toBe(10)
        ->and(DB::table('invitations')->whereNull('cancelled_at')->count())->toBe(1);
});

test('a rejected addition is audited even though its transaction rolled back', function () {
    $admin = seatAdmin(10);

    expect(fn () => app(Invitations::class)->invite($admin, 'eleventh@example.test'))->toThrow(SeatLimitReached::class);

    $event = DB::table('audit_events')->where('action', 'seat_limit.reached')->sole();
    expect($event->actor_id)->toBe($admin->id)
        ->and($event->outcome)->toBe('denied')
        ->and(json_decode((string) $event->sanitized_context, true))->toBe(['context' => 'invitation', 'in_use' => 10, 'limit' => 10])
        ->and(DB::table('audit_events')->where('action', 'invitation.created')->exists())->toBeFalse();
});

test('an invitation cannot be accepted after the limit dropped, and can be retried once a seat is free', function () {
    vaultigen_install_test_license(['max_users' => 50]);
    $admin = seatAdmin(12);
    $token = seatInvitationToken($admin, 'late@example.test');
    app(LicenseManager::class)->remove($admin);

    $this->post(route('invitation.store'), seatAcceptance($token, 'late@example.test'))
        ->assertSessionHasErrors(['email' => __('licensing.seats.acceptance_unavailable')]);
    expect(User::where('normalized_email', 'late@example.test')->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('action', 'seat_limit.reached')->sole()->actor_id)->toBeNull();

    User::query()->whereKeyNot($admin->id)->limit(3)->update(['is_active' => false]);

    $this->post(route('invitation.store'), seatAcceptance($token, 'late@example.test'))->assertSessionHasNoErrors();
    expect(User::where('normalized_email', 'late@example.test')->exists())->toBeTrue();
});

test('reactivation needs a free seat', function () {
    $admin = seatAdmin(10);
    $inactive = User::factory()->inactive()->create();
    $active = User::query()->whereKeyNot($admin->id)->where('is_active', true)->firstOrFail();

    expect(fn () => app(AccountAdministration::class)->activate($admin, $inactive->id, true))->toThrow(SeatLimitReached::class)
        ->and($inactive->refresh()->is_active)->toBeFalse();

    app(AccountAdministration::class)->activate($admin, $active->id, false);
    app(AccountAdministration::class)->activate($admin, $inactive->id, true);
    app(AccountAdministration::class)->activate($admin, $inactive->id, true);

    expect($inactive->refresh()->is_active)->toBeTrue()->and(app(SeatLimit::class)->inUse())->toBe(10);
});

test('the first administrator can always be bootstrapped but additional administrators need a seat', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    User::factory()->count(10)->create();

    $this->artisan('vaultigen:bootstrap', ['email' => 'first@example.test'])
        ->expectsQuestion('Administrator password', 'a long synthetic passphrase')
        ->assertSuccessful();

    $this->artisan('vaultigen:bootstrap', ['email' => 'second@example.test'])
        ->expectsQuestion('Administrator password', 'another synthetic passphrase')
        ->expectsOutput(__('licensing.seats.full_free', ['limit' => 10]))
        ->assertFailed();
    expect(User::where('normalized_email', 'second@example.test')->exists())->toBeFalse();
});

test('the seat limit follows the license', function (array $overrides, ?string $releaseDate, int $active, bool $allowed) {
    config(['vaultigen.release_date' => $releaseDate]);
    vaultigen_install_test_license($overrides);
    $admin = seatAdmin($active);

    $invite = fn () => app(Invitations::class)->invite($admin, 'next@example.test');

    $allowed ? expect($invite)->not->toThrow(SeatLimitReached::class) : expect($invite)->toThrow(SeatLimitReached::class);
})->with([
    'unlimited license' => [['max_users' => null], '2026-10-01', 30, true],
    'license below its cap' => [['max_users' => 50], '2026-10-01', 49, true],
    'license at its cap' => [['max_users' => 50], '2026-10-01', 50, false],
    'update window expired' => [['max_users' => null], '2027-10-02', 10, false],
]);

test('an expired update window keeps every existing user working and only blocks additions', function () {
    config(['vaultigen.release_date' => '2027-10-02']);
    vaultigen_install_test_license();
    $admin = seatAdmin(40);
    $member = User::factory()->withTwoFactor()->create();

    $this->actingAs($member)->get('/')->assertOk();

    expect(User::query()->where('is_active', true)->count())->toBe(41)
        ->and(fn () => app(Invitations::class)->invite($admin, 'next@example.test'))
        ->toThrow(SeatLimitReached::class, __('licensing.seats.full_updates_expired', ['limit' => 10]));
});

test('removing the license never deactivates anyone', function () {
    vaultigen_install_test_license();
    $admin = seatAdmin(25);

    app(LicenseManager::class)->remove($admin);

    expect(User::query()->where('is_active', true)->count())->toBe(25);
});

test('the users page shows the seat counter and disables inviting when no seat is free', function () {
    $admin = seatAdmin(10);
    $this->actingAs($admin);

    Livewire::test(Users::class)
        ->assertSee('10 of 10 seats in use')
        ->assertActionDisabled('invite');

    vaultigen_install_test_license();
    Livewire::test(Users::class)
        ->assertSee('10 seats in use (active users and open invitations); your license allows unlimited users.')
        ->assertActionEnabled('invite');
});

test('the invitation form reports a seat taken by a concurrent request and stays open', function () {
    $admin = seatAdmin(10);
    $this->actingAs($admin);
    app()->instance(SeatLimit::class, new class(app(LicenseManager::class), app(AuditWriter::class)) extends SeatLimit
    {
        public function hasRoom(): bool
        {
            return true;
        }
    });

    Livewire::test(Users::class)->mountAction('invite')->fillForm(['email' => 'late@example.test'])
        ->callMountedAction()
        ->assertNotified(__('licensing.seats.limit_reached'))
        ->assertActionMounted('invite');
    expect(DB::table('audit_events')->where('action', 'seat_limit.reached')->exists())->toBeTrue();
});

test('activating on the users page reports a full seat limit instead of failing', function () {
    $admin = seatAdmin(9);
    $first = User::factory()->inactive()->create(['name' => 'Aaron First']);
    $second = User::factory()->inactive()->create(['name' => 'Zoe Second']);
    $this->actingAs($admin);

    Livewire::test(Users::class)
        ->callTableBulkAction('activate', [$first, $second])
        ->assertNotified(__('licensing.seats.limit_reached'));
    Livewire::test(Users::class)
        ->callAction('activate', arguments: ['id' => $second->id])
        ->assertNotified(__('licensing.seats.limit_reached'));

    expect($first->refresh()->is_active)->toBeTrue()->and($second->refresh()->is_active)->toBeFalse();
});
