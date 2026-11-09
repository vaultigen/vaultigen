<?php

return [

    'layout' => [
        'footer' => 'Dies ist eine automatische Nachricht von :app. Bitte antworten Sie nicht auf diese E-Mail.',
    ],

    'verify_email_code' => [
        'subject' => 'Vaultigen-Anmeldecode',
        'kicker' => 'Anmeldecode',
        'title' => 'Anmeldung bestätigen',
        'body' => 'Geben Sie diesen Code ein, um die Anmeldung bei Vaultigen abzuschließen.',
        'code_label' => 'Ihr Anmeldecode',
        'expires' => 'Dieser Code läuft in einer Minute ab.|Dieser Code läuft in :minutes Minuten ab.',
        'note' => 'Geben Sie diesen Code niemals weiter. Vaultigen wird Sie niemals danach fragen.',
    ],

    'admin_recovered' => [
        'subject' => 'Vaultigen: lokale Administrator-Wiederherstellung',
        'kicker' => 'Administrator-Wiederherstellung',
        'title' => 'Lokaler Administrator wiederhergestellt',
        'body' => 'Ein Host-Betreiber hat den einzigen Vaultigen-Administrator wiederhergestellt. Bestehende Sitzungen und Authentifizierungsfaktoren wurden widerrufen.',
        'footer' => 'Falls dies unerwartet geschieht, wenden Sie sich an Ihren Host-Betreiber. Audit-Ereignis: :sequence',
    ],

    'vault_purge_initiated' => [
        'subject' => 'Vaultigen: Tresor zur Löschung vorgemerkt',
        'kicker' => 'Tresor-Löschung',
        'title' => 'Ein Tresor wurde zur Löschung vorgemerkt',
        'body' => 'Niemand hat mehr Zugriff auf den Tresor, und er wird nach Ablauf der Wartezeit endgültig gelöscht. Bis dahin kann ein Super-Admin ihn in der Tresorverwaltung wiederherstellen.',
        'footer' => 'Falls dies unerwartet geschieht, lassen Sie es sofort von einem Super-Admin prüfen. Audit-Ereignis: :sequence',
    ],

    'factors_reset' => [
        'subject' => 'Vaultigen: Authentifizierungsfaktoren zurückgesetzt',
        'kicker' => 'Sicherheitswarnung',
        'title' => 'Authentifizierungsfaktoren zurückgesetzt',
        'body' => 'Ein Vaultigen-Administrator hat Ihre Authentifizierungsfaktoren zurückgesetzt. Alle Sitzungen und Wiederherstellungscodes wurden widerrufen. Melden Sie sich mit Ihrem Passwort an und richten Sie Ihren Authenticator erneut ein.',
        'footer' => 'Falls dies unerwartet geschieht, wenden Sie sich unverzüglich an Ihren Administrator. Audit-Ereignis: :sequence',
    ],

    'emergency_access' => [
        'subject' => 'Vaultigen: Notfall-Administratorzugriff gestartet',
        'kicker' => 'Notfallzugriff',
        'title' => 'Notfallzugriff gestartet',
        'body' => 'Ein Vaultigen-Administrator hat einen zeitlich begrenzten Notfallzugriff auf einen Tresor gestartet. Die Sitzung ist schreibgeschützt, läuft innerhalb von 30 Minuten ab und wird gut sichtbar protokolliert.',
        'footer' => 'Falls dies unerwartet geschieht, untersuchen Sie es unverzüglich. Audit-Ereignis: :sequence',
    ],

    'invitation' => [
        'subject' => 'Ihre Vaultigen-Einladung',
        'kicker' => 'Einladung',
        'title' => 'Sie wurden eingeladen',
        'intro' => 'Sie wurden zu Vaultigen eingeladen. Diese Einladung läuft in 24 Stunden ab und kann einmal verwendet werden.',
        'accept' => 'Einladung annehmen und E-Mail-Adresse bestätigen',
        'ignore' => 'Falls Sie diese Einladung nicht erwartet haben, ignorieren Sie diese E-Mail.',
    ],

    'test' => [
        'subject' => 'Vaultigen-Test-E-Mail',
        'kicker' => 'Testnachricht',
        'title' => 'Test-E-Mail-Zustellung',
        'body' => 'Dies ist eine Test-E-Mail von Vaultigen, die von der Seite „Installationsstatus“ gesendet wird, um die Zustellung von E-Mails zu bestätigen.',
        'footer' => 'Wenn Sie sie erhalten haben, funktioniert der konfigurierte E-Mail-Versand. Diese Nachricht enthält keine Geheimnisse.',
    ],

];
