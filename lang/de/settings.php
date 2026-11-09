<?php

return [

    'subheading' => 'Nur Einstellungen, die von aktiven Arbeitsabläufen verwendet werden, sind verfügbar. Schlüssel und Host-Konfiguration werden außerhalb dieses Formulars verwaltet.',

    'actions' => [
        'save' => 'Einstellungen speichern',
    ],

    'sections' => [
        'installation' => 'Installation',
        'installation_description' => 'Wie diese Installation gegenüber Benutzern identifiziert wird.',
        'vault_creation' => 'Tresorerstellung',
        'vault_creation_description' => 'Wer freigegebene Tresore erstellen darf und wie viele. Das Erstellen eines Tresors gewährt niemals Zugriff auf dessen Inhalte.',
        'retention' => 'Papierkorb- und Verlaufsaufbewahrung',
        'retention_description' => 'Gelöschte Einträge und Ordner bleiben bis zu ihrer Löschfrist wiederherstellbar. Der Verlauf wird im Hintergrund bereinigt; der Wert 0 bedeutet kein Limit für diese Dimension.',
        'audit_retention' => 'Audit-Aufbewahrung',
        'audit_retention_description' => 'Wie lange Sicherheitsereignisse aufbewahrt werden. Die Bereinigung läuft im Hintergrund und bewahrt zuvor einen geschlüsselten Prüfpunkt auf; IP-Adressen und Benutzeragenten werden getrennt redigiert und sind nie Teil der Hash-Kette.',
        'imports' => 'Import-Aufbewahrung',
        'imports_description' => 'Wie lange zwischengespeicherte Uploads und abgeschlossene Importläufe aufbewahrt werden. Die Bereinigung entfernt die verschlüsselte Quelle und kürzt Fehlerlisten; sie behält niemals Zugangsdaten oder Klartext.',
        'site_icons' => 'Website-Icons',
        'site_icons_description' => 'Zeigt das Icon der Website jedes Logins in Eintragslisten. Der Server lädt Icons direkt von der Website; es werden nur öffentliche Adressen kontaktiert und kein Icon-Dienst von Drittanbietern genutzt.',
        'session_security' => 'Sitzungssicherheit',
        'session_security_description' => 'Die Grenzwerte werden bei jeder Anfrage durchgesetzt, auch für bereits angemeldete Sitzungen.',
        'authentication' => 'Authentifizierung',
        'authentication_description' => 'Multi-Faktor-Authentifizierung ist immer erforderlich. Wählen Sie, welche Methoden Benutzer einrichten dürfen; nur E-Mail ist zulässig. Wenn beide erlaubt sind, aktiviert jeder Benutzer genau eine.',
        'security_notifications' => 'Sicherheitsbenachrichtigungen',
        'security_notifications_description' => 'Diese Empfänger erhalten Warnungen zu Wiederherstellung, Authentifizierungs-Zurücksetzungen und anderen sensiblen Kontoereignissen.',
    ],

    'fields' => [
        'display_name' => 'Anzeigename',
        'display_name_placeholder' => 'Vaultigen',
        'display_name_helper' => 'Wird im Browsertitel, auf Anmeldeseiten und in Sicherheitsbenachrichtigungen angezeigt.',
        'vault_creation_policy' => 'Wer Tresore erstellen darf',
        'vault_creation_policy_helper' => '„Tresor-Ersteller“ ist eine Plattformrolle, die keinen Zugriff auf bestehende Geheimnisse gewährt.',
        'vault_creation_policy_options' => [
            'admins' => 'Nur Super-Admins',
            'vault_creators' => 'Super-Admins und Tresor-Ersteller',
            'any_active_user' => 'Jeder aktive Benutzer',
        ],
        'vault_creation_limit_per_user' => 'Limit pro Ersteller',
        'vault_creation_limit_per_user_helper' => 'Maximale Anzahl freigegebener Tresore, die ein Konto für sich selbst erstellen darf. Super-Admins sind beim Erstellen in der Administration oder per Import ausgenommen.',
        'vault_creation_limit_installation' => 'Installationslimit',
        'vault_creation_limit_installation_helper' => 'Die Selbstbedienung endet, sobald die Installation so viele freigegebene Tresore hat. Super-Admins sind beim Erstellen in der Administration oder per Import ausgenommen.',
        'inactivity_minutes' => 'Inaktivitäts-Timeout',
        'inactivity_minutes_helper' => 'Meldet den Benutzer nach dieser Leerlaufzeit ab.',
        'absolute_minutes' => 'Absolute Sitzungsdauer',
        'absolute_minutes_helper' => 'Maximales Sitzungsalter unabhängig von Aktivität, bis zu 12 Stunden.',
        'mfa_methods' => 'Erlaubte Methoden',
        'mfa_methods_helper' => 'Mindestens eine Methode muss erlaubt bleiben; sie kann nicht deaktiviert werden. Eine Änderung betrifft jedes Konto und wird protokolliert.',
        'mfa_methods_options' => [
            'app' => 'Authentifizierungs-App',
            'email' => 'E-Mail-Code',
        ],
        'remember_device_days' => 'Dieses Gerät merken',
        'remember_device_days_helper' => 'Wie lange ein vertrauenswürdiges Gerät den Codeschritt nach einer vollständigen Anmeldung überspringen darf. 0 blendet die Option aus; ein wiederhergestelltes oder zurückgesetztes Konto vergisst vertrauenswürdige Geräte immer.',
        'trash_retention_days' => 'Papierkorb-Aufbewahrung',
        'trash_retention_days_helper' => 'Tage, die ein gelöschter Eintrag oder Ordner wiederherstellbar bleibt, bevor der Löschauftrag ihn endgültig entfernen darf.',
        'vault_purge_retention_days' => 'Wartezeit für Tresor-Löschung',
        'vault_purge_retention_days_helper' => 'Tage, in denen ein Super-Admin einen zur Löschung vorgemerkten Tresor wiederherstellen kann. In dieser Zeit hat niemand Zugriff; danach wird er automatisch und endgültig gelöscht.',
        'vault_purge_notifications_enabled' => 'E-Mail, wenn ein Tresor zur Löschung vorgemerkt wird',
        'vault_purge_notifications_enabled_helper' => 'Sendet eine Warnung an die obigen Empfänger, damit ein Super-Admin den Tresor vor der Löschung wiederherstellen kann.',
        'revision_retention_count' => 'Limit für Revisionen',
        'revision_retention_count_helper' => 'Höchstens so viele Revisionen pro Eintrag behalten. 0 behält jede Revision.',
        'revision_retention_days' => 'Alterslimit für Revisionen',
        'revision_retention_days_helper' => 'Revisionen entfernen, die älter als so viele Tage sind. 0 deaktiviert das Alterslimit.',
        'audit_retention_days' => 'Audit-Aufbewahrung',
        'audit_retention_days_helper' => 'Tage, die Sicherheitsereignisse aufbewahrt werden, bevor die kontrollierte Bereinigung sie entfernen darf. Die verbleibende Kette verifiziert weiterhin ab ihrem aufgezeichneten Anker.',
        'audit_ip_retention_days' => 'Aufbewahrung von IP und Benutzeragent',
        'audit_ip_retention_days_helper' => 'Kürzere Datenschutz-Aufbewahrung für IP-Adressen und Benutzeragenten. 0 behält sie so lange wie das Ereignis; sie sind nie Teil der Hash-Kette.',
        'import_retention_days' => 'Import-Aufbewahrung',
        'import_retention_days_helper' => 'Tage, die ein zwischengespeicherter Upload oder abgeschlossener Importlauf aufbewahrt wird. Die Bereinigung bricht verwaiste Uploads ab und löscht ältere Läufe.',
        'site_icons_enabled' => 'Website-Icons abrufen',
        'site_icons_enabled_helper' => 'Wenn aktiv, stellt das Speichern oder Importieren eines Logins mit URL einen Hintergrundabruf des Website-Icons ein. Deaktivieren stoppt neue Abrufe; bereits geladene Icons bleiben sichtbar.',
        'suffix_minutes' => 'Minuten',
        'suffix_days' => 'Tage',
    ],

    'security_recipients' => [
        'email_placeholder' => 'security@example.com',
        'duplicate' => 'Jeder Empfänger muss eindeutig sein.',
        'add_action' => 'Empfänger hinzufügen',
        'helper' => 'Wiederherstellungsbenachrichtigungen gehen auch an den betroffenen Administrator.',
    ],

    'notifications' => [
        'saved' => 'Einstellungen gespeichert',
    ],

];
