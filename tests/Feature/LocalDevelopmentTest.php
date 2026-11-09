<?php

use App\Auth\EditProfile;
use App\Auth\Login;
use App\Filament\Pages\Settings;
use App\Models\Entry;
use App\Models\Team;
use App\Models\User;
use App\Models\Vault;
use App\Services\VaultAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

test('local seeding creates the default administrator and can be repeated', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    Http::preventStrayRequests();
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('email', 'admin@admin.com')->sole();
    expect($admin->email)->toBe('admin@admin.com');
    expect(Hash::check('password', $admin->password))->toBeTrue();
    expect($admin->isSuperAdmin())->toBeTrue();
    expect(User::count())->toBe(10);
    expect(Team::count())->toBe(6);
    expect(DB::table('team_memberships')->count())->toBe(17);
    expect(Vault::count())->toBe(19);
    expect(Entry::count())->toBe(116);
    expect(DB::table('folder_grants')->count())->toBe(23);
    $bob = User::query()->where('email', 'bob@example.com')->sole();
    expect(DB::table('team_memberships')->where('user_id', $bob->id)->count())->toBe(3);
    expect(Team::query()->where('normalized_name', 'support')->sole()->members()->count())->toBe(0);
    $this->seed(DatabaseSeeder::class);
    expect(User::count())->toBe(10);
    expect(Team::count())->toBe(6);
    expect(DB::table('team_memberships')->count())->toBe(17);
    expect(Vault::count())->toBe(19);
    expect(Entry::count())->toBe(116);
    expect(DB::table('user_platform_roles')->count())->toBe(1);
    expect(User::query()->where('email', 'admin@admin.com')->sole()->id)->toBe($admin->id);
    Http::assertNothingSent();
});

test('local seeding provisions the role and folder-scope scenarios', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    Http::preventStrayRequests();
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@admin.com')->sole();
    $manager = User::query()->where('email', 'manager@manager.com')->sole();
    $editor = User::query()->where('email', 'editor@editor.com')->sole();
    $viewer = User::query()->where('email', 'viewer@viewer.com')->sole();
    $access = app(VaultAccess::class);

    $projectOne = Vault::query()->where('normalized_name', 'project 1')->sole();

    expect(Hash::check('password', $manager->password))->toBeTrue()
        ->and($projectOne->primary_custodian_user_id)->toBe($manager->id)
        ->and($access->permission($manager, $projectOne->id))->toBe('manager')
        ->and($access->permission($editor, $projectOne->id))->toBe('editor')
        ->and($access->permission($viewer, $projectOne->id))->toBe('viewer')
        ->and($access->visibleEntries($editor, $projectOne->id)->count())->toBe(3);

    $projectTwo = Vault::query()->where('normalized_name', 'project 2')->sole();

    expect($access->permission($admin, $projectTwo->id))->toBe('editor')
        ->and($access->permission($manager, $projectTwo->id))->toBeNull()
        ->and($access->hasVaultAccess($manager, $projectTwo->id))->toBeFalse()
        ->and($access->hasVaultScope($editor, $projectTwo->id))->toBeFalse()
        ->and($access->hasVaultScope($viewer, $projectTwo->id))->toBeFalse()
        ->and($access->hasVaultAccess($editor, $projectTwo->id))->toBeTrue()
        ->and($access->hasVaultAccess($viewer, $projectTwo->id))->toBeTrue()
        ->and($access->visibleFolders($editor, $projectTwo->id)->pluck('name')->all())->toBe(['Team'])
        ->and($access->visibleEntries($editor, $projectTwo->id)->count())->toBe(2)
        ->and($access->visibleEntries($viewer, $projectTwo->id)->count())->toBe(2)
        ->and($access->visibleEntries($admin, $projectTwo->id)->count())->toBe(3)
        ->and(DB::table('vault_grants')->where('vault_id', $projectTwo->id)->whereNotNull('active_slot')->count())->toBe(1);

    Http::assertNothingSent();
});

test('the default local administrator signs straight into settings without totp', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['vaultigen.mfa_disabled' => true]);
    $this->seed(DatabaseSeeder::class);
    Livewire::test(Login::class)->set('data', ['email' => 'admin@admin.com', 'password' => 'password'])
        ->call('authenticate')->assertHasNoErrors()->assertRedirect('/');
    Livewire::test(Settings::class)->assertOk();
    Livewire::test(EditProfile::class)->assertOk();
});

test('local profile and command line accounts accept short passwords without network checks', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['vaultigen.mfa_disabled' => true]);
    Http::preventStrayRequests();
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::query()->where('email', 'admin@admin.com')->sole());
    Livewire::test(EditProfile::class)->set('passwordData', [
        'currentPassword' => 'password',
        'password' => 'x', 'passwordConfirmation' => 'x',
    ])->call('savePassword')->assertHasNoErrors()->assertNotified('Saved');
    expect(Hash::check('x', User::query()->where('email', 'admin@admin.com')->sole()->password))->toBeTrue();
    // The seeded accounts fill all ten free seats; free one for the additional administrator.
    User::query()->where('email', 'frank@example.com')->update(['is_active' => false]);
    $this->artisan('vaultigen:bootstrap', ['email' => 'another@example.test'])
        ->expectsQuestion('Administrator password', 'x')->assertSuccessful();
    expect(User::where('email', 'another@example.test')->sole()->isSuperAdmin())->toBeTrue();
    expect(Hash::check('x', User::where('email', 'another@example.test')->sole()->password))->toBeTrue();
    Http::assertNothingSent();
});

test('the default development account is never seeded outside local', function (string $environment) {
    $this->app->detectEnvironment(fn (): string => $environment);
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    expect(User::count())->toBe(0);
})->with(['production', 'staging', 'testing']);

test('local migrate fresh seed initializes missing configuration and supports another fresh reset', function () {
    $storage = sys_get_temp_dir().'/vaultigen-local-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($storage.'/framework/views', 0700, true);
    $files->put($storage.'/database.sqlite', '');
    $code = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->useStoragePath($argv[1]);
$app->loadEnvironmentFrom('.env.local-test-does-not-exist');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--seed' => true, '--no-interaction' => true]);
$admin = App\Models\User::query()->where('email', 'admin@admin.com')->sole();
if ($admin->email !== 'admin@admin.com' || ! Illuminate\Support\Facades\Hash::check('password', $admin->password)
    || ! $admin->isSuperAdmin() || ! Illuminate\Support\Facades\Gate::forUser($admin)->allows('manage-installation')) {
    exit(1);
}
Illuminate\Support\Facades\Auth::login($admin);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
foreach (['/', '/settings'] as $path) {
    $request = Illuminate\Http\Request::create($path);
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== 200) { echo 'Unexpected response: '.$response->getStatusCode(); exit(1); }
    $kernel->terminate($request, $response);
}
echo 'Ready';
CODE;
    try {
        foreach (range(1, 2) as $attempt) {
            $process = new Process([PHP_BINARY, '-r', $code, $storage], base_path(), [
                'APP_ENV' => 'local',
                'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
                'APP_CONFIG_CACHE' => $storage.'/config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $storage.'/database.sqlite', 'DB_URL' => false,
                'VAULTIGEN_INSTALLATION_ID' => false, 'VAULTIGEN_AUDIT_KEY' => false, 'PASSKEYS_USER_HANDLE_SECRET' => false,
                'VAULTIGEN_MFA_DISABLED' => 'true',
            ]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            expect($process->getOutput())->toBe('Ready');
        }
        expect($storage.'/app/private/installation.json')->toBeFile();
    } finally {
        $files->deleteDirectory($storage);
    }
});
