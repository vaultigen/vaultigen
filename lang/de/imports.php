<?php

return [

    'title' => 'Import',
    'subheading' => 'Zugangsdaten aus einem anderen Passwortmanager in neue Tresore übernehmen.',

    'sources' => [
        'syspass_xml' => 'sysPass (verschlüsselter XML-Export)',
        'keepass_xml' => 'KeePass / KeePassXC (XML-Export)',
    ],

    'statuses' => [
        'staging' => 'Wird vorbereitet',
        'staged' => 'Bereit zur Prüfung',
        'queued' => 'Wartet auf Worker',
        'importing' => 'Wird importiert',
        'completed' => 'Abgeschlossen',
        'failed' => 'Fehlgeschlagen',
        'cancelled' => 'Abgebrochen',
    ],

    'columns' => [
        'source' => 'Quelle',
        'file' => 'Datei',
        'result' => 'Ergebnis',
        'requested_by' => 'Angefordert von',
        'created' => 'Erstellt',
    ],

    'values' => [
        'none' => '—',
    ],

    'actions' => [
        'review' => 'Prüfen und importieren',
        'report' => 'Bericht anzeigen',
        'cancel' => 'Abbrechen',
    ],

    'filters' => [
        'status' => 'Status',
    ],

    'empty' => [
        'heading' => 'Noch keine Importe',
        'description' => 'Laden Sie einen Export aus einem anderen Passwortmanager hoch, um zu starten.',
    ],

    'upload' => [
        'heading' => 'Neuer Import',
        'description' => 'Der Export wird einmalig gelesen (und, sofern die Quelle eines hat, mit seinem Exportpasswort entschlüsselt), unter einem installationsgebundenen Importschlüssel neu verschlüsselt und geprüft, bevor etwas geschrieben wird.',
        'no_sources' => 'Es ist keine Importquelle registriert.',
        'password_hint' => 'Wird nur für diesen Upload verwendet; er wird niemals gespeichert.',
        'submit' => 'Upload vorbereiten',
        'hints' => [
            'keepass_xml' => 'KeePass: Datei → Exportieren → KeePass XML (2.x). KeePassXC: Datenbank → Exportieren → XML. Die XML-Datei ist unverschlüsselt: Löschen Sie sie nach dem Import.',
        ],
    ],

    'runs' => [
        'heading' => 'Importverlauf',
        'description' => 'Vergangene und laufende Importe. Nur Zähler und Fehler; es werden keine geheimen Werte gespeichert.',
    ],

    'fields' => [
        'source' => 'Quelle',
        'file' => 'Exportdatei',
        'export_password' => 'Exportpasswort',
        'layout' => 'Layout',
        'folder_axis' => 'Ordnerachse',
        'axis_destination' => 'Ziel der Achse „:axis“',
        'single_vault_name' => 'Tresorname',
        'vault_name' => 'Tresorname',
        'custodian' => 'Verantwortlicher',
        'manager' => 'Manager',
        'default_custodian' => 'Standard-Verantwortlicher',
        'default_manager' => 'Standard-Manager',
        'entry_count' => 'Einträge',
        'vault_count' => 'Tresore',
        'include' => 'Importieren',
        'tag_name' => 'Tag',
        'tag_scope' => 'Gültigkeit',
        'principal' => 'Benutzer oder Team',
        'permission' => 'Rolle',
        'master_password' => 'Master-Passwort',
        'entry_title' => 'Eintrag',
        'context' => 'Kontext',
        'reason' => 'Grund',
    ],

    'destinations' => [
        'vault' => 'Ein eigener Tresor',
        'folder' => 'Ein Ordner',
        'tag' => 'Ein Tag',
        'skip' => 'Überspringen',
    ],

    'axes' => [
        'client' => 'Kunde',
        'category' => 'Kategorie',
        'top_group' => 'Oberste Gruppe',
        'subgroup' => 'Untergruppe',
        'group_path' => 'Gruppenpfad',
        'none' => 'Keine',
    ],

    'permissions' => [
        'viewer' => 'Betrachter',
        'editor' => 'Bearbeiter',
        'manager' => 'Manager',
    ],

    'wizard' => [
        'title' => 'Import prüfen',
        'subheading' => 'Es wird nichts geschrieben, bis Sie den Import bestätigen.',
        'steps' => [
            'setup' => 'Einrichtung',
            'vaults' => 'Tresore',
            'tags' => 'Tags',
            'confirm' => 'Bestätigen und importieren',
        ],
        'step_descriptions' => [
            'setup' => 'Was der Export enthält, wie er auf den Tresor abgebildet wird und wer sonst Zugriff erhält.',
            'vaults' => 'Benennen Sie jeden neuen Tresor, wählen Sie Verantwortlichen und Manager und wählen Sie Tresore zum Überspringen ab.',
            'tags' => 'Ob jedes Tag global oder tresorlokal ist und welche Tags überhaupt importiert werden.',
            'confirm' => 'Geben Sie ein von der Quelle benötigtes Passwort ein und starten Sie den Import. Vor diesem Schritt wird nichts geschrieben.',
        ],
        'sections' => [
            'summary' => 'Was importiert wird',
            'summary_description' => 'Eine kurze Übersicht über den Export. Nur Zählwerte; keine Geheimwerte.',
            'layout' => 'Layout und Achsen',
            'layout_description' => 'Wie die Gruppierungen des Exports auf Tresore, Ordner, Tags und Überspringen abgebildet werden.',
            'access' => 'Zusätzlicher Zugriff',
            'access_description' => 'Standard-Verantwortlicher und Standard-Manager sowie zusätzliche Benutzer oder Teams, angewendet auf jeden von diesem Import erstellten Tresor.',
        ],
        'save_plan' => 'Plan speichern',
    ],

    'confirm' => [
        'heading' => 'Bestätigen und importieren',
        'description' => 'Der Import wird eingereiht und läuft im Hintergrund; den Fortschritt sehen Sie auf dem nächsten Bildschirm. Nach Abschluss kann er nicht fortgesetzt werden.',
        'hints' => [
            'master_password' => 'Wird mit dem Installationsschlüssel für den Hintergrundjob verpackt und nach Ende des Imports entfernt. Lassen Sie es leer, wenn dieser Export kein separates Master-Passwort hat.',
        ],
        'no_credentials' => 'Diese Quelle benötigt kein Passwort. Der Import beginnt, sobald Sie ihn starten.',
        'submit' => 'Import starten',
    ],

    'notifications' => [
        'plan_saved' => 'Importplan gespeichert',
        'plan_invalid' => 'Der Importplan ist ungültig',
        'cancelled' => 'Import abgebrochen und Zwischenspeicher entfernt',
        'cancel_requested' => 'Abbruch angefordert; der Import wird in Kürze beendet',
        'not_cancelled' => 'Der Import konnte nicht abgebrochen werden',
    ],

    'cancel' => [
        'description' => 'Dies entfernt den verschlüsselten Zwischenspeicher und markiert den Lauf als abgebrochen. Bereits erstellte Tresore bleiben unberührt.',
    ],

    'access' => [
        'add' => 'Zugriff hinzufügen',
        'helper' => 'Fügen Sie zusätzliche Benutzer oder Teams hinzu und wählen Sie die Rolle, die jeder für jeden von diesem Import erstellten Tresor erhalten soll (Betrachter, Bearbeiter oder Manager). Der im Schritt „Tresore“ pro Tresor gewählte Verantwortliche und Manager bleiben unverändert.',
        'default_custodian_helper' => 'Wird der Verantwortliche jedes erstellten Tresors. Kann im Schritt „Tresore“ pro Tresor geändert werden.',
        'default_manager_helper' => 'Wird der Manager jedes erstellten Tresors. Kann im Schritt „Tresore“ pro Tresor geändert werden.',
    ],

    'vaults' => [
        'helper' => 'Wenn Sie einen Tresor abwählen, werden er und alle Einträge, die hineingehören würden, übersprungen. Er wird niemals erstellt.',
        'empty_heading' => 'Keine Tresore zu prüfen',
        'empty_description' => 'Der Importplan erstellt noch keine Tresore.',
    ],

    'tags' => [
        'helper' => 'Ein Tag, das in mehr als einem erstellten Tresor verwendet wird, ist standardmäßig global. Globale Tags werden namentlich wiederverwendet. Wenn Sie ein Tag abwählen, wird es vollständig übersprungen.',
        'empty_heading' => 'Keine Tags zu prüfen',
        'empty_description' => 'Der Export enthält für den aktuellen Plan keine Tags.',
    ],

    'review' => [
        'file' => 'Datei',
        'generator' => 'Generator',
        'accounts' => 'Konten',
        'clients' => 'Kunden',
        'categories' => 'Kategorien',
        'tags' => 'Tags',
        'empty_passwords' => 'Leere Passwörter',
        'entries' => 'Einträge',
        'groups' => 'Gruppen',
        'attachments' => 'Anhänge',
        'totp' => 'TOTP-Schlüssel',
        'recycle_bin' => 'Übersprungen: Papierkorb',
        'history' => 'Übersprungen: Verlauf',
        'totp_unsupported' => 'Nicht unterstütztes TOTP',
        'field_references' => 'Feldreferenzen',
        'invalid_attachments' => 'Unlesbare Anhänge',
    ],

    'result' => ':entries Einträge in :vaults Tresoren, :failures fehlgeschlagen',

    'report' => [
        'title' => 'Importbericht',
        'subheading' => 'Zählwerte, Fehler und die von diesem Lauf erstellten Tresore. Es werden keine Geheimwerte angezeigt.',
        'back' => 'Zurück zu den Importen',
        'summary' => ':entries Einträge in :vaults Tresore importiert; :failures fehlgeschlagen.',
        'reason' => 'Grund',
        'counters' => [
            'vaults' => 'Tresore',
            'folders' => 'Ordner',
            'tags' => 'Tags',
            'entries' => 'Einträge',
            'grants' => 'Berechtigungen',
            'attachments' => 'Anhänge',
        ],
        'attachment_summary' => ':attachments Anhänge importiert; :missing Anhänge fehlen. Fügen Sie die fehlenden Dateien den verlinkten Einträgen manuell hinzu.',
        'attachment_position' => 'Anhang :index von :of',
        'vaults_heading' => 'Erstellte Tresore',
        'failures_heading' => 'Fehlgeschlagene Einträge und Anhänge',
        'failures_truncated' => 'Es werden nur die ersten gespeicherten Fehler aufgelistet; der Zähler oben ist exakt.',
        'continue' => 'Diesen Import weiter prüfen',
        'unknown_vault' => 'Unbekannter Tresor',
        'progress' => ':done von :total Einträgen verarbeitet',
        'queue_unavailable_heading' => 'Warten auf einen Queue-Worker',
        'queue_unavailable' => 'Dieser Import ist eingereiht, aber noch kein Queue-Worker hat ihn übernommen. Starten Sie den Queue-Worker, sonst wird der Lauf automatisch als fehlgeschlagen markiert.',
    ],

    'context_labels' => [
        'syspass_xml' => 'Kunde',
        'keepass_xml' => 'Gruppe',
    ],

    'defaults' => [
        'single_vault_name' => 'Importierte Passwörter',
    ],

    'placeholders' => [
        'untitled' => 'Ohne Namen',
        'untitled_entry' => 'Eintrag ohne Namen',
    ],

    'layouts' => [
        'vault_per_client' => 'Ein Tresor pro Kunde',
        'vault_per_category' => 'Ein Tresor pro Kategorie',
        'vault_per_top_group' => 'Ein Tresor pro oberster Gruppe',
        'vault_per_subgroup' => 'Ein Tresor pro Untergruppe',
        'vault_per_group_path' => 'Ein Tresor pro Gruppe',
        'single_vault' => 'Ein einzelner Tresor',
    ],

    'scopes' => [
        'global' => 'Global',
        'local' => 'Tresorlokal',
        'skip' => 'Überspringen',
    ],

    'failure_codes' => [
        'password_decrypt_failed' => 'Das Kontopasswort konnte nicht entschlüsselt werden.',
        'title_too_long' => 'Der Titel ist zu lang.',
        'url_too_long' => 'Die URL ist zu lang.',
        'notes_too_long' => 'Die Notizen sind zu lang.',
        'too_many_custom_fields' => 'Der Eintrag hat zu viele benutzerdefinierte Felder.',
        'custom_field_too_long' => 'Bezeichnung oder Wert eines benutzerdefinierten Feldes ist zu lang.',
        'invalid_base64' => 'Die Anhangsdaten sind kein gültiges Base64.',
        'decompression_failed' => 'Der Anhang konnte nicht entpackt werden.',
        'too_large' => 'Der Anhang überschreitet die Größengrenze pro Datei.',
        'missing_reference' => 'Die Anhangsdaten fehlen im Export.',
        'attachment_rejected' => 'Der Anhang wurde abgelehnt (leer, zu groß oder über dem Tresorkontingent).',
        'attachment_failed' => 'Der Anhang konnte nicht gespeichert werden.',
        'folder_name_invalid' => 'Der Zielordner konnte nicht angelegt werden.',
        'vault_create_failed' => 'Der Tresor konnte nicht angelegt werden.',
        'staging_unavailable' => 'Der zwischengespeicherte Upload ist nicht verfügbar.',
        'master_password_rejected' => 'Das Master-Passwort wurde abgelehnt; es wurde nichts importiert.',
        'queue_failed' => 'Der Hintergrund-Importjob ist fehlgeschlagen.',
        'queue_unavailable' => 'Kein Queue-Worker hat diesen Import rechtzeitig verarbeitet.',
        'import_timeout' => 'Der Importjob wurde nicht rechtzeitig abgeschlossen.',
        'unknown' => 'Der Eintrag konnte nicht importiert werden.',
    ],

    'validation' => [
        'distinct_axes' => 'Die Tresor- und Ordnerachsen müssen unterschiedlich sein.',
        'layout_axis_mismatch' => 'Die Tresorachse passt nicht zum gewählten Layout.',
        'axis_destination' => 'Dieses Ziel ist für die Achse „:axis“ nicht verfügbar.',
        'excluded_axes' => 'Diese Achsen können nicht kombiniert werden.',
        'single_vault_name_required' => 'Geben Sie einen Namen für den Tresor ein.',
    ],

    'errors' => [
        'unreadable_export' => 'Der Export konnte nicht gelesen werden. Prüfen Sie Exportpasswort und Dateiformat.',
        'unreadable_keepass' => 'Die Datei ist kein XML-Export von KeePass 2.x oder KeePassXC.',
        'protected_values' => 'Die Datei enthält verschlüsselte (geschützte) Werte. Exportieren Sie sie aus KeePass oder KeePassXC stattdessen als einfaches XML.',
        'attachments_too_large' => 'Die Anhänge dieses Exports überschreiten die Importgrenze. Es wurde nichts zwischengespeichert.',
        'upload_failed' => 'Der Upload ist fehlgeschlagen. Bitte erneut versuchen.',
        'too_large' => 'Der Export ist zu groß für den Import.',
        'credential_required' => 'Geben Sie das erforderliche Passwort ein.',
        'already_finished' => 'Dieser Import ist bereits abgeschlossen.',
    ],

];
