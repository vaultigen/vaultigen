<?php

return [

    'title' => 'Vaultigen-Einladung annehmen',
    'heading' => 'Nehmen Sie Ihre Einladung an',
    'description' => 'Der Einladungslink bestätigt die eingeladene E-Mail-Adresse. Richten Sie nach der Annahme einen Authenticator ein, bevor Sie Vaultigen verwenden.',
    'security_notice' => 'Vaultigen wird von der Organisation verwaltet: Der vertrauenswürdige Serverbetreiber kann auf gespeicherte Geheimnisse zugreifen, auch in Ihrem persönlichen Arbeitstresor.',

    'fields' => [
        'name' => 'Name',
        'email' => 'Eingeladene E-Mail-Adresse',
        'password' => 'Passwort (mindestens 15 Zeichen)',
        'password_confirmation' => 'Passwort bestätigen',
    ],

    'policy' => 'Ich verstehe das organisatorisch verwaltete Sicherheitsmodell.',
    'submit' => 'E-Mail bestätigen und Authentifizierung einrichten',

];
