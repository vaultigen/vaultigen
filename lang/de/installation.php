<?php

return [

    'subheading' => 'Präsenzprüfungen belegen keine Sicherungsbereitschaft. Es werden keine Schlüsselwerte oder Verbindungsanmeldedaten angezeigt.',

    'test_mail' => [
        'label' => 'Test-E-Mail senden',
        'tooltip' => 'Test-E-Mail senden',
        'modal_heading' => 'Test-E-Mail senden',
        'modal_description' => 'Sendet eine einzelne Nachricht, um die E-Mail-Zustellung zu bestätigen. Sie enthält keine Geheimnisse und wirkt sich nicht auf gespeicherte Daten aus.',
        'modal_submit' => 'Test-E-Mail senden',
        'email' => 'E-Mail-Adresse des Empfängers',
        'not_delivering_title' => 'E-Mail-Transport stellt nicht zu',
        'not_delivering_body' => 'Konfigurieren Sie einen echten E-Mail-Transport, bevor Sie die Zustellung testen. Es wurde nichts gesendet.',
        'failed_title' => 'Test-E-Mail wurde nicht gesendet',
        'failed_body' => 'Zustellung fehlgeschlagen. Prüfen Sie die E-Mail-Transport-Konfiguration und das Anwendungsprotokoll.',
        'sent_title' => 'Test-E-Mail gesendet',
        'sent_body' => 'Eine Testnachricht wurde an :email gesendet.',
    ],

    'trust' => [
        'heading' => 'Vertrauen und Metadaten',
        'server_trusted' => [
            'heading' => 'Serververtrauenswürdige Architektur',
            'body' => 'Vaultigen ist ein serververtrauenswürdiger Passwortmanager. Der Host-Betreiber und die laufende Anwendung können gespeicherte Geheimnisse entschlüsseln. Es bietet kein Zero-Knowledge und keinen Schutz vor einem kompromittierten Host.',
        ],
        'metadata' => [
            'heading' => 'Metadaten sind sichtbar',
            'body' => 'Eine Offenlegung nur der Datenbank kann Namen, E-Mail-Adressen, Teams, Berechtigungen, Tresor- und Ordnerstrukturen, Eintragstitel und -typen, bereinigte URL-Hosts, Eigentümerschaft, Zeitstempel und Audit-Metadaten offenlegen. Anmeldenamen für Anmeldedaten, vollständige URLs, Dateinamen, private Notizen und benutzerdefinierte Werte gehören in verschlüsselte Nutzdaten.',
        ],
        'personal_vaults' => [
            'heading' => 'Persönliche Tresore werden von der Organisation verwaltet',
            'body' => 'Ein persönlicher Arbeitstresor ist gegenüber dem vertrauenswürdigen Serverbetreiber nicht privat. Allein die Rolle Super-Admin gewährt keinen Zugriff auf Tresorgeheimnisse.',
        ],
        'audit_checkpoints' => [
            'heading' => 'Audit-Prüfpunkte',
            'body' => 'Lokale Audit-Prüfpunkte erkennen Änderungen ohne den externen Integritätsschlüssel. Ein Host-Betreiber, der diesen Schlüssel kontrolliert, kann die Kette neu schreiben; eine vollständige Rollback-Erkennung erfordert einen separat aufbewahrten Prüfpunkt.',
        ],
    ],

    'summary' => [
        'needs_attention' => 'Installation erfordert Aufmerksamkeit',
        'review_recommended' => 'Überprüfung empfohlen',
        'all_passing' => 'Alle Prüfungen bestanden',
        'all_passing_description' => 'Jede Bereitschaftsprüfung wurde bestanden. Präsenzprüfungen belegen nicht, dass Sicherungen wiederherstellbar sind.',
        'failed_required' => '{1} :count erforderliche Konfigurationsprüfung fehlgeschlagen|[2,*] :count erforderliche Konfigurationsprüfungen fehlgeschlagen',
        'review_suffix' => '{1} und :count betriebliche Prüfung muss noch überprüft werden.|[2,*] und :count betriebliche Prüfungen müssen noch überprüft werden.',
        'review_only' => '{1} Die gesamte erforderliche Konfiguration ist vorhanden, aber :count betriebliche Prüfung muss noch überprüft werden.|[2,*] Die gesamte erforderliche Konfiguration ist vorhanden, aber :count betriebliche Prüfungen müssen noch überprüft werden.',
    ],

    'checks' => [
        'database' => [
            'label' => 'Datenbank',
            'status' => ':driver — verbunden',
        ],
        'sodium' => [
            'label' => 'Sodium',
            'ok' => 'Verfügbar',
            'error' => 'Fehlt — aktivieren Sie die PHP-Erweiterung sodium',
        ],
        'installation_identity' => [
            'label' => 'Installationsidentität',
            'ok' => 'Vorhanden',
            'error' => 'Fehlt oder geändert — stellen Sie die geschützte Installationskonfiguration wieder her',
        ],
        'application_key' => [
            'label' => 'Anwendungsschlüssel',
            'ok' => 'Vorhanden',
            'error' => 'Fehlt — stellen Sie APP_KEY bereit',
        ],
        'security_keys' => [
            'label' => 'Unabhängige Sicherheitsschlüssel',
            'ok' => 'Vorhanden',
            'error' => 'Fehlt oder ungültig — führen Sie vaultigen:initialize aus',
        ],
        'storage' => [
            'label' => 'Speicherplatz',
            'ok' => ':free MB frei',
            'low' => 'Nur noch :free MB frei — Speicher freigeben oder Backups auslagern',
            'unknown' => 'Speicherpfad nicht verfügbar — VAULTIGEN_STORAGE_PATH prüfen',
        ],
        'mail' => [
            'label' => 'E-Mail',
            'ok' => 'Konfiguriert — überprüfen Sie die Zustellung mit Ihrem E-Mail-Betreiber',
            'warning' => 'Stellt nicht zu — konfigurieren Sie einen echten E-Mail-Transport',
        ],
        'scheduler' => [
            'label' => 'Scheduler',
            'ok' => 'Aktueller Heartbeat',
            'warning' => 'Nicht verifiziert — führen Sie schedule:run jede Minute aus',
        ],
        'queue' => [
            'label' => 'Warteschlange',
            'ok' => 'Aktueller Worker-Heartbeat',
            'warning' => 'Nicht verifiziert — konfigurieren Sie eine dauerhafte Warteschlange und führen Sie queue:work aus',
        ],
        'security_notifications' => [
            'label' => 'Sicherheitsbenachrichtigungen',
            'status' => ':count ausstehend; der Scheduler wiederholt die E-Mail-Zustellung',
        ],
        'license' => [
            'label' => 'Lizenz',
            'free' => 'Kostenlose Edition: bis zu :limit Benutzer. Installieren Sie eine Lizenz, um weitere hinzuzufügen.',
            'licensed' => 'Lizenziert für :licensee (:license_id).',
            'updates_until' => 'Updates bis :date.',
            'lifetime' => 'Lebenslange Updates.',
            'users_limited' => 'Bis zu :count Benutzer.',
            'users_unlimited' => 'Unbegrenzte Benutzerzahl.',
            'updates_expired' => 'Die Lizenz für :licensee gilt für Versionen, die bis :date veröffentlicht wurden; diese Version wurde am :release veröffentlicht. Bis zur Verlängerung der Lizenz sind bis zu :limit Benutzer möglich.',
            'invalid' => 'Die installierte Lizenz ist ungültig: :message Die Installation läuft als kostenlose Edition mit bis zu :limit Benutzern.',
        ],
    ],

];
