<?php
/*
|--------------------------------------------------------------------------
| EXPORT_CONFIG.PHP
|--------------------------------------------------------------------------
| Exporte une sauvegarde complète de la configuration applicative
| (équipements, groupes, plannings, paramètres admin) au format JSON.
| Réservé aux administrateurs. La restauration se fait via import_config.php
*/

require 'auth.php';
require_admin();
require 'config/db.php';
require 'lib/audit.php';

$db = get_db();

function fetch_all($db, $sql) {
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

$backup = [
    'meta' => [
        'app'         => 'Plateforme-clim',
        'type'        => 'config_backup',
        'version'     => 1,
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'exported_by' => $_SESSION['user']['username'] ?? 'unknown',
    ],

    // Équipements & groupes
    'equipments'                     => fetch_all($db, "SELECT * FROM equipments ORDER BY id"),
    'groups_hvac'                    => fetch_all($db, "SELECT * FROM groups_hvac ORDER BY id"),
    'equipment_groups'               => fetch_all($db, "SELECT * FROM equipment_groups"),

    // Plannings
    'schedules'                      => fetch_all($db, "SELECT * FROM schedules ORDER BY id"),

    // Administration / paramètres système
    'settings'                       => fetch_all($db, "SELECT * FROM settings ORDER BY id"),
    'discovery_config'                => fetch_all($db, "SELECT * FROM discovery_config ORDER BY id"),
    'equipment_temperature_alarms'   => fetch_all($db, "SELECT * FROM equipment_temperature_alarms ORDER BY id"),
    'mail_accounts'                  => fetch_all($db, "SELECT * FROM mail_accounts ORDER BY id"),
    'mail_recipients'                => fetch_all($db, "SELECT * FROM mail_recipients ORDER BY id"),
    'mail_config'                    => fetch_all($db, "SELECT * FROM mail_config ORDER BY id"),

    // Utilisateurs (sans mot de passe : le hash n'est jamais exporté)
    'users' => array_map(function ($u) {
        return [
            'username' => $u['username'],
            'role'     => $u['role'],
        ];
    }, fetch_all($db, "SELECT username, role FROM users ORDER BY id")),
];

audit('EXPORT_CONFIG', 'Export de la configuration complète (sauvegarde)');

$filename = 'clim_backup_' . date('Y-m-d_His') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
