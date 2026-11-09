<?php

return [

    'title' => 'Papierkorb · :vault',

    'subheading' => 'Gelöschte Einträge und Ordner bleiben hier bis zu ihrer Löschfrist. Nur ein Tresor-Manager kann den Papierkorb einsehen und wiederherstellen.',

    'actions' => [
        'restore' => 'Wiederherstellen',
    ],

    'columns' => [
        'deleted' => 'Gelöscht',
        'purge_after' => 'Löschen nach',
    ],

    'retention_notice' => 'Elemente im Papierkorb werden nach :days Tagen endgültig gelöscht. Der geplante Löschauftrag versucht es erneut und protokolliert einen bereinigten Fehler, wenn ein Element nicht entfernt werden kann.',

    'restore_entry_description' => 'Diesen Eintrag im Tresor wiederherstellen. Er wird wieder seinem früheren Ordner zugeordnet, wenn dieser noch existiert, andernfalls kehrt er in den Wurzelbereich des Tresors zurück („Kein Ordner“).',

    'notifications' => [
        'entry_restored' => 'Eintrag wiederhergestellt',
        'folder_restored' => 'Ordner wiederhergestellt',
        'not_restored' => 'Nicht wiederhergestellt',
    ],

    'entries' => [
        'heading' => 'Gelöschte Einträge',
        'empty_heading' => 'Keine gelöschten Einträge',
        'empty_description' => 'In den Papierkorb verschobene Einträge erscheinen hier mit ihrer Löschfrist.',
    ],

    'folders' => [
        'heading' => 'Gelöschte Ordner',
        'description' => 'Ein Ordner kann nur gelöscht werden, wenn er keine aktiven Einträge enthält. Seine Einträge werden zuerst verschoben oder gelöscht, niemals kaskadierend.',
        'empty_heading' => 'Keine gelöschten Ordner',
        'empty_description' => 'In den Papierkorb verschobene Ordner erscheinen hier mit ihrer Löschfrist.',
    ],

];
