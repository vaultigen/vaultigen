<?php

return [

    'columns' => [
        'members' => 'Mitglieder',
        'created' => 'Erstellt',
        'platform_roles' => 'Plattformrollen',
        'added' => 'Hinzugefügt',
        'active' => 'Aktiv',
        'verified' => 'Verifiziert',
    ],

    'actions' => [
        'manage' => 'Team verwalten',
        'create' => 'Team erstellen',
        'back' => 'Teams',
        'add_member' => 'Mitglied hinzufügen',
        'add_member_heading' => 'Teammitglied hinzufügen',
        'add_member_description' => 'Der Benutzer tritt dem Team sofort bei. Er erhält nur den Zugriff, der diesem Team bereits gewährt wurde.',
        'add_member_submit' => 'Mitglied hinzufügen',
        'remove' => 'Entfernen',
        'remove_tooltip' => 'Aus Team entfernen',
        'remove_heading' => 'Teammitglied entfernen',
        'remove_description' => 'Der Benutzer verliert sofort den Zugriff, den dieses Team trägt. Direkt dem Benutzer gewährter Zugriff bleibt unberührt.',
        'remove_selected' => 'Ausgewählte entfernen',
        'remove_selected_description' => 'Jedes ausgewählte Mitglied verliert sofort den Zugriff, den dieses Team trägt.',
    ],

    'fields' => [
        'user' => 'Benutzer',
        'ordinary_user' => 'Gewöhnlicher Benutzer',
    ],

    'filters' => [
        'platform_role' => 'Plattformrolle',
        'active' => 'Aktiv',
        'platform_role_options' => [
            'super_admin' => 'Super-Admin',
            'auditor' => 'Auditor',
            'vault_creator' => 'Tresor-Ersteller',
        ],
    ],

    'empty' => [
        'teams_heading' => 'Noch keine Teams',
        'teams_description' => 'Erstellen Sie ein Team, um Mitgliedschaften zu organisieren.',
        'members_heading' => 'Noch keine Mitglieder',
        'members_description' => 'Fügen Sie einen Benutzer hinzu, damit dieses Team nützlich wird. Zugriff wird nur gewährt, wo dem Team selbst Zugriff gewährt wurde.',
    ],

    'notifications' => [
        'member_added' => 'Mitglied hinzugefügt',
        'member_removed' => 'Mitglied entfernt',
        'members_removed' => 'Mitglieder entfernt',
        'renamed' => 'Team umbenannt',
        'not_renamed' => 'Team nicht umbenannt',
    ],

    'manage' => [
        'title' => 'Team · :name',
        'subheading' => 'Benennen Sie dieses Team um und verwalten Sie, wer dazugehört. Die Teammitgliedschaft gewährt Zugriff über das Team, überall wo dem Team Zugriff gewährt wird.',
        'team_name' => 'Teamname',
        'team_name_description' => 'Der Name wird im Klartext gespeichert und muss in der gesamten Installation eindeutig sein.',
        'save_name' => 'Namen speichern',
        'members' => 'Mitglieder',
        'members_description' => 'Suchen, filtern und wählen Sie, welche Spalten angezeigt werden. Fügen Sie Mitglieder über die Kopfzeile hinzu oder entfernen Sie sie in jeder Zeile.',
    ],

];
