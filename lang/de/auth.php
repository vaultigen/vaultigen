<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'Diese Zugangsdaten stimmen nicht mit unseren Aufzeichnungen überein.',
    'password' => 'Das angegebene Passwort ist falsch.',
    'throttle' => 'Zu viele Anmeldeversuche. Bitte versuchen Sie es in :seconds Sekunden erneut.',

    'login' => [
        'heading' => 'Vault entsperren',
        'tagline' => 'Selbst gehostete Passwortverwaltung',
        'subheading_local' => 'Lokale Entwicklung. Melden Sie sich mit admin@admin.com / password an, nachdem Sie migrate:fresh --seed ausgeführt haben. Vorbefüllte Testkonten: manager@manager.com, editor@editor.com, viewer@viewer.com (Passwort).',
        'subheading' => 'Zugang nur auf Einladung. Diese Installation vertraut ihrem Host-Betreiber, der auf gespeicherte Geheimnisse zugreifen kann. Richten Sie nach der ersten Anmeldung Ihren erlaubten zweiten Faktor ein; wenn Sie die Authentifizierungs-App wählen, speichern Sie Ihre Offline-Wiederherstellungscodes.',
        'trusted_device' => 'Dieses Gerät merken',
        'trusted_device_helper' => 'Überspringt den Codeschritt in diesem Browser für eine begrenzte Zeit. Passwortänderungen und Kontozurücksetzungen verwerfen die Erinnerung.',
    ],

];
