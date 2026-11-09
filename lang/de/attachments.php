<?php

return [

    'heading' => 'Anhänge',
    'description' => 'Dateien werden vor dem Speichern mit einem eigenen Schlüssel verschlüsselt. Dateinamen und erkannter Typ werden ebenfalls verschlüsselt, und jeder Download wird protokolliert.',

    'empty' => [
        'heading' => 'Noch keine Anhänge',
        'description' => 'Lade eine verschlüsselte Datei hoch, um sie mit diesem Eintrag zu speichern.',
    ],

    'columns' => [
        'filename' => 'Datei',
        'size' => 'Größe',
        'uploaded_by' => 'Hochgeladen von',
        'uploaded_at' => 'Hochgeladen',
    ],

    'quota' => [
        'label' => 'Tresorspeicher',
        'usage' => ':used von :limit belegt',
        'per_file' => 'Bis zu :size pro Datei.',
    ],

    'upload' => [
        'label' => 'Datei hinzufügen',
        'help' => 'Bis zu :size. Die Datei wird vor dem Schreiben verschlüsselt und nie inline angezeigt.',
        'submit' => 'Hochladen und verschlüsseln',
        'busy' => 'Verschlüsseln und hochladen…',
    ],

    'actions' => [
        'download' => 'Herunterladen',
        'remove' => 'Entfernen',
    ],

    'remove_confirm' => 'Diesen Anhang entfernen? Er wandert in den Papierkorb des Tresors und wird nach der Aufbewahrungsfrist endgültig gelöscht.',

    'notifications' => [
        'uploaded' => 'Anhang hochgeladen',
        'deleted' => 'Anhang entfernt',
    ],

    'errors' => [
        'heading' => 'Die Datei konnte nicht hinzugefügt werden.',
    ],

    'validation' => [
        'too_large' => 'Die Datei ist größer als das Limit von 25 MB pro Datei.',
        'vault_quota' => 'Dieser Tresor hat sein Speicherlimit von 500 MB erreicht.',
        'missing' => 'Wähle eine Datei zum Hochladen.',
        'upload_failed' => 'Der Upload wurde nicht abgeschlossen. Wähle die Datei erneut.',
        'empty' => 'Die Datei ist leer.',
        'unreadable' => 'Die hochgeladene Datei konnte nicht gelesen werden.',
    ],

    'sizes' => [
        'bytes' => ':count B',
        'kilobytes' => ':count KB',
        'megabytes' => ':count MB',
    ],

];
