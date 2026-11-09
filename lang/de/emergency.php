<?php

return [

    'title' => 'Notfallzugriff',
    'navigation_label' => 'Notfallzugriff',
    'subheading' => 'Zeitlich begrenzter, schreibgeschützter Einblick in einen Tresor für einen Betriebsvorfall. Jede Sitzung wird gut sichtbar protokolliert und gemeldet.',
    'unknown_vault' => 'Unbekannter Tresor',
    'unknown_actor' => 'Unbekannter Administrator',

    'actions' => [
        'start' => 'Notfallzugriff starten',
        'end' => 'Zugriff beenden',
        'start_modal_heading' => 'Notfallzugriff starten',
        'start_modal_description' => 'Authentifizieren Sie sich erneut, um eine schreibgeschützte Sitzung zu starten. Sie läuft nach 30 Minuten automatisch ab und kann weder bearbeiten, teilen, verschieben, löschen noch exportieren.',
    ],

    'fields' => [
        'vault' => 'Tresor',
        'reason' => 'Grund',
        'reason_helper' => 'Erforderlich. Wird im Audit-Protokoll und in Benachrichtigungen erfasst.',
        'incident_reference' => 'Vorfall- / Ticket-Referenz',
        'incident_reference_helper' => 'Optional. Wird im Audit-Protokoll erfasst.',
        'password' => 'Ihr Passwort',
        'code' => 'Ihr Authentifizierungscode',
        'expires_at' => 'Läuft ab am',
    ],

    'values' => [
        'none' => '—',
        'active' => 'Aktiv',
    ],

    'active' => [
        'heading' => 'Notfallzugriff ist aktiv',
        'description' => 'Sie besitzen eine schreibgeschützte Notfallsitzung für diesen Tresor.',
        'scope_notice' => 'Nur Metadatenansicht und einzelne Enthüllung. Bearbeiten, Teilen, Verschieben, Löschen und Exportieren bleiben gesperrt; die Sitzung endet automatisch beim Ablauf.',
    ],

    'recent' => [
        'heading' => 'Letzte Notfallsitzungen',
        'description' => 'Die neuesten Sitzungen, einschließlich beendeter und abgelaufener.',
        'empty' => 'Es wurde noch kein Notfallzugriff gestartet.',
    ],

    'columns' => [
        'vault' => 'Tresor',
        'actor' => 'Administrator',
        'reason' => 'Grund',
        'started_at' => 'Gestartet (UTC)',
        'ended_at' => 'Beendet (UTC)',
        'notification_status' => 'Benachrichtigungen',
    ],

    'statuses' => [
        'pending' => 'Ausstehend',
        'sent' => 'Gesendet',
        'failed' => 'Fehlgeschlagen',
        'none' => 'Keine Empfänger',
    ],

    'empty' => 'Keine aktive Notfallsitzung.',

    'banner' => [
        'title' => 'Notfallzugriff aktiv.',
        'body' => 'Schreibgeschützter Zugriff auf :vault bis :expires UTC.',
        'end_confirm' => 'Notfallzugriff jetzt beenden?',
    ],

    'notifications' => [
        'started' => 'Notfallzugriff gestartet',
        'ended' => 'Notfallzugriff beendet',
        'failed' => 'Notfallzugriff konnte nicht gestartet werden',
    ],

];
