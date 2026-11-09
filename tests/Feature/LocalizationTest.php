<?php

use App\Auth\EditProfile;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EntryForm;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Vaults\VaultResource;
use App\Models\User;
use App\Services\LocaleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;

test('the supported locales are defined in configuration', function () {
    $resolver = app(LocaleResolver::class);

    expect($resolver->supported())->toBe(['en' => 'English', 'de' => 'Deutsch'])
        ->and($resolver->default())->toBe('en')
        ->and($resolver->cookieName())->toBe('vaultigen_locale')
        ->and($resolver->isSupported('en'))->toBeTrue()
        ->and($resolver->isSupported('de'))->toBeTrue()
        ->and($resolver->isSupported('fr'))->toBeFalse()
        ->and($resolver->isSupported(null))->toBeFalse();
});

test('guests fall back to the default locale', function () {
    expect(app(LocaleResolver::class)->resolve(Request::create('/')))->toBe('en');
});

test('the browser language is detected', function () {
    $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.8']);

    expect(app(LocaleResolver::class)->fromBrowser($request))->toBe('de')
        ->and(app(LocaleResolver::class)->resolve($request))->toBe('de');
});

test('an unsupported browser language falls back to the default', function () {
    $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);

    expect(app(LocaleResolver::class)->fromBrowser($request))->toBeNull()
        ->and(app(LocaleResolver::class)->resolve($request))->toBe('en');
});

test('a remembered choice overrides the browser language', function () {
    $request = Request::create(
        '/',
        server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9'],
        cookies: [app(LocaleResolver::class)->cookieName() => 'de'],
    );

    expect(app(LocaleResolver::class)->resolve($request))->toBe('de');
});

test('a user locale overrides the remembered choice and browser language', function () {
    $user = User::factory()->create(['locale' => 'de']);
    $request = Request::create(
        '/',
        server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9'],
        cookies: [app(LocaleResolver::class)->cookieName() => 'fr'],
    );
    $request->setUserResolver(fn (): User => $user);

    expect(app(LocaleResolver::class)->resolve($request))->toBe('de');
});

test('an unsupported user locale is ignored', function () {
    $user = User::factory()->create(['locale' => 'fr']);
    $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de']);
    $request->setUserResolver(fn (): User => $user);

    expect(app(LocaleResolver::class)->resolve($request))->toBe('de');
});

test('the middleware sets the application locale from the browser', function () {
    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->get(route('filament.app.auth.login'))
        ->assertOk();

    expect(app()->getLocale())->toBe('de');
});

test('the middleware prefers the signed-in users language', function () {
    $user = User::factory()->withTwoFactor()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get(EditProfile::getUrl())
        ->assertOk()
        ->assertSee('Sprache');

    expect(app()->getLocale())->toBe('de');
});

test('users can choose their language in the profile', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => $user->name,
            'email' => $user->email,
            'locale' => 'de',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->locale)->toBe('de')
        ->and(app()->getLocale())->toBe('de')
        ->and(Cookie::queued(app(LocaleResolver::class)->cookieName())?->getValue())->toBe('de');
});

test('saving the profile without changing the language does nothing', function () {
    $user = User::factory()->withTwoFactor()->create(['locale' => 'de']);

    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => 'Renamed',
            'email' => $user->email,
            'locale' => 'de',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->name)->toBe('Renamed')
        ->and($user->locale)->toBe('de')
        ->and(Cookie::queued(app(LocaleResolver::class)->cookieName()))->toBeNull();
});

test('the user preferred locale is exposed to mail and notifications', function () {
    expect(User::factory()->create(['locale' => 'de'])->preferredLocale())->toBe('de')
        ->and(User::factory()->create(['locale' => 'fr'])->preferredLocale())->toBeNull()
        ->and(User::factory()->create(['locale' => null])->preferredLocale())->toBeNull();
});

test('the vault resource labels are translatable', function () {
    expect(VaultResource::getModelLabel())->toBe('vault')
        ->and(VaultResource::getPluralModelLabel())->toBe('vaults');

    app()->setLocale('de');

    expect(VaultResource::getModelLabel())->toBe('Tresor')
        ->and(VaultResource::getPluralModelLabel())->toBe('Tresore');
});

test('the dashboard renders in German for a German user', function () {
    $user = User::factory()->superAdmin()->withTwoFactor()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('Willkommen bei Vaultigen')
        ->assertSee('Tresore');
});

test('javascript strings are passed to Alpine in the active language', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $alice->update(['locale' => 'de']);

    $this->actingAs($alice)
        ->get(EntryForm::getUrl(['vault' => $vault->id]))
        ->assertOk()
        ->assertSee('Mit der Eingabe beginnen')
        ->assertSee('Passwort anzeigen');
});

test('the settings page renders in German for a German user', function () {
    $user = User::factory()->superAdmin()->withTwoFactor()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->get(Settings::getUrl())
        ->assertOk()
        ->assertSee('Einstellungen speichern');
});

test('every supported locale translates the same keys as the default locale', function () {
    $flatten = fn (string $path): array => Arr::dot(require $path);
    $default = app(LocaleResolver::class)->default();

    foreach (array_keys(app(LocaleResolver::class)->supported()) as $locale) {
        foreach (glob(lang_path($default.'/*.php')) as $file) {
            $translation = lang_path($locale.'/'.basename($file));

            expect($translation)->toBeFile();
            expect(array_keys($flatten($translation)))->toEqualCanonicalizing(array_keys($flatten($file)));
        }
    }
});
