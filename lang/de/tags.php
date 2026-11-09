<?php

return [

    'title' => 'Tags',
    'back' => 'Einträge',

    'columns' => [
        'entries' => 'Einträge',
        'vaults' => 'Tresore',
        'updated' => 'Aktualisiert',
    ],

    'filters' => [
        'assigned' => 'Einträgen zugewiesen',
        'not_assigned' => 'Nicht zugewiesen',
        'any' => 'Beliebig',
        'used_in_vault' => 'Verwendet in Tresor',
        'scope' => 'Geltungsbereich',
        'assigned_in_vault' => 'In diesem Tresor zugewiesen',
        'not_assigned_in_vault' => 'In diesem Tresor nicht zugewiesen',
    ],

    'scope' => [
        'global' => 'Global',
        'vault' => 'Dieser Tresor',
    ],

    'groups' => [
        'scope' => 'Geltungsbereich',
        'global' => 'Globale Tags',
        'vault' => 'Tags dieses Tresors',
    ],

    'placeholder' => [
        'no_color' => 'Keine Farbe',
    ],

    'options' => [
        'global_suffix' => ':name (global)',
    ],

    'actions' => [
        'new_global_tag' => 'Neuer globaler Tag',
        'create_global_heading' => 'Globalen Tag erstellen',
        'new_tag' => 'Neuer Tag',
        'create_heading' => 'Tag erstellen',
        'create_description' => 'Dieser Tag ist nur in diesem Tresor verfügbar.',
        'rename' => 'Umbenennen',
        'rename_global_heading' => 'Globalen Tag umbenennen',
        'edit' => 'Bearbeiten',
        'edit_heading' => 'Tag bearbeiten',
        'delete' => 'Löschen',
        'delete_global_heading' => 'Globalen Tag löschen',
        'delete_heading' => 'Tag löschen',
        'delete_submit' => 'Tag löschen',
    ],

    'empty' => [
        'global_heading' => 'Noch keine globalen Tags',
        'global_description' => 'Erstellen Sie hier einen Tag, um ihn in jedem Tresor verfügbar zu machen.',
        'heading' => 'Noch keine Tags',
        'description' => 'Fügen Sie einen Tag hinzu, um Einträge ordnerübergreifend zu kennzeichnen.',
    ],

    'warning' => [
        'not_attached' => 'Dieser Tag ist keinem Eintrag zugewiesen. Er kann bedenkenlos gelöscht werden.',
        'attached_entries' => '{1} Dieser Tag ist :count Eintrag zugewiesen|[2,*] Dieser Tag ist :count Einträgen zugewiesen',
        'across_vaults' => '{1} in :count Tresor.|[2,*] in :count Tresoren.',
        'in_vaults' => 'In: :vaults.',
        'removes_label' => 'Beim Löschen wird die Kennzeichnung von diesen Einträgen entfernt.',
        'attached_entries_vault' => '{1} Dieser Tag ist :count Eintrag in diesem Tresor zugewiesen.|[2,*] Dieser Tag ist :count Einträgen in diesem Tresor zugewiesen.',
    ],

    'notifications' => [
        'global_created' => 'Globaler Tag erstellt',
        'global_not_created' => 'Globaler Tag nicht erstellt',
        'global_updated' => 'Globaler Tag aktualisiert',
        'global_not_updated' => 'Globaler Tag nicht aktualisiert',
        'global_deleted' => 'Globaler Tag gelöscht',
        'created' => 'Tag erstellt',
        'not_created' => 'Tag nicht erstellt',
        'updated' => 'Tag aktualisiert',
        'not_updated' => 'Tag nicht aktualisiert',
        'deleted' => 'Tag gelöscht',
        'not_deleted' => 'Tag nicht gelöscht',
    ],

    'subheading' => 'Globale Tags sind in jedem Tresor verfügbar. Nur tresorinterne Tags werden innerhalb eines Tresors verwaltet.',
    'manage_subheading' => 'Globale Tags sind überall verfügbar; Tags, die Sie hier hinzufügen, gehören nur zu diesem Tresor. Ein Eintrag kann mehrere tragen.',

    'errors' => [
        'no_access_to_add' => 'Sie haben keinen Zugriff, um in diesem Tresor Tags hinzuzufügen.',
        'choose_valid' => 'Wählen Sie Tags, die global sind oder zu diesem Tresor gehören.',
        'enter_name' => 'Geben Sie einen Tag-Namen ein.',
        'global_manager_only' => 'Nur ein Super-Admin kann installationsweite Tags verwalten.',
        'editor_required' => 'Sie benötigen Editor-Zugriff, um die Tags dieses Tresors zu verwalten.',
        'vault_not_active' => 'Dieser Tresor ist nicht aktiv, daher können seine Tags nicht geändert werden.',
        'duplicate_vault' => 'Ein Tag mit diesem Namen existiert in diesem Tresor bereits.',
        'duplicate_global' => 'Ein globaler Tag mit diesem Namen existiert bereits.',
        'duplicate_global_shadow' => 'Ein globaler Tag mit diesem Namen existiert bereits. Verwenden Sie stattdessen den globalen Tag.',
    ],

];
