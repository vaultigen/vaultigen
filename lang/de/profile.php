<?php

return [

    'fields' => [
        'locale' => 'Sprache',
        'locale_helper' => 'Die in Vaultigen verwendete Sprache.',
        'email' => 'E-Mail-Adresse',
        'current_password' => 'Aktuelles Passwort',
        'new_password' => 'Neues Passwort',
        'confirm_new_password' => 'Neues Passwort bestätigen',
        'email_code' => 'Aktueller E-Mail-Code',
        'authenticator_code' => 'Aktueller Authentifizierungscode',
        'confirmation_code_helper' => 'Erforderlich zusammen mit Ihrem aktuellen Passwort, wenn Sie Ihr Passwort oder Ihre E-Mail-Adresse ändern.',
    ],

    'sections' => [
        'profile_information' => 'Profilinformationen',
        'profile_information_description' => 'Ihr Name und die E-Mail-Adresse, mit der Sie sich anmelden.',
        'change_password' => 'Passwort ändern',
        'change_password_description' => 'Lassen Sie diese Felder leer, um Ihr aktuelles Passwort beizubehalten. Ein neues Passwort muss mit Ihren aktuellen Anmeldedaten und einem Bestätigungscode bestätigt werden.',
        'two_factor' => 'Zwei-Faktor-Authentifizierung',
        'two_factor_description' => 'Verwalten Sie die Methode, mit der Ihre Identität bei der Anmeldung bestätigt wird.',
        'active_sessions' => 'Aktive Sitzungen',
        'active_sessions_description' => 'Geräte und Browser, die derzeit bei Ihrem Konto angemeldet sind.',
        'trusted_devices' => 'Vertrauenswürdige Geräte',
        'trusted_devices_description' => 'Browser, die den zweiten Faktor überspringen dürfen. Widerrufen Sie alle, die Sie nicht kennen.',
    ],

    'notifications' => [
        'email_code_throttled' => 'Es wurde kürzlich ein Code gesendet. Versuchen Sie es in einer Minute erneut.',
        'email_code_sent' => 'Ein neuer Code wurde Ihnen per E-Mail zugeschickt.',
    ],

];
