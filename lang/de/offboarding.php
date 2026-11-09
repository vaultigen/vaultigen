<?php

return [

    'subheading' => 'Deaktivieren Sie ein Konto, widerrufen Sie seine Sitzungen und entscheiden Sie über jeden Tresor, den es besitzt oder verwaltet. Die Deaktivierung blockiert sofort die Anmeldung und jede authentifizierte Anfrage.',

    'actions' => [
        'offboard' => 'Deaktivieren und offboarden',
    ],

    'sections' => [
        'account' => 'Konto',
        'account_description' => 'Das ausgewählte Konto wird deaktiviert und alle seine Sitzungen und vertrauenswürdigen Geräte werden widerrufen.',
        'personal' => 'Persönliche Arbeitstresore',
        'personal_description' => 'Jeder persönliche Arbeitstresor muss in einen freigegebenen Tresor umgewandelt, archiviert oder zur aufbewahrungsgebundenen Löschung vorgemerkt werden.',
        'custodian' => 'Verwahrerpflichten',
        'custodian_description' => 'Jeder freigegebene Tresor, den dieses Konto verwahrt, benötigt einen neuen Verwahrer. Die Verwahrung gewährt keinen Zugriff auf Geheimnisse.',
    ],

    'fields' => [
        'user' => 'Konto',
        'vault' => 'Tresor',
        'decision' => 'Entscheidung',
        'new_custodian' => 'Neuer Verwahrer',
        'new_manager' => 'Neuer Manager',
    ],

    'decisions' => [
        'convert' => 'In freigegebenen Tresor umwandeln',
        'archive' => 'Archivieren',
        'purge' => 'Zur Löschung vormerken',
    ],

    'report' => [
        'heading' => 'Anmeldedaten zur Rotation',
        'description' => 'Diese Einträge wurden von diesem Konto offengelegt. Rotieren Sie die entsprechenden externen Anmeldedaten nach dem Offboarding; es werden nur Metadaten aufgeführt, niemals ein geheimer Wert.',
        'empty' => 'Dieses Konto hat keine noch gespeicherten Anmeldedaten offengelegt.',
        'last_revealed' => 'zuletzt offengelegt :time',
        'reveal_count' => ':count Offenlegung|:count Offenlegungen',
        'rotation_hint' => 'Das Offenlegen eines Werts garantiert nicht, dass er kopiert wurde, und ein rotierter Wert kann nicht ungesehen gemacht werden. Behandeln Sie jeden aufgeführten Eintrag als potenziell gefährdet.',
    ],

    'notifications' => [
        'offboarded' => ':name wurde offboarded',
        'not_offboarded' => 'Nicht offboarded',
    ],

    'errors' => [
        'cannot_offboard_self' => 'Sie können Ihr eigenes Konto nicht offboarden.',
        'last_super_admin' => 'Sie können den letzten aktiven Super-Admin nicht offboarden.',
        'user_required' => 'Wählen Sie ein Konto zum Offboarden.',
        'personal_decision_required' => 'Wählen Sie eine Entscheidung für den persönlichen Arbeitstresor „:vault“.',
        'custodian_required' => 'Wählen Sie den neuen Verwahrer für „:vault“.',
        'custodian_invalid' => 'Wählen Sie ein aktives Konto, das nicht das offboardete ist.',
        'manager_required' => 'Wählen Sie den neuen Manager für „:vault“.',
        'manager_invalid' => 'Wählen Sie ein aktives Konto, das nicht das offboardete ist.',
        'transfer_required' => 'Wählen Sie einen neuen Verwahrer für „:vault“.',
        'transfer_invalid' => 'Wählen Sie ein aktives Konto, das nicht das offboardete ist.',
        'invalid_decision' => 'Wählen Sie für jeden persönlichen Arbeitstresor eine gültige Entscheidung.',
    ],

];
