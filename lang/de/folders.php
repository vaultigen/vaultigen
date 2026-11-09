<?php

return [

    'title' => 'Ordner',

    'subheading' => 'Nur ein Tresor-Manager kann Ordner erstellen. Verwenden Sie Bearbeiten, um einen Ordner umzubenennen oder zu verwalten, wer auf die darin enthaltenen Einträge zugreifen kann.',

    'actions' => [
        'entries' => 'Einträge',
        'new_folder' => 'Neuer Ordner',
        'new_entry' => 'Neuer Eintrag',
        'edit' => 'Bearbeiten',
        'delete' => 'Löschen',
        'trash' => 'Papierkorb',
    ],

    'columns' => [
        'entries' => 'Einträge',
        'updated' => 'Aktualisiert',
    ],

    'create_modal' => [
        'heading' => 'Ordner erstellen',
        'description' => 'Ordner haben eine einzige Ebene. Verschachtelte Ordner werden nicht unterstützt.',
    ],

    'empty' => [
        'heading' => 'Noch keine Ordner',
        'description' => 'Erstellen Sie einen Ordner, um Einträge zu gruppieren. Einträge ohne Ordner bleiben im Wurzelbereich des Tresors und sind für alle mit Tresorzugriff sichtbar.',
    ],

    'delete_modal' => [
        'description' => 'Diesen Ordner in den Papierkorb verschieben? Nur ein leerer Ordner kann gelöscht werden; verschieben oder löschen Sie zuerst seine Einträge. Ordner werden nie mit ihrem Inhalt gelöscht.',
    ],

    'notifications' => [
        'created' => 'Ordner erstellt',
        'not_created' => 'Ordner nicht erstellt',
        'deleted' => 'Ordner in den Papierkorb verschoben',
        'not_deleted' => 'Ordner nicht gelöscht',
    ],

    'errors' => [
        'manager_only' => 'Nur ein Tresor-Manager kann Ordner verwalten.',
        'vault_inactive' => 'Dieser Tresor ist nicht aktiv, daher können seine Ordner nicht geändert werden.',
        'name_required' => 'Geben Sie einen Ordnernamen ein.',
        'stale' => 'Dieser Ordner wurde geändert, seit Sie ihn geöffnet haben. Laden Sie neu und versuchen Sie es erneut.',
        'duplicate' => 'Ein Ordner mit diesem Namen existiert bereits in diesem Tresor.',
        'not_empty' => 'Verschieben oder löschen Sie die Einträge in diesem Ordner, bevor Sie den Ordner löschen. Ordner werden nie mit ihrem Inhalt gelöscht.',
    ],

];
