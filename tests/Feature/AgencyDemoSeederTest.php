<?php

use App\Models\Entry;
use App\Models\User;
use App\Models\Vault;
use App\Services\EntryVault;
use App\Services\Favorites;
use App\Services\PersonalVaults;
use App\Services\VaultAccess;
use Database\Seeders\AgencyDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

test('agency demo gives the administrator populated client and personal vaults with team scoped access', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    $admin = User::factory()->superAdmin()->create(['email' => 'admin@admin.com']);
    $designer = User::factory()->create(['email' => 'alice@example.com']);
    $developer = User::factory()->create(['email' => 'bob@example.com']);
    $manager = User::factory()->create(['email' => 'manager@manager.com']);
    Http::preventStrayRequests();

    $this->seed(AgencyDemoSeeder::class);

    $access = app(VaultAccess::class);
    foreach (['Apple', 'Tesla', 'Airbnb', 'Patagonia', 'Spotify', 'Nike'] as $client) {
        $vault = Vault::query()->where('name', $client)->sole();
        expect($access->permission($admin, $vault->id))->toBe('manager');
        expect($access->visibleEntries($admin, $vault->id)->count())->toBe(16);
        expect($access->visibleFolders($admin, $vault->id)->pluck('name')->sort()->values()->all())
            ->toBe(['Design', 'Development', 'Project Management']);
        expect($access->visibleEntries($designer, $vault->id)->count())->toBe(4);
        expect($access->visibleEntries($developer, $vault->id)->count())->toBe(8);
        expect($access->visibleEntries($manager, $vault->id)->count())->toBe(4);
    }

    $personal = Vault::query()->where('private_workspace_owner_user_id', $admin->id)->sole();
    expect($access->visibleEntries($admin, $personal->id)->count())->toBe(5);
    expect($access->hasVaultAccess($designer, $personal->id))->toBeFalse();
    expect(app(Favorites::class)->favoriteVaults($admin)->pluck('name')->all())->toBe(['Agency HQ', 'Apple', 'Tesla']);
    expect(app(Favorites::class)->favoriteEntries($admin))->toHaveCount(8);
    expect(config('vaultigen.mfa_disabled'))->toBeFalse();
    $this->assertDatabaseCount('users', 4);
    $this->assertDatabaseCount('entries', 110);
    $figma = Entry::query()->where('vault_id', Vault::query()->where('name', 'Apple')->value('id'))->where('title', 'Figma — design system')->sole();
    expect(app(EntryVault::class)->reveal($admin, $figma->id, 'username'))->toBe('design+apple@northstar.example');
    $this->assertDatabaseHas('tags', ['name' => 'Production', 'color' => 'danger']);
    Http::assertNothingSent();
});

test('repeating the agency demo preserves credentials edits favorites and unrelated data', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    $admin = User::factory()->superAdmin()->withTwoFactor()->create(['email' => 'admin@admin.com']);
    $unrelated = vaultigen_make_vault($admin, $admin, ['name' => 'Existing client']);
    $unrelatedEntry = vaultigen_make_entry($admin, $unrelated, ['title' => 'Existing credentials']);
    $this->seed(AgencyDemoSeeder::class);
    $apple = Vault::query()->where('name', 'Apple')->sole();
    $entry = Entry::query()->where('vault_id', $apple->id)->where('title', 'AWS — production console')->sole();
    $entries = app(EntryVault::class);
    $password = $entries->reveal($admin, $entry->id, 'password');
    $entries->update($admin, $entry->id, vaultigen_entry_update($admin, [
        'title' => 'AWS — release console',
        'username' => 'release@northstar.example',
        'password' => $password,
    ]), $entry->lock_version);
    $personalEntry = Entry::query()->where('title', 'Google Workspace — work account')->sole();
    $entries->update($admin, $personalEntry->id, vaultigen_entry_update($admin, ['title' => 'My work mailbox']), $personalEntry->lock_version);
    $counts = [
        'vaults' => Vault::count(),
        'entries' => Entry::count(),
        'audit_events' => DB::table('audit_events')->count(),
        'folder_grants' => DB::table('folder_grants')->count(),
    ];
    $adminPassword = $admin->password;

    $this->seed(AgencyDemoSeeder::class);

    foreach ($counts as $table => $count) {
        $this->assertDatabaseCount($table, $count);
    }
    expect($admin->fresh()->password)->toBe($adminPassword);
    expect($entries->reveal($admin, $entry->id, 'password'))->toBe($password);
    $this->assertDatabaseHas('entries', ['id' => $entry->id, 'title' => 'AWS — release console']);
    $this->assertDatabaseHas('entries', ['id' => $personalEntry->id, 'title' => 'My work mailbox']);
    $this->assertDatabaseHas('entries', ['id' => $unrelatedEntry->id, 'title' => 'Existing credentials']);
    expect(app(Favorites::class)->favoriteVaults($admin))->toHaveCount(3);
    expect(app(Favorites::class)->favoriteEntries($admin))->toHaveCount(8);
});

