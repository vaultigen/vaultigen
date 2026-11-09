<?php

return [

    'types' => [
        'login' => 'Login',
        'note' => 'Sichere Notiz',
        'ssh_key' => 'SSH-Schlüssel',
        'totp' => 'TOTP',
    ],

    'fields' => [
        'login' => [
            'username' => 'Benutzername',
            'password' => 'Passwort',
            'url' => 'Vollständige URL',
            'notes' => 'Notizen',
            'totp_secret' => 'Authenticator-Seed',
        ],
        'note' => [
            'body' => 'Notiz',
        ],
        'ssh_key' => [
            'public_key' => 'Öffentlicher Schlüssel',
            'private_key' => 'Privater Schlüssel',
            'passphrase' => 'Passphrase',
            'notes' => 'Notizen',
        ],
        'totp' => [
            'issuer' => 'Aussteller',
            'account' => 'Konto',
            'secret' => 'Geheimnis',
            'algorithm' => 'Algorithmus',
            'digits' => 'Stellen',
            'period' => 'Periode',
            'notes' => 'Notizen',
        ],
    ],

    'common' => [
        'owner' => 'Besitzer',
        'folder' => 'Ordner',
        'tags' => 'Tags',
        'no_folder' => 'Kein Ordner',
        'unknown' => 'Unbekannt',
        'updated' => 'Aktualisiert',
    ],

    'state' => [
        'read_only' => [
            'heading' => 'Dieser Tresor ist schreibgeschützt',
            'description' => 'Einträge, Ordner, Tags und Mitgliedschaft können nicht geändert werden, solange der Tresor schreibgeschützt ist. Alles bleibt lesbar und kann offengelegt werden.',
        ],
        'archived' => [
            'heading' => 'Dieser Tresor ist archiviert',
            'description' => 'Ein archivierter Tresor ist eingefroren. Aktivieren Sie ihn über die Tresorverwaltung, bevor Sie Änderungen vornehmen; Lesen und Offenlegen funktionieren weiterhin.',
        ],
        'pending_purge' => [
            'heading' => 'Für diesen Tresor steht die Löschung aus',
            'description' => 'Der Tresor ist eingefroren und zur endgültigen Entfernung vorgemerkt. Lesen und Offenlegen funktionieren weiterhin, bis ein Administrator die Löschung bestätigt.',
        ],
    ],

    'validation' => [
        'choose_supported_type' => 'Wählen Sie einen unterstützten Eintragstyp.',
        'provide_key' => 'Geben Sie mindestens einen öffentlichen oder einen privaten Schlüssel an.',
        'entry_too_large' => 'Dieser Eintrag ist zu groß zum Speichern. Kürzen Sie die Notizen oder benutzerdefinierten Werte.',
        'invalid_totp_secret' => 'Geben Sie ein gültiges Base32-Authenticator-Geheimnis ein (mindestens 80 Bit).',
        'too_many_custom_fields' => 'Zu viele benutzerdefinierte Felder (maximal :max).',
        'custom_label_too_long' => 'Beschriftungen benutzerdefinierter Felder sind auf :max Zeichen begrenzt.',
        'custom_value_too_long' => 'Werte benutzerdefinierter Felder sind auf :max Zeichen begrenzt.',
        'folder_not_in_vault' => 'Wählen Sie einen Ordner, der zu diesem Tresor gehört.',
        'vault_not_active' => 'Dieser Tresor ist nicht aktiv, daher können seine Einträge nicht geändert werden.',
        'stale_entry' => 'Dieser Eintrag wurde geändert, seit Sie ihn geöffnet haben. Laden Sie die Seite neu und wenden Sie Ihre Änderungen erneut an.',
        'owner_not_allowed' => 'Wählen Sie einen Besitzer, der Zugriff auf diesen Tresor hat.',
    ],

    'errors' => [
        'update_not_allowed' => 'Sie sind nicht berechtigt, diesen Eintrag zu aktualisieren.',
        'folder_placement_not_allowed' => 'Sie sind nicht berechtigt, einen Eintrag in diesem Ordner abzulegen.',
        'move_not_allowed' => 'Sie sind nicht berechtigt, diesen Eintrag zu verschieben.',
        'move_exposes_history' => 'Durch dieses Verschieben würde der Versionsverlauf des Eintrags offengelegt. Das darf nur ein Manager. Bitten Sie einen Manager dieses Eintrags, ihn zu verschieben.',
        'destination_placement_not_allowed' => 'Sie sind nicht berechtigt, einen Eintrag am Zielort abzulegen.',
        'history_not_allowed' => 'Sie sind nicht berechtigt, den Verlauf dieses Eintrags anzuzeigen.',
        'restore_not_allowed' => 'Sie sind nicht berechtigt, diesen Eintrag wiederherzustellen.',
        'trash_restore_not_allowed' => 'Sie sind nicht berechtigt, diesen Eintrag aus dem Papierkorb wiederherzustellen.',
        'delete_not_allowed' => 'Sie sind nicht berechtigt, diesen Eintrag zu löschen.',
        'folder_create_manager_only' => 'Nur ein Tresor-Manager kann hier einen Ordner erstellen.',
        'create_in_folder_not_allowed' => 'Sie sind nicht berechtigt, einen Eintrag in diesem Ordner zu erstellen.',
    ],

    'js' => [
        'copy_failed' => 'Kopieren in die Zwischenablage fehlgeschlagen',
        'copied_to_clipboard' => 'In die Zwischenablage kopiert',
        'copied_label' => ':label kopiert',
        'none_stored' => 'Kein Wert für :label gespeichert',
        'code_copied' => 'Code kopiert',
        'not_available' => 'Nicht verfügbar',
        'show' => 'Anzeigen',
        'hide' => 'Ausblenden',
        'show_password' => 'Passwort anzeigen',
        'hide_password' => 'Passwort ausblenden',
        'password_start' => 'Mit der Eingabe beginnen',
        'password_select_set' => 'Wählen Sie mindestens einen Zeichensatz',
        'password_weak' => 'Schwach',
        'password_fair' => 'Mittelmäßig',
        'password_good' => 'Gut',
        'password_strong' => 'Stark',
        'leave_blank' => 'Leer lassen, um den aktuellen Wert beizubehalten',
    ],

    'form' => [
        'title' => [
            'edit' => ':type bearbeiten',
            'new' => ':type anlegen',
        ],
        'heading' => [
            'edit' => ':type-Eintrag bearbeiten',
            'new' => 'Neuer Eintrag',
            'description' => 'Nur der Titel, der Eintragstyp, der bereinigte Login-Host, der Besitzer, das Ablaufdatum und die Ordner-/Tag-Zuordnung werden im Klartext gespeichert. Alle anderen Werte werden vor dem Schreiben verschlüsselt.',
        ],
        'section' => [
            'details' => 'Eintragsdetails',
            'organization' => 'Organisation',
            'totp' => 'Authenticator-Parameter',
        ],
        'entry_type' => [
            'label' => 'Eintragstyp',
            'helper' => 'Der Typ ist nach der Erstellung festgelegt. Ein Wechsel des Typs löscht die bereits eingegebenen Werte.',
        ],
        'owner' => [
            'helper' => 'Nur Personen mit Zugriff auf diesen Tresor können Besitzer eines Eintrags sein.',
        ],
        'expires_at' => [
            'label' => 'Läuft ab am (optional)',
        ],
        'organization' => [
            'description' => 'Ordner und Tags sind Metadaten. Sie werden im Klartext gespeichert und unterstützen Suche und Gruppierung; sie ändern nie, wer das Geheimnis einsehen darf.',
        ],
        'folder' => [
            'helper_scoped' => 'Sie können diesen Eintrag auf der obersten Ebene des Tresors oder in einem beliebigen Ordner ablegen.',
            'helper_unscoped' => 'Wählen Sie einen der Ordner, auf die Sie Zugriff haben. Das Ablegen auf der obersten Ebene erfordert Tresor-weiten Zugriff.',
            'root_option' => 'Kein Ordner (Tresor-Wurzel)',
        ],
        'new_folder' => [
            'label' => 'Neuen Ordner erstellen (optional)',
            'helper' => 'Wenn ausgefüllt, wird der Ordner erstellt und dieser Eintrag darin abgelegt.',
        ],
        'tags' => [
            'placeholder' => 'Tag hinzufügen',
            'helper' => 'Wählen Sie ein vorhandenes Tag oder geben Sie einen neuen Namen ein. Neue Tags werden in diesem Tresor gespeichert.',
        ],
        'totp' => [
            'description' => 'Nicht geheime Einstellungen zur Erzeugung von Codes für einen TOTP-Eintrag.',
        ],
        'algorithm' => [
            'label' => 'Algorithmus',
        ],
        'digits' => [
            'label' => 'Stellen',
        ],
        'period' => [
            'label' => 'Periode (Sekunden)',
        ],
        'title_field' => [
            'label' => 'Titel',
        ],
        'secret_note' => 'Lassen Sie das Feld leer, um den aktuellen Wert beizubehalten, oder geben Sie einen Ersatz ein. Geheimfelder werden nie vorausgefüllt.',
        'username' => [
            'label' => 'Benutzername',
        ],
        'url' => [
            'label' => 'Vollständige URL',
            'hint' => 'Die vollständige URL bleibt verschlüsselt; in Listen wird nur ihr Host angezeigt.',
        ],
        'password' => [
            'label' => 'Passwort',
            'generate' => 'Generieren',
            'length' => 'Länge',
            'symbols' => 'Symbole',
            'bits_of_entropy' => 'Bit Entropie',
        ],
        'notes' => [
            'label' => 'Notizen',
        ],
        'totp_seed' => [
            'label' => 'Authenticator-Seed (optional)',
            'placeholder_saved' => 'Ein Seed ist gespeichert — lassen Sie das Feld leer, um ihn beizubehalten',
            'placeholder_new' => 'Base32-Geheimnis, z. B. JBSWY3DPEHPK3PXP',
            'hint' => 'Optional. Die Eintragsseite zeigt den aktuellen Einmalcode für dieses Konto an.',
        ],
        'totp_warning' => [
            'heading' => 'Passwort und zweiten Faktor zusammen zu speichern verringert die Trennung der Faktoren',
            'description' => 'Bewahren Sie bei Konten mit hohem Wert das Passwort und den Authenticator-Seed unter getrenntem Zugriff auf, indem Sie den Seed in einem eigenen Tresor statt neben dem Passwort ablegen.',
        ],
        'note' => [
            'label' => 'Notiz',
        ],
        'public_key' => [
            'label' => 'Öffentlicher Schlüssel',
        ],
        'private_key' => [
            'label' => 'Privater Schlüssel',
            'hidden' => 'Verborgen — zum Bearbeiten einblenden',
        ],
        'ssh_key' => [
            'hint' => 'Geben Sie mindestens einen öffentlichen oder einen privaten Schlüssel an. Private Schlüssel sind standardmäßig maskiert.',
        ],
        'passphrase' => [
            'label' => 'Passphrase',
        ],
        'issuer' => [
            'label' => 'Aussteller',
        ],
        'account' => [
            'label' => 'Konto',
        ],
        'secret' => [
            'label' => 'Geheimnis',
            'hint' => 'Base32-Geheimnis aus dem Authenticator. Es ist verschlüsselt und wird nur bei der Offenlegung angezeigt.',
        ],
        'custom_fields' => [
            'heading' => 'Benutzerdefinierte Felder',
            'description' => 'Jeder benutzerdefinierte Wert wird verschlüsselt. Verborgene Werte bleiben verborgen, bis sie offengelegt werden; sichtbare Werte werden auf der Eintragsseite angezeigt.',
            'label' => 'Beschriftung',
            'value' => 'Wert',
            'concealed' => 'Verborgen',
            'remove' => 'Entfernen',
            'add' => 'Benutzerdefiniertes Feld hinzufügen',
        ],
        'edit_notice' => 'Geheimfelder bleiben absichtlich leer. Lassen Sie sie leer, um die aktuellen Werte beizubehalten, oder geben Sie einen Ersatz ein.',
        'save_changes' => 'Änderungen speichern',
        'create_entry' => 'Eintrag erstellen',
        'cancel' => 'Abbrechen',
    ],

    'detail' => [
        'action' => [
            'entries' => 'Einträge',
            'edit' => 'Bearbeiten',
            'move' => 'Verschieben',
            'access' => 'Zusätzlicher Zugriff',
            'delete' => 'Löschen',
            'refresh_icon' => 'Icon aktualisieren',
        ],
        'delete_confirm' => 'Diesen Eintrag in den Papierkorb verschieben? Er kann bis zu seiner Löschfrist wiederhergestellt und danach endgültig entfernt werden.',
        'deleted' => 'Eintrag in den Papierkorb verschoben',
        'icon_refresh_queued' => 'Icon-Aktualisierung eingeplant',
        'not_deleted' => 'Eintrag nicht gelöscht',
        'custom_field' => 'Benutzerdefiniertes Feld',
        'concealed' => 'Verborgen',
        'copy' => 'Kopieren',
        'copied' => 'Kopiert',
        'reveal' => 'Anzeigen',
        'restored' => [
            'title' => 'Revision wiederhergestellt',
            'body' => 'Die vorherigen Werte sind zurück, und der ersetzte Zustand bleibt im Verlauf erhalten.',
        ],
        'key_values' => [
            'heading' => 'Gespeicherte Werte',
            'description' => 'Jedes Feld wird einzeln erst nach einer erneuten Berechtigungsprüfung und einem Prüfeintrag übermittelt. Offengelegte Werte werden nach 30 Sekunden von der Seite entfernt.',
        ],
        'reveal_unavailable' => [
            'heading' => 'Offenlegung ist nicht verfügbar',
            'description' => 'Ihr Zugriff auf diesen Tresor erlaubt es nicht, geheime Werte offenzulegen.',
        ],
        'totp' => [
            'heading' => 'Authenticator-Code',
            'description' => 'Der aktuelle Einmalcode wird aus dem gespeicherten Seed erzeugt und bei jeder Übermittlung protokolliert.',
            'show' => 'Aktuellen Code anzeigen',
            'refreshing' => 'Aktualisieren…',
            'error' => 'Der Code konnte nicht erzeugt werden. Prüfen Sie den gespeicherten Seed.',
            'seconds_remaining' => 'Sek. verbleibend',
        ],
        'expiry' => 'Ablauf',
        'no_expiry' => 'Kein Ablaufdatum',
        'expired' => 'Abgelaufen :time',
        'access' => [
            'heading' => 'Zugriff',
            'description' => 'Zugriff ist additiv. Ein Tresor-, Ordner- oder Eintrags-Grant kann jeweils Reichweite hinzufügen; keiner davon kann einen anderen einschränken.',
            'can_edit_heading' => 'Sie können diesen Eintrag ändern',
            'can_edit_description' => 'Ihre Berechtigung für diesen Tresor erlaubt es Ihnen, jeden gespeicherten Wert zu bearbeiten.',
            'read_only_heading' => 'Nur-Lese-Zugriff',
            'read_only_description' => 'Ihre Berechtigung erlaubt es Ihnen, diesen Eintrag anzusehen, aber nicht zu ändern.',
        ],
        'managers_only' => 'Nur Manager können den Verlauf einsehen und Revisionen wiederherstellen.',
        'history' => [
            'heading' => 'Verlauf',
            'description' => 'Jede Aktualisierung speichert das vorherige verschlüsselte Dokument. Bei der Wiederherstellung bleibt der ersetzte Zustand im Verlauf erhalten und die Änderung wird protokolliert.',
            'empty_heading' => 'Noch keine Revisionen',
            'empty_description' => 'Dieser Eintrag wurde seit seiner Erstellung nicht geändert.',
            'revision' => 'Revision :number',
            'restore' => 'Wiederherstellen',
            'restore_confirm' => 'Diese Revision wiederherstellen? Die aktuellen Werte bleiben in der Historie erhalten.',
        ],
    ],

    'move' => [
        'title' => 'Eintrag verschieben',
        'subheading' => 'Der Zugriff ändert sich unmittelbar nach dem Verschieben. Zusätzliche Berechtigungen werden gegen das Ziel erneut geprüft.',
        'section' => [
            'destination' => 'Ziel',
            'access' => 'Auswirkungen auf den Zugriff',
        ],
        'destination' => [
            'description' => 'Wählen Sie, wo der Eintrag liegen soll. Sie können ihn nur dorthin verschieben, wo Sie Einträge erstellen dürfen.',
        ],
        'vault' => [
            'label' => 'Tresor',
            'helper' => 'Beim Verschieben zwischen Tresoren wird der Eintrag unter dem Schlüssel des Ziel-Tresors neu verschlüsselt.',
        ],
        'new_folder' => [
            'label' => 'Oder einen neuen Ordner erstellen',
            'helper' => 'Ein Tresor-Manager kann den Ordner im Rahmen des Verschiebens erstellen.',
        ],
        'impact' => [
            'destination' => 'Personen mit einer Tresor- oder Ordner-Berechtigung am Ziel erhalten sofort Zugriff.',
            'lose_access' => 'Personen, die diesen Eintrag nur über den alten Tresor, Ordner oder eine Eintrags-Berechtigung erreicht haben, verlieren den Zugriff.',
            'cross_vault' => 'Beim Verschieben zwischen Tresoren werden die Tresor-gebundenen Tag-Beschriftungen entfernt, Sie werden zum Besitzer und der Versionsverlauf wird für die Manager des Zieltresors sichtbar.',
        ],
        'not_moved' => 'Eintrag nicht verschoben',
        'moved' => 'Eintrag verschoben',
        'submit' => 'Eintrag verschieben',
        'cancel' => 'Abbrechen',
        'confirm' => 'Diesen Eintrag verschieben? Personen mit Zugriff auf das Ziel erhalten sofort Zugriff, und Personen, die ihn nur über den alten Tresor, Ordner oder eine Eintragsberechtigung erreicht haben, verlieren den Zugriff.',
    ],

    'list' => [
        'subheading' => 'Einträge · Die Suche findet Titel, Host, Typ, Ordner und Tag-Namen. Benutzernamen, Passwörter und Notizen bleiben verschlüsselt und werden nie durchsucht.',
        'action' => [
            'my_vaults' => 'Meine Tresore',
            'new_entry' => 'Neuer Eintrag',
            'about' => 'Über diesen Tresor',
            'close' => 'Schließen',
            'manage_folders' => 'Ordner verwalten',
            'manage_tags' => 'Tags verwalten',
            'trash' => 'Papierkorb',
            'edit_vault' => 'Tresor bearbeiten',
            'manage_members' => 'Mitglieder verwalten',
            'vault_actions' => 'Tresor-Aktionen',
            'copy_username' => 'Benutzername kopieren',
            'copy_password' => 'Passwort kopieren',
            'copy_passphrase' => 'Passphrase kopieren',
            'open' => 'Öffnen',
        ],
        'no_icon' => 'Kein Symbol',
        'vault_updated' => 'Tresor aktualisiert',
        'column' => [
            'host' => 'Host',
            'expires' => 'Läuft ab',
        ],
        'placeholder' => [
            'no_expiry' => 'Kein Ablaufdatum',
        ],
        'filter' => [
            'tag' => 'Tag',
        ],
        'search_placeholder' => 'Titel, Host, Typ, Ordner, Tag suchen',
        'empty' => [
            'heading' => 'Hier sind noch keine Einträge',
            'description' => 'Keine Einträge entsprechen dieser Ansicht. Die Suche betrachtet nur Metadaten, niemals verschlüsselte Werte.',
        ],
    ],

];
