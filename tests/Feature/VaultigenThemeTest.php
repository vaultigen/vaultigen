<?php

use App\Filament\VaultigenTheme;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Filament\Support\Colors\Color;

test('the vaultigen palette exposes a complete eleven shade scale', function () {
    foreach ([VaultigenTheme::lime(), VaultigenTheme::ink()] as $palette) {
        expect($palette)->toHaveCount(11)
            ->and(array_keys($palette))->toBe([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]);

        foreach ($palette as $hex) {
            expect($hex)->toMatch('/^#[0-9A-F]{6}$/');
        }
    }
});

test('the vaultigen palette carries the documented signature colors', function () {
    expect(VaultigenTheme::lime()[400])->toBe('#A3E635')
        ->and(VaultigenTheme::lime()[700])->toBe('#4D7C0F')
        ->and(VaultigenTheme::ink()[950])->toBe('#0B0E0B')
        ->and(VaultigenTheme::ink()[400])->toBe('#97A595');
});

test('the panel is themed dark-first with the vaultigen palette', function () {
    $panel = Filament::getPanel('app');
    $colors = $panel->getColors();

    expect($panel->getDefaultThemeMode())->toBe(ThemeMode::Dark)
        ->and($colors['primary'])->toBe(VaultigenTheme::lime())
        ->and($colors['gray'])->toBe(VaultigenTheme::ink())
        ->and($colors['success'])->toBe(VaultigenTheme::lime())
        ->and($colors['warning'])->toBe(Color::Yellow)
        ->and($colors['danger'])->toBe(Color::Red)
        ->and($colors['info'])->toBe(Color::Sky);
});

test('the panel serves the system font stack without an external request', function () {
    $panel = Filament::getPanel('app');

    expect($panel->getFontProvider())->toBe(LocalFontProvider::class)
        ->and($panel->getFontUrl())->toBeNull()
        ->and($panel->getBrandLogoHeight())->toBe('1.75rem');
});

test('the sign-in page renders the vaultigen branding', function () {
    $this->get(route('filament.app.auth.login'))
        ->assertOk()
        ->assertSee(__('auth.login.heading'))
        ->assertSee(__('auth.login.tagline'))
        ->assertSee('vaultigen-icon.svg', false)
        ->assertSee('fi-vaultigen-mark', false);
});
