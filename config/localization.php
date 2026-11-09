<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | The locales the application exposes. The keys are the codes persisted on
    | the user record and passed to Laravel's translator; the values are the
    | labels shown in the profile language selector.
    |
    */

    'supported' => [
        'en' => 'English',
        'de' => 'Deutsch',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Locale
    |--------------------------------------------------------------------------
    |
    | Used when neither the signed-in user, a remembered choice, nor the
    | browser expresses a supported preference.
    |
    */

    'default' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Guest Locale Cookie
    |--------------------------------------------------------------------------
    |
    | When a user picks a language in their profile it is remembered in this
    | cookie, so the signed-out screens (login, invitations) keep their choice
    | after signing out.
    |
    */

    'cookie' => 'vaultigen_locale',

    /*
    |--------------------------------------------------------------------------
    | Locale Cookie Lifetime
    |--------------------------------------------------------------------------
    |
    | The remembered guest choice is kept for a year, in minutes.
    |
    */

    'cookie_lifetime' => 60 * 24 * 365,

];
