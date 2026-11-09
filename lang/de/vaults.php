<?php

return [

    'model' => [
        'label' => 'Tresor',
        'plural' => 'Tresore',
    ],

    'actions' => [
        'new_vault' => 'Neuer Tresor',
        'create_vault' => 'Tresor erstellen',
        'vault_administration' => 'Tresorverwaltung',
        'open' => 'Öffnen',
        'edit' => 'Bearbeiten',
        'edit_vault' => 'Tresor bearbeiten',
        'more_actions' => 'Weitere Aktionen',
        'add_grant' => 'Berechtigung hinzufügen',
        'add_or_replace_grant' => 'Berechtigung hinzufügen oder ersetzen',
        'save_grant' => 'Berechtigung speichern',
        'revoke' => 'Widerrufen',
        'transfer_custodian' => 'Treuhänder übertragen',
        'archive' => 'Archivieren',
        'reactivate' => 'Reaktivieren',
        'initiate_purge' => 'Löschung einleiten',
        'cancel_purge' => 'Tresor wiederherstellen',
        'recover_manager' => 'Manager wiederherstellen',
    ],

    'labels' => [
        'vault' => 'Tresor',
        'type' => 'Typ',
        'owner' => 'Eigentümer',
        'custodian' => 'Treuhänder',
        'your_access' => 'Ihr Zugriff',
        'access_expires' => 'Zugriff läuft ab',
        'no_expiry' => 'Kein Ablauf',
        'no_icon' => 'Kein Symbol',
        'image' => 'Bild',
        'image_helper' => 'Optional. Wird statt des Symbols angezeigt und auf einen kleinen quadratischen Avatar verkleinert.',
        'remove_image' => 'Aktuelles Bild entfernen',
        'created' => 'Erstellt',
        'purge_after' => 'Löschdatum',
        'expiry' => 'Ablauf',
        'expires' => 'Läuft ab',
        'granted_by' => 'Gewährt von',
        'unknown' => 'Unbekannt',
        'note' => 'Notiz',
        'user_or_team' => 'Benutzer oder Team',
        'permission' => 'Berechtigung',
        'expires_at_optional' => 'Läuft ab am (optional)',
    ],

    'kinds' => [
        'personal' => 'Persönlicher Arbeitstresor',
        'shared' => 'Gemeinsamer Tresor',
    ],

    'states' => [
        'active' => 'Aktiv',
        'read_only' => 'Schreibgeschützt',
        'archived' => 'Archiviert',
        'pending_purge' => 'Löschung ausstehend',
    ],

    'state_filters' => [
        'active' => 'Aktiv',
        'read_only' => 'Schreibgeschützt',
        'archived' => 'Archiviert',
        'pending_purge' => 'Löschung ausstehend',
    ],

    'permissions' => [
        'viewer' => 'Betrachter',
        'editor' => 'Bearbeiter',
        'manager' => 'Manager',
    ],

    'permission_options' => [
        'viewer' => 'Betrachter (Metadaten und Einsicht)',
        'editor' => 'Bearbeiter (erstellen und aktualisieren)',
        'manager' => 'Manager (volle Kontrolle)',
    ],

    'statuses' => [
        'active' => 'Aktiv',
        'history' => 'Verlauf',
    ],

    'status_filters' => [
        'active' => 'Aktiv',
        'history' => 'Verlauf',
    ],

    'principals' => [
        'user' => 'Benutzer',
        'team' => 'Team',
        'unknown' => 'Unbekannt',
    ],

    'scopes' => [
        'vault' => 'Tresor',
        'folder' => 'Ordner',
        'entry' => 'Eintrag',
    ],

    'principal' => [
        'team_name' => 'Team: :name',
    ],

    'grant_trail' => [
        'expires' => 'läuft ab am :date',
        'no_expiry' => 'kein Ablauf',
    ],

    'notifications' => [
        'vault_created' => 'Tresor erstellt',
        'vault_updated' => 'Tresor aktualisiert',
        'vault_archived' => 'Tresor archiviert',
        'vault_reactivated' => 'Tresor reaktiviert',
        'vault_marked_pending_purge' => 'Tresor zur Löschung vorgemerkt',
        'vault_purge_cancelled' => 'Tresor wiederhergestellt',
        'vault_recovered' => 'Tresor wiederhergestellt',
        'custodian_transferred' => 'Treuhänder übertragen',
        'grant_saved' => 'Berechtigung gespeichert',
        'grant_not_saved' => 'Berechtigung nicht gespeichert',
        'grant_revoked' => 'Berechtigung widerrufen',
        'grant_not_revoked' => 'Berechtigung nicht widerrufen',
        'folder_renamed' => 'Ordner umbenannt',
        'folder_not_renamed' => 'Ordner nicht umbenannt',
    ],

    'errors' => [
        'invalid_image' => 'Das Bild muss eine PNG-, JPEG-, GIF- oder WebP-Datei mit höchstens 2 MB und 4096 × 4096 Pixeln sein.',
        'already_pending_purge' => 'Für diesen Tresor ist die Löschung bereits vorgemerkt.',
        'not_pending_purge' => 'Für diesen Tresor ist keine Löschung vorgemerkt.',
        'recover_pending_purge' => 'Stellen Sie diesen Tresor zuerst aus der ausstehenden Löschung wieder her.',
        'pending_purge_state_change' => 'Ein Tresor mit ausstehender Löschung kann seinen Status nicht ändern.',
        'already_in_state' => 'Der Tresor befindet sich bereits in diesem Status.',
        'recovery_not_required' => 'Dieser Tresor hat noch einen aktiven Manager, daher ist keine Wiederherstellung erforderlich.',
        'not_editable' => 'Dieser Tresor kann im aktuellen Status nicht bearbeitet werden.',
        'not_allowed_to_govern' => 'Sie sind nicht berechtigt, diesen Tresor zu verwalten.',
        'grant_already_inactive' => 'Diese Berechtigung ist bereits inaktiv.',
        'keep_one_manager' => 'Ein Tresor muss mindestens eine aktive Manager-Berechtigung behalten.',
        'manager_membership_only' => 'Nur ein Tresor-Manager kann die Mitgliedschaft ändern.',
        'manager_additional_grants_only' => 'Nur ein Tresor-Manager kann zusätzliche Berechtigungen ändern.',
        'expiry_after_authority' => 'Das Ablaufdatum der Berechtigung darf nicht nach Ihrem eigenen Zugriffsablauf liegen.',
        'membership_vault_inactive' => 'Dieser Tresor ist nicht aktiv, daher kann seine Mitgliedschaft nicht geändert werden.',
        'grants_vault_inactive' => 'Dieser Tresor ist nicht aktiv, daher können seine Berechtigungen nicht geändert werden.',
        'creation_limit_reached' => 'Das Limit für die Tresorerstellung dieser Installation ist erreicht. Bitten Sie einen Administrator, das Kontingent zu erhöhen.',
    ],

    'my_vaults' => [
        'create_modal' => [
            'heading' => 'Tresor erstellen',
            'description' => 'Sie werden Manager und Treuhänder dieses Tresors. Ein Geheimnis, das vor dem Manager eines Tresors verborgen bleiben muss, gehört in einen eigenen Tresor.',
            'access_helper' => 'Für alle mit Zugriff sichtbar. Fügen Sie keine Geheimnisse ein.',
        ],
        'created_body' => 'Sie sind sein Manager und Treuhänder. Fügen Sie Mitglieder über den Tresor hinzu.',
        'empty' => [
            'heading' => 'Noch keine Tresore verfügbar',
            'description' => 'Sie sehen nur Tresore, die Ihnen gewährt wurden. Ein Tresor-Manager muss Zugriff gewähren, und jedes Konto erhält automatisch einen persönlichen Arbeitstresor.',
        ],
    ],

    'list_vaults' => [
        'create_modal' => [
            'heading' => 'Eine Tresor-Hülle erstellen',
            'description' => 'Der anfängliche Manager erhält eine Manager-Berechtigung. Das Erstellen des Tresors gewährt Ihnen keinen Zugriff.',
            'metadata_helper' => 'Für alle mit Metadatenzugriff sichtbar. Fügen Sie keine Geheimnisse ein.',
        ],
        'fields' => [
            'primary_custodian' => 'Primärer Treuhänder',
            'primary_custodian_helper' => 'Verantwortlich für den Tresor. Diese Rolle gewährt für sich genommen keinen Datenzugriff.',
            'initial_manager' => 'Anfänglicher Manager',
            'initial_manager_helper' => 'Erhält bei der Erstellung des Tresors eine ausdrückliche Manager-Berechtigung.',
        ],
        'created_body' => 'Der anfängliche Manager steuert nun Mitgliedschaft und Datenzugriff.',
    ],

    'infolist' => [
        'sections' => [
            'your_access' => 'Ihr Zugriff',
        ],
    ],

    'vaults_table' => [
        'empty' => [
            'heading' => 'Keine Tresore zu verwalten',
            'description' => 'Sie sind weder Treuhänder noch Manager eines Tresors und verwalten die Installation nicht.',
        ],
        'transfer_modal' => [
            'heading' => 'Treuhänderschaft übertragen',
            'description' => 'Der neue Treuhänder verwaltet den Lebenszyklus dieses Tresors, ohne Zugriff auf seine Geheimnisse zu erhalten.',
            'new_custodian' => 'Neuer Treuhänder',
        ],
        'archive_modal' => [
            'description' => 'Archivierte Tresore sind eingefroren: Mitgliedschaft und Metadaten können bis zur Reaktivierung nicht geändert werden.',
        ],
        'purge_modal' => [
            'description' => 'Ab sofort hat niemand mehr Zugriff auf den Tresor. Ein Super-Admin kann ihn bis zum Ende der Wartezeit wiederherstellen; danach wird er endgültig gelöscht.',
        ],
        'cancel_purge_modal' => [
            'description' => 'Versetzt den Tresor in seinen vorherigen Status zurück. Inhalte und Zugriffe bleiben unverändert.',
        ],
        'recover_modal' => [
            'heading' => 'Einen nicht verwalteten Tresor wiederherstellen',
            'description' => 'Nur verwenden, wenn ein Tresor keinen aktiven Manager hat. Dies wird streng re-authentifiziert, erfordert eine Begründung und wird prominent protokolliert.',
            'new_manager' => 'Neuer Manager',
            'reason_helper' => 'Fügen Sie keine Passwörter oder andere Geheimnisse ein.',
            'your_password' => 'Ihr Passwort',
            'your_code' => 'Ihr aktueller Authentifizierungscode',
        ],
    ],

    'members' => [
        'title' => 'Mitglieder',
        'empty' => [
            'heading' => 'Noch keine Berechtigungen',
            'description' => 'Fügen Sie einen Benutzer oder ein Team hinzu, um ihm Zugriff auf diesen Tresor zu geben.',
        ],
        'grant_modal' => [
            'heading' => 'Tresorzugriff gewähren',
            'replace_description' => 'Das Speichern einer Berechtigung für einen Berechtigten, der bereits eine hat, ersetzt sie und behält die vorherige Berechtigung im Verlauf.',
            'authority_helper' => 'Sie können nicht mehr gewähren als Ihre eigene effektive Berechtigung.',
            'expiry_helper' => 'Wird bei jeder Anfrage geprüft. Darf nicht nach Ihrem eigenen Zugriffsablauf liegen.',
        ],
        'revoke_modal' => [
            'description' => 'Der Berechtigte verliert den Zugriff sofort. Der Verlauf bleibt erhalten.',
        ],
    ],

    'scoped_access' => [
        'title' => [
            'folder' => 'Ordner bearbeiten · ',
            'entry' => 'Zusätzlicher Zugriff · ',
        ],
        'subheading' => [
            'folder' => 'Benennen Sie diesen Ordner um und verwalten Sie die zusätzlichen Berechtigungen, die auf die darin enthaltenen Einträge wirken.',
            'entry' => 'Berechtigungen hier sind additiv. Sie können Zugriff auf diesen Eintrag hinzufügen, aber niemals aus dem Tresor oder seinem Ordner geerbten Zugriff verringern.',
        ],
        'back' => [
            'folder' => 'Ordner',
            'entry' => 'Eintrag',
        ],
        'folder_section' => [
            'heading' => 'Ordnername',
            'description' => 'Nur ein Tresor-Manager kann Ordner umbenennen. Der Name wird im Klartext gespeichert und ist innerhalb des Tresors eindeutig.',
            'save_name' => 'Namen speichern',
        ],
        'vault_access' => [
            'heading' => 'Zugriff auf den gesamten Tresor',
            'description' => 'Diese Personen können auf diesen :kind zugreifen, weil sie eine Berechtigung für den gesamten Tresor besitzen. Sie werden nicht in der obigen Tabelle aufgeführt, die nur direkt auf diesen :kind gewährte Berechtigungen zeigt.',
            'empty_heading' => 'Niemand hat Zugriff auf den gesamten Tresor',
            'empty_description' => 'Niemand erreicht diesen :kind über eine tresorweite Berechtigung. Alle, die ihn erreichen können, sind in der obigen Tabelle aufgeführt.',
        ],
        'empty' => [
            'heading' => 'Keine zusätzlichen Berechtigungen',
            'description' => 'Alle mit Zugriff erreichen dieses Ziel über eine Tresor- oder Ordnerberechtigung. Fügen Sie nur dann eine hinzu, wenn jemand sie ohne umfassenderen Zugriff benötigt.',
        ],
        'grant_modal' => [
            'heading' => 'Zusätzliche Berechtigung hinzufügen oder ersetzen',
            'replace_description' => 'Das Speichern einer Berechtigung für einen Berechtigten, der bereits eine hat, ersetzt sie und behält die vorherige Berechtigung im Verlauf.',
            'authority_helper' => 'Sie können nicht mehr gewähren als Ihre eigene effektive Berechtigung.',
            'expiry_helper' => 'Wird bei jeder Anfrage geprüft. Darf nicht nach Ihrem eigenen Zugriffsablauf liegen.',
        ],
        'revoke_modal' => [
            'description' => 'Der Berechtigte verliert diesen zusätzlichen Zugriff sofort. Der Verlauf bleibt erhalten.',
        ],
    ],

    'about' => [
        'your_access' => 'Ihr Zugriff: :permission',
        'expires_badge' => 'Läuft ab am :date',
        'scoped_access' => 'Bereichsbezogener Zugriff',
        'callout' => [
            'heading' => 'Organisationsverwalteter Tresor',
            'description' => 'Vaultigen ist serververtrauend: Der Host-Betreiber kann auf gespeicherte Geheimnisse zugreifen. Ein persönlicher Arbeitstresor ist kein privater Verbraucherspeicher und unterliegt weiterhin der Aufbewahrung durch die Organisation und dem Notfallzugriff.',
        ],
        'why_heading' => 'Warum kann ich das sehen?',
        'why_description' => 'Diese aktiven Berechtigungen gewähren Ihnen Zugriff. Abgelaufene oder widerrufene Berechtigungen funktionieren sofort nicht mehr.',
    ],

    'personal_vault' => [
        'name' => 'Arbeitstresor von :name',
        'description' => 'Organisationsverwalteter persönlicher Arbeitstresor. Der Host-Betreiber kann darauf zugreifen.',
    ],

];
