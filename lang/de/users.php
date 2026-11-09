<?php

return [

    'subheading' => 'Konten und Einladungen verwalten. Plattformrollen gewähren keinen Zugriff auf Geheimnisse. Jedes Konto erhält einen von der Organisation verwalteten persönlichen Arbeitstresor.',

    'columns' => [
        'platform_roles' => 'Plattformrollen',
        'active' => 'Aktiv',
        'verified' => 'Verifiziert',
        'mfa' => 'MFA',
        'registered' => 'Registriert',
    ],

    'placeholder' => [
        'ordinary_user' => 'Gewöhnlicher Benutzer',
    ],

    'filters' => [
        'active' => 'Aktiv',
        'email_verified' => 'E-Mail verifiziert',
        'mfa_method' => 'MFA-Methode',
        'platform_role' => 'Plattformrolle',
        'mfa_options' => [
            'app' => 'App',
            'email' => 'E-Mail',
            'none' => 'Keine',
        ],
        'platform_role_options' => [
            'super_admin' => 'Super-Admin',
            'auditor' => 'Auditor',
        ],
    ],

    'mfa' => [
        'app' => 'App',
        'email' => 'E-Mail',
        'none' => 'Keine',
        'app_email' => 'App + E-Mail',
    ],

    'actions' => [
        'user_actions' => 'Benutzeraktionen',
        'activate_selected' => 'Ausgewählte aktivieren',
        'deactivate_selected' => 'Ausgewählte deaktivieren',
        'deactivate_description' => 'Bestehende Sitzungen und Remember-Tokens werden widerrufen.',
        'offboard' => 'Benutzer offboarden',
        'offboard_description' => 'Öffnet die Offboarding-Seite mit vorausgewähltem Konto.',
        'invite' => 'Benutzer einladen',
        'invite_heading' => 'Benutzer einladen',
        'invite_description' => 'Vaultigen sendet einen Einmal-Link per E-Mail an diese Adresse. Der Empfänger verifiziert seine E-Mail-Adresse, wählt ein Passwort und richtet einen Authenticator ein, bevor er sich anmeldet. Der Link läuft nach 24 Stunden ab.',
        'invite_submit' => 'Einladung senden',
        'email' => 'E-Mail-Adresse',
        'email_helper' => 'Eine erneute Einladung an dieselbe Adresse ersetzt jede zuvor ausstehende Einladung.',
        'platform_roles' => 'Plattformrollen',
        'roles_description' => 'Plattformrollen gewähren niemals Zugriff auf bestehende Geheimnisse. Tresor-Ersteller erlaubt nur das Erstellen neuer Tresore.',
        'roles_options' => [
            'super_admin' => 'Super-Admin',
            'auditor' => 'Auditor',
            'vault_creator' => 'Tresor-Ersteller (Tresore erstellen, kein Zugriff auf Geheimnisse)',
        ],
        'reset_authentication' => 'Authentifizierung zurücksetzen',
        'reset_description' => 'Widerruft alle Sitzungen, gemerkten Geräte und Wiederherstellungscodes. Der Benutzer muss erneut eine erlaubte Methode einrichten. Eine Sicherheitsbenachrichtigung wird zur Zustellung aufgezeichnet.',
        'reason_helper' => 'Geben Sie keine Passwörter oder andere Geheimnisse an.',
        'your_password' => 'Ihr Passwort',
        'your_code' => 'Ihr frischer Authentifizierungscode',
    ],

    'empty' => [
        'heading' => 'Noch keine Benutzer',
        'description' => 'Laden Sie den ersten Benutzer ein, um ihm einen Einmal-Onboarding-Link zu senden.',
    ],

    'notifications' => [
        'invitation_email_failed_title' => 'Einladung erstellt, aber die E-Mail wurde nicht gesendet',
        'invitation_email_failed_body' => 'Die Einladung für :email existiert. Prüfen Sie die E-Mail-Transport-Konfiguration und verwenden Sie „Erneut senden“ in der Einladungsliste.',
        'invitation_sent_title' => 'Einladung gesendet',
        'invitation_sent_body' => 'Ein Einmal-Link wurde an :email gesendet. Er läuft in 24 Stunden ab.',
    ],

    'blade' => [
        'recent_invitations' => 'Letzte Einladungen',
        'recent_invitations_description' => 'Einladungen laufen 24 Stunden nach dem Versand ab und können einmalig angenommen werden.',
        'delivery' => 'Zustellung von Sicherheitsbenachrichtigungen',
        'delivery_description' => 'Der Scheduler wiederholt ausstehende Benachrichtigungen automatisch.',
        'attempts' => 'Versuche: :count',
        'delivered' => 'Zugestellt',
        'failed' => 'Fehlgeschlagen',
        'pending' => 'Ausstehend',
        'none_pending' => 'Keine ausstehenden Sicherheitsbenachrichtigungen.',
    ],

    'invitations' => [
        'email' => 'E-Mail',
        'invited_by' => 'Eingeladen von',
        'removed_account' => 'Entferntes Konto',
        'created' => 'Erstellt',
        'expires' => 'Läuft ab',
        'status' => [
            'pending' => 'Ausstehend',
            'accepted' => 'Angenommen',
            'expired' => 'Abgelaufen',
            'cancelled' => 'Abgebrochen',
        ],
        'delivery' => [
            'sent' => 'Gesendet',
            'pending' => 'Ausstehend',
            'failed' => 'Fehlgeschlagen',
        ],
        'delivery_state' => [
            'sent' => 'Gesendet',
            'pending' => 'Ausstehend',
            'failed' => 'Zustellung fehlgeschlagen',
            'not_configured' => 'E-Mail nicht konfiguriert',
        ],
        'resend' => 'Erneut senden',
        'resend_description' => 'Ein frischer Einmal-Link wird per E-Mail gesendet. Der vorherige Link funktioniert sofort nicht mehr.',
        'cancel' => 'Abbrechen',
        'cancel_description' => 'Der Einladungslink funktioniert sofort nicht mehr. Der Empfänger kann ihn danach nicht mehr annehmen.',
        'empty_heading' => 'Noch keine Einladungen',
        'empty_description' => 'Laden Sie einen Benutzer über die Benutzertabelle oben ein, um ihm einen Einmal-Link zu senden.',
        'replaced_email_failed_title' => 'Einladung ersetzt, aber die E-Mail wurde nicht gesendet',
        'replaced_email_failed_body' => 'Der Link für :email wurde ersetzt. Prüfen Sie den E-Mail-Transport und senden Sie erneut über diese Liste.',
        'resent_title' => 'Einladung erneut gesendet',
        'resent_body' => 'Ein frischer Einmal-Link wurde an :email gesendet.',
        'cancelled_title' => 'Einladung abgebrochen',
        'cancelled_body' => 'Der Link für :email funktioniert nicht mehr.',
    ],

    'administration' => [
        'cannot_deactivate_self' => 'Ein anderer Administrator muss Ihr Konto deaktivieren.',
        'cannot_remove_own_admin' => 'Ein anderer Administrator muss Ihre Administratorrolle entfernen.',
        'use_account_security' => 'Verwenden Sie die Kontosicherheit, um Ihre eigenen Faktoren zu verwalten.',
        'invitation_unavailable' => 'Diese Einladung ist ungültig oder nicht mehr verfügbar.',
    ],

];
