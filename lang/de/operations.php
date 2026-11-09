<?php

return [
    'title' => 'Betrieb',
    'subheading' => 'Backup-Bereitschaft, Wiederherstellbarkeit und Schlüsselrotation.',

    'tabs' => [
        'backup' => 'Backup',
        'keys' => 'Schlüssel',
    ],

    'actions' => [
        'backup' => 'Backup ausführen',
        'backup_now' => 'Backup jetzt ausführen',
        'rotate_kek' => 'Installationsschlüssel neu umhüllen',
        'rotate_vault' => 'Tresorschlüssel rotieren',
        'disabled_not_ready' => 'Beheben Sie die oben genannten Bereitschaftsprobleme, bevor Sie ein Backup ausführen.',
        'disabled_busy' => 'Ein anderer Vorgang läuft; warten Sie, bis er abgeschlossen ist.',
    ],

    'fields' => [
        'vault' => 'Tresor',
        'password' => 'Ihr Passwort',
    ],

    'readiness' => [
        'heading' => 'Backup-Bereitschaft',
        'description' => 'Diese Prüfungen müssen bestanden sein, bevor einem Backup vertraut werden kann.',
        'backup_key' => [
            'label' => 'Backup-Verschlüsselungsschlüssel',
            'ok' => 'Ein Backup-Schlüsselbund ist konfiguriert und lesbar.',
            'error' => 'Es ist kein Backup-Schlüsselbund konfiguriert. Führen Sie vaultigen:backup-key:generate auf dem Host aus.',
        ],
        'disk' => [
            'label' => 'Backup-Speicher',
            'ok' => 'Das private Backup-Verzeichnis ist beschreibbar.',
            'error' => 'Das private Backup-Verzeichnis ist nicht beschreibbar.',
        ],
        'tool' => [
            'label' => 'Datenbank-Snapshot-Werkzeug',
            'ok' => 'Die Snapshot-Methode für :driver ist verfügbar.',
            'error' => 'Die Snapshot-Methode für :driver ist auf diesem Host nicht verfügbar.',
        ],
        'window' => [
            'label' => 'Wartungsfenster',
            'ok' => 'Es läuft kein Backup und keine Rotation.',
            'busy' => 'Ein anderer Vorgang läuft: :operation.',
        ],
        'operations' => [
            'maintenance' => 'ein Wartungsfenster',
            'backup' => 'ein Backup',
            'rotation' => 'eine Schlüsselrotation',
        ],
    ],

    'recovery' => [
        'heading' => 'Wiederherstellungsvoraussetzungen',
        'description' => 'Eine Wiederherstellung benötigt diese Eingaben aus separater Verwahrung. Hier werden nur ihre Kennungen angezeigt; die Werte sind im Browser nie verfügbar.',
        'columns' => [
            'input' => 'Eingabe',
            'identifier' => 'Kennung',
            'status' => 'Status',
        ],
        'installation_id' => 'Installations-ID',
        'kek' => 'KEK-Schlüsselbund der Installation',
        'app_key' => 'APP_KEY',
        'passkey_secret' => 'Passkey-User-Handle-Geheimnis',
        'audit_key' => 'Audit-Integritätsschlüssel',
        'backup_key' => 'Backup-Schlüssel',
        'notice' => 'Bewahren Sie jede Eingabe getrennt von den Backups und von der laufenden Installation auf. Geht eine davon verloren, kann eine Wiederherstellung unmöglich werden.',
    ],

    'backup' => [
        'heading' => 'Letztes Backup',
        'description' => 'Der Abschluss wird erst gemeldet, nachdem jedes geschriebene Objekt erneut gelesen und geprüft wurde.',
        'empty' => 'Es wurde noch kein Backup erstellt.',
        'state' => 'Ergebnis',
        'objects' => 'Verschlüsselte Objekte',
        'bytes' => 'Bytes gesamt',
        'created_at' => 'Gestartet',
        'finished_at' => 'Beendet',
        'schema' => 'Schema',
        'offsite' => 'Kopieren Sie jeden Backup-Satz auf Offline- oder schreibgeschützten Speicher. Ein Backup neben der laufenden Datenbank schützt nicht vor Ransomware.',
        'confirm' => 'Ein Backup pausiert alle Schreibvorgänge für kurze Zeit, während es die Datenbank sichert und jedes referenzierte verschlüsselte Objekt kopiert. Fortfahren?',
    ],

    'key_health' => [
        'heading' => 'Schlüsselzustand',
        'description' => 'Jeder Tresor muss einen aktiven Schlüssel haben, und jeder von einer Umhüllung verwendete Schlüssel muss noch im Schlüsselbund vorhanden sein.',
        'active_kek' => 'Aktiver Installations-KEK',
        'key_ids' => 'Verfügbare KEK-IDs',
        'versions' => 'Tresorschlüssel-Versionen',
        'retired' => ':count ausgemustert',
        'problem' => 'Die Wiederherstellbarkeit ist gefährdet. Fehlende KEK-IDs: :keys. Tresore ohne aktiven Schlüssel: :vaults.',
        'ok' => 'Jeder angegebene Schlüssel ist vorhanden und jeder Tresor hat einen aktiven Schlüssel.',
    ],

    'rotation' => [
        'heading' => 'Schlüsselrotationen',
        'description' => 'Eine neue Umhüllung der Installation lässt die Tresorschlüssel unverändert; eine Tresorrotation verschlüsselt einen Tresor unter einer neuen Schlüsselversion neu.',
        'empty' => 'Es wurde noch keine Schlüsselrotation ausgeführt.',
        'kek_description' => 'Jeden Tresorschlüssel unter dem aktiven Installations-KEK neu umhüllen. Geheime Daten ändern sich dabei nicht. Alte Schlüssel bleiben für aufbewahrte Backups im Schlüsselbund. Bestätigen Sie mit Ihrem Passwort und einem neuen Code.',
        'vault_description' => 'Eine neue Tresorschlüssel-Version für einen Tresor erstellen, aktivieren und Einträge, Revisionen und Anhang-Dateischlüssel darunter neu verschlüsseln. Eine gleichzeitige Bearbeitung wird nie überschrieben. Bestätigen Sie mit Ihrem Passwort und einem neuen Code.',
        'progress' => ':processed verarbeitet, :failed fehlgeschlagen',
        'resume' => 'Fortsetzen',
        'operations' => [
            'installation_kek_rewrap' => 'Neue Umhüllung des Installationsschlüssels',
            'vault_key_rotate' => 'Tresorschlüssel-Rotation',
        ],
        'columns' => [
            'operation' => 'Vorgang',
            'status' => 'Status',
            'progress' => 'Verarbeitet / fehlgeschlagen',
            'created_at' => 'Gestartet',
            'action' => 'Aktion',
        ],
    ],

    'active' => [
        'heading' => 'Vorgang läuft',
        'description' => 'Die Seite aktualisiert sich automatisch, solange der Vorgang läuft.',
        'backup' => 'Ein Backup läuft. Schreibvorgänge sind bis zum Abschluss pausiert.',
        'rotation' => 'Eine Schlüsselrotation läuft. Die Seite aktualisiert sich automatisch.',
    ],

    'maintenance' => [
        'heading' => 'Wartungsfenster geöffnet',
        'description' => 'Schreibvorgänge sind pausiert, damit Datenbank-Snapshot und verschlüsselte Objekte denselben Zeitpunkt abbilden.',
        'operation' => 'Vorgang: :operation',
        'write_blocked' => 'Die Installation befindet sich in einem Wartungsfenster; Schreibvorgänge sind pausiert. Versuchen Sie es in Kürze erneut.',
    ],

    'states' => [
        'pending' => 'Ausstehend',
        'running' => 'Läuft',
        'completed' => 'Abgeschlossen',
        'failed' => 'Fehlgeschlagen',
    ],

    'values' => [
        'present' => 'Vorhanden',
        'missing' => 'Fehlt',
        'unknown' => 'Unbekannt',
        'pending' => 'Nicht abgeschlossen',
    ],

    'notifications' => [
        'backup_started' => 'Backup gestartet. Während es läuft, werden Schreibvorgänge kurz pausiert.',
        'backup_not_started' => 'Das Backup wurde nicht gestartet',
        'rotation_started' => 'Schlüsselrotation gestartet.',
        'rotation_not_started' => 'Die Schlüsselrotation wurde nicht gestartet',
        'rotation_resumed' => 'Die Schlüsselrotation wird fortgesetzt.',
    ],

    'restore' => [
        'table_header' => 'Backup-Satz',
        'run' => 'Backup-Lauf: :id',
        'created' => 'Erstellt: :at',
        'driver' => 'Datenbanktreiber: :driver',
        'objects' => 'Referenzierte Objekte: :count',
        'kek_keys' => 'Benötigte KEK-IDs der Installation: :ids',
        'audit_keys' => 'Benötigter Audit-Schlüssel: :ids',
        'confirm' => 'Dies ersetzt die aktuelle Datenbank und die benötigten gespeicherten Dateien durch das Backup. Fortfahren?',
    ],

    'errors' => [
        'backup_configuration_invalid' => 'Die Backup-Einstellungen sind ungültig. Prüfen Sie Aktivierung, fünfteiligen Cron-Zeitplan, Zeitzone, Höchstalter und Aufbewahrungstage.',
        'backup_queue_invalid' => 'Geplante Backups erfordern eine dauerhafte Queue und ein retry_after, das länger als das Backup-Timeout ist. Prüfen Sie Worker- und Queue-Konfiguration.',
        'backup_cleanup_failed' => 'Das Backup ist fehlgeschlagen und sein unvollständiges Verzeichnis konnte nicht entfernt werden. Prüfen Sie die Rechte des Backup-Verzeichnisses und entfernen Sie den unvollständigen Satz.',
        'backup_stale' => 'Ein Backup wurde möglicherweise unterbrochen. Stoppen Sie die Dienste, stellen Sie sicher, dass kein Vorgang mehr läuft, und führen Sie dann bei heruntergefahrenem Laravel vaultigen:backup:recover --services-stopped aus.',
        'backup_overdue' => 'Es gibt kein aktuelles erfolgreiches Backup. Führen Sie ein Backup aus, prüfen Sie Scheduler und Worker und kopieren Sie verifizierte Sätze auf einen anderen Host.',
        'backup_current' => 'Ein aktuelles abgeschlossenes Backup ist vorhanden.',
        'interrupted' => 'Ein Betreiber hat dieses unterbrochene Backup nach dem Stoppen der Dienste als fehlgeschlagen markiert.',
        'unsafe_path' => 'Ein Backup- oder Speicherpfad ist unsicher. Absolute Objektpfade, Verzeichniswechsel nach oben und Links werden abgelehnt.',
        'insufficient_space' => 'Nicht genug freier Speicher für Arbeitsdateien und das endgültige Backup bzw. die Wiederherstellung. Prüfen Sie sowohl das private temporäre Volume als auch das Ziel.',
        'unsupported_format' => 'Dieses Backup-Format wird von dieser Anwendungsversion nicht unterstützt.',
        'database_driver_mismatch' => 'Die Wiederherstellung erfordert dieselbe Datenbank-Engine wie das Backup.',
        'audit_key_missing' => 'Die Wiederherstellung erfordert den ursprünglichen Audit-Schlüssel und die passende Schlüsselkennung.',
        'recovery_input_missing' => 'Ein ursprüngliches Wiederherstellungsgeheimnis fehlt. Stellen Sie APP_KEY, Audit-/Passkey-Geheimnisse und die benötigten Schlüsselbunde wieder her.',
        'recovery_input_mismatch' => 'Ein Wiederherstellungsgeheimnis weicht vom Original ab. Stellen Sie die ursprünglichen Eingaben wieder her; erzeugen Sie keine Ersatzschlüssel.',
        'restore_requires_offline' => 'Führen Sie artisan down aus, stoppen Sie Web, Worker und Scheduler und übergeben Sie dann --services-stopped an die Wiederherstellung.',
        'restore_apply_failed' => 'Die Anwendung der Wiederherstellung ist fehlgeschlagen. Lassen Sie die Dienste offline; storage/framework/restore-in-progress.json markiert die unvollständige Wiederherstellung. Wiederholen Sie sie mit demselben Satz oder beginnen Sie mit einem sauberen Ziel neu.',
        'restore_staging_failed' => 'Die Vorbereitung der Wiederherstellung ist vor dem Datenbankaustausch fehlgeschlagen. Prüfen Sie privaten temporären Speicher, Rechte und Snapshot-Werkzeuge.',
        'inventory_mismatch' => 'Die Dateiverweise im Snapshot stimmen nicht mit dem authentifizierten Objektinventar überein.',

        'generic' => 'Der Vorgang ist fehlgeschlagen. Den bereinigten Grund finden Sie im Anwendungsprotokoll.',
        'maintenance_active' => 'Ein Wartungsfenster ist bereits geöffnet.',
        'backup_in_progress' => 'Ein Backup läuft bereits.',
        'backup_failed' => 'Das Backup ist fehlgeschlagen, bevor es verifiziert werden konnte.',
        'import_in_flight' => 'Ein Import läuft noch. Warten Sie vor dem Backup, bis er abgeschlossen ist.',
        'rotation_in_flight' => 'Eine Schlüsselrotation läuft. Warten Sie zuerst, bis sie abgeschlossen ist.',
        'rotation_in_progress' => 'Eine Schlüsselrotation läuft bereits.',
        'rotation_failed' => 'Die Schlüsselrotation ist fehlgeschlagen.',
        'queue_failed' => 'Der Worker wurde beendet, bevor der Vorgang abgeschlossen war.',
        'nothing_to_rotate' => 'Kein Tresorschlüssel ist noch unter einem älteren Installations-KEK umhüllt.',
        'vault_not_found' => 'Der ausgewählte Tresor existiert nicht mehr.',
        'rotation_not_found' => 'Der Rotationslauf existiert nicht mehr.',
        'unknown_operation' => 'Der Rotationstyp ist unbekannt.',
        'references_remain' => 'Einige Datensätze verweisen noch auf den alten Schlüssel. Setzen Sie die Rotation fort, um sie abzuschließen.',
        'verification_failed' => 'Neu umhüllte Schlüssel konnten nicht verifiziert werden. Es wurde nichts ausgemustert.',
        'backup_required' => 'Erstellen Sie ein verifiziertes Backup, bevor Sie eine alte Tresorschlüssel-Version ausmustern.',
        'manifest_missing' => 'Das Backup-Manifest fehlt im Satz.',
        'manifest_malformed' => 'Das Backup-Manifest konnte nicht gelesen werden.',
        'backup_key_missing' => 'Der Backup-Schlüsselbund fehlt oder enthält den benötigten Backup-Schlüssel nicht.',
        'manifest_authentication_failed' => 'Die Authentifizierung des Backup-Manifests ist fehlgeschlagen; der Satz wurde möglicherweise verändert.',
        'installation_mismatch' => 'Das Backup gehört zu einer anderen Installations-ID.',
        'kek_key_missing' => 'Ein benötigter Installations-KEK fehlt im Schlüsselbund.',
        'app_key_missing' => 'Dieses Backup enthält vom Framework verschlüsselte Authentifizierungsdaten und benötigt den passenden APP_KEY.',
        'passkey_secret_missing' => 'Dieses Backup benötigt das Passkey-User-Handle-Geheimnis.',
        'integrity_failed' => 'Eine Prüfsumme stimmt nicht überein; der Backup-Satz ist beschädigt.',
        'snapshot_unreadable' => 'Der Datenbank-Snapshot konnte nicht gelesen werden.',
        'snapshot_unwritable' => 'Der Datenbank-Snapshot konnte nicht geschrieben werden.',
        'object_missing' => 'Ein referenziertes verschlüsseltes Objekt fehlt im Speicher.',
        'object_unwritable' => 'Ein verschlüsseltes Objekt konnte nicht kopiert werden.',
        'object_unreadable' => 'Ein verschlüsseltes Objekt konnte nicht gelesen werden.',
        'restored' => 'Dieser Vorgang war beim Erstellen des Backups offen und wurde nach der Wiederherstellung zurückgesetzt.',
        'pruned' => 'Die lokale Aufbewahrung hat diesen Satz entfernt. Kopien auf anderen Hosts sind nicht betroffen.',
    ],
];