test('agency demo never seeds outside local', function (string $environment) {
    $this->app->detectEnvironment(fn (): string => $environment);

    $this->artisan('db:seed', ['--class' => AgencyDemoSeeder::class, '--force' => true])->assertSuccessful();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('vaults', 0);
})->with(['production', 'staging', 'testing']);

test('agency demo requires an existing administrator', function () {
    $this->app->detectEnvironment(fn (): string => 'local');

    expect(fn () => $this->seed(AgencyDemoSeeder::class))
        ->toThrow(RuntimeException::class, 'Run the local DatabaseSeeder');

    $this->assertDatabaseCount('vaults', 0);
});

test('agency demo preserves an existing client vault without granting the administrator access', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    $admin = User::factory()->superAdmin()->withTwoFactor()->create(['email' => 'admin@admin.com']);
    $owner = User::factory()->withTwoFactor()->create();
    $existing = vaultigen_make_vault($admin, $owner, ['name' => 'Apple']);
    $entry = vaultigen_make_entry($owner, $existing, ['title' => 'Client credentials']);

    $this->seed(AgencyDemoSeeder::class);

    expect(app(VaultAccess::class)->hasVaultAccess($admin, $existing->id))->toBeFalse();
    $this->assertDatabaseHas('entries', ['id' => $entry->id, 'title' => 'Client credentials']);
    expect(Entry::query()->where('vault_id', $existing->id)->count())->toBe(1);
});

test('agency demo rolls back and restores the mfa setting when a service rejects a write', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    $admin = User::factory()->superAdmin()->create(['email' => 'admin@admin.com']);
    $personalId = app(PersonalVaults::class)->provisionFor($admin);
    DB::table('vaults')->where('id', $personalId)->update(['state' => 'read_only']);

    expect(fn () => $this->seed(AgencyDemoSeeder::class))->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('vaults', ['name' => 'Apple']);
    $this->assertDatabaseCount('entries', 0);
    $this->assertDatabaseCount('teams', 0);
    expect(config('vaultigen.mfa_disabled'))->toBeFalse();
});

test('agency demo enrolls agency users with a mix of email and app authentication and keeps test accounts unchanged', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    $admin = User::factory()->superAdmin()->withEmailTwoFactor()->create(['email' => 'admin@admin.com']);
    $testAccounts = User::factory()->count(3)->sequence(
        ['email' => 'manager@manager.com'],
        ['email' => 'editor@editor.com'],
        ['email' => 'viewer@viewer.com'],
    )->create();
    $testAccountAttributes = $testAccounts->map(fn (User $user): array => $user->fresh()->getAttributes())->all();
    $adminAttributes = $admin->fresh()->getAttributes();
    $expectedMethods = [
        'alice@example.com' => ['email'],
        'bob@example.com' => ['app'],
        'carol@example.com' => ['app'],
        'dave@example.com' => ['app'],
        'erin@example.com' => ['email'],
        'frank@example.com' => ['email'],
    ];
    foreach (array_keys($expectedMethods) as $email) {
        User::factory()->create(['email' => $email]);
    }
    Notification::fake();

    $this->seed(AgencyDemoSeeder::class);

    foreach ($expectedMethods as $email => $methods) {
        $user = User::query()->where('email', $email)->sole();
        expect($user->mfaMethods())->toBe($methods);
        if ($methods === ['app']) {
            expect($user->app_authentication_recovery_codes)->toHaveCount(8);
        }
    }
    expect($admin->fresh()->getAttributes())->toBe($adminAttributes);
    expect($testAccounts->map(fn (User $user): array => $user->fresh()->getAttributes())->all())->toBe($testAccountAttributes);
    Notification::assertNothingSent();
});

test('agency demo preserves existing factors and does not regenerate factors on repeat runs', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    User::factory()->superAdmin()->create(['email' => 'admin@admin.com']);
    $alice = User::factory()->withTwoFactor()->create(['email' => 'alice@example.com']);
    $bob = User::factory()->create(['email' => 'bob@example.com']);
    $aliceAttributes = $alice->fresh()->getAttributes();
    $this->seed(AgencyDemoSeeder::class);
    $bobAttributes = $bob->fresh()->getAttributes();

    $this->seed(AgencyDemoSeeder::class);

    expect($alice->fresh()->getAttributes())->toBe($aliceAttributes);
    expect($bob->fresh()->getAttributes())->toBe($bobAttributes);
});
