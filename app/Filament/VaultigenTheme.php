<?php

namespace App\Filament;

use Filament\Support\Colors\Color;

/**
 * Vaultigen brand palette.
 *
 * The brand is dark-first: Vault Lime is the single 10% accent, Vault Ink
 * carries the surfaces, and Signal Yellow is reserved for warnings only.
 *
 * @phpstan-type Palette array<int, string>
 */
class VaultigenTheme
{
    /**
     * Vault Lime — the signature accent, from the raw brand scale.
     *
     * @return Palette
     */
    public static function lime(): array
    {
        return [
            50 => '#F6FCE8',
            100 => '#E9F8C8',
            200 => '#D5F294',
            300 => '#BEF264',
            400 => '#A3E635',
            500 => '#84CC16',
            600 => '#65A30D',
            700 => '#4D7C0F',
            800 => '#3F6212',
            900 => '#365314',
            950 => '#1A2E05',
        ];
    }

    /**
     * Vault Ink — the green-tinted neutral ramp used for every surface, border
     * and text shade. Light shades are warm off-whites, dark shades are the
     * "inside of the vault".
     *
     * @return Palette
     */
    public static function ink(): array
    {
        return [
            50 => '#FAFBF7',
            100 => '#F1F4EC',
            200 => '#DDE4D5',
            300 => '#C4CFBB',
            400 => '#97A595',
            500 => '#5B6A57',
            600 => '#3F4A3B',
            700 => '#2B332C',
            800 => '#232B24',
            900 => '#161B15',
            950 => '#0B0E0B',
        ];
    }

    /**
     * The palette array consumed by `Panel::colors()`.
     *
     * `success` intentionally reuses Vault Lime so that "secure / unlocked"
     * states speak the same language as the primary action, and the design's
     * "one bright accent" rule is respected.
     *
     * @return array<string, Palette>
     */
    public static function colors(): array
    {
        return [
            'primary' => self::lime(),
            'gray' => self::ink(),
            'success' => self::lime(),
            'warning' => Color::Yellow,
            'danger' => Color::Red,
            'info' => Color::Sky,
        ];
    }
}
