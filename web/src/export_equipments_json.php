<?php
require 'config/db.php';

$db = get_db();

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
| Le Hub Modbus est accessible par FUXA sur 10.0.0.39:8500.
|--------------------------------------------------------------------------
*/
$hubReadUrl = getenv('HUB_URL_EXTERNAL') ?: 'http://10.0.0.39:8500/read';
$hubWriteUrl = getenv('HUB_WRITE_URL_EXTERNAL') ?: 'http://10.0.0.39:8500/write';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function clean_id(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim($value, '_');

    return $value !== '' ? $value : 'equipement';
}

function add_tag(array &$tags, string $id, array $tag): void
{
    $tags[$id] = $tag;
}

/*
|--------------------------------------------------------------------------
| Détection structure DB
|--------------------------------------------------------------------------
*/
$hasGroupsTable = table_exists($db, 'groups_hvac');
$hasGroupId = column_exists($db, 'equipments', 'group_id');

/*
|--------------------------------------------------------------------------
| Chargement équipements
|--------------------------------------------------------------------------
*/
if ($hasGroupsTable && $hasGroupId) {
    $sql = "
        SELECT 
            e.*,
            g.name AS group_name
        FROM equipments e
        LEFT JOIN groups_hvac g ON g.id = e.group_id
        WHERE COALESCE(e.enabled, 1) = 1
        ORDER BY e.UI, e.name
    ";
} else {
    $sql = "
        SELECT 
            e.*,
            NULL AS group_name
        FROM equipments e
        WHERE COALESCE(e.enabled, 1) = 1
        ORDER BY e.UI, e.name
    ";
}

$equipments = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Device unique FUXA
|--------------------------------------------------------------------------
| Tous les équipements clim sont regroupés dans un seul équipement FUXA.
|--------------------------------------------------------------------------
*/
$device = [
    "id" => "climatisations",
    "name" => "Climatisations",
    "type" => "WebAPI",
    "enabled" => true,
    "polling" => 5000,
    "property" => [
        "address" => $hubReadUrl,
        "method" => "GET",
        "format" => "JSON"
    ],
    "tags" => []
];

/*
|--------------------------------------------------------------------------
| Registres GREE
|--------------------------------------------------------------------------
| Pour UI n :
| Base = 25 * (UI - 1)
|
| Marche / Arrêt              : 102 + base
| Mode                        : 103 + base
| Consigne température        : 104 + base
| Consigne ventilation        : 105 + base
| Température ambiante        : 116 + base
| Puissance / état puissance  : 123 + base
|
| Défaut général :
| 319 + 64 * (UI - 1)
|--------------------------------------------------------------------------
*/
foreach ($equipments as $eq) {
    $ui = (int)($eq['UI'] ?? 1);
    $base = 25 * ($ui - 1);

    $name = trim($eq['name'] ?? ('UI-' . $ui));
    $ip = trim($eq['ip'] ?? '');
    $port = (int)($eq['port'] ?? 502);
    $slaveId = (int)($eq['slave_id'] ?? 1);
    $power = $eq['power'] ?? '';
    $groupName = $eq['group_name'] ?: 'Sans groupe';

    $safeName = clean_id($name);
    $prefixId = "ui{$ui}_{$safeName}";

    $displayPrefix = "UI {$ui} - {$name}";
    $meta = "IP: {$ip} | Port: {$port} | Slave: {$slaveId} | Puissance: {$power} | Groupe: {$groupName}";

    /*
    |--------------------------------------------------------------------------
    | Metadata visibles dans FUXA
    |--------------------------------------------------------------------------
    | Ces informations sont intégrées dans les noms / descriptions des TAGs.
    |--------------------------------------------------------------------------
    */

    add_tag($device["tags"], "{$prefixId}_marche_arret", [
        "name" => "{$displayPrefix} - Marche / Arrêt",
        "description" => $meta,
        "type" => "coil",
        "address" => 102 + $base,
        "register" => 102 + $base,
        "rw" => true,
        "read" => true,
        "write" => true,
        "writeUrl" => $hubWriteUrl,
        "equipment" => [
            "ui" => $ui,
            "name" => $name,
            "ip" => $ip,
            "port" => $port,
            "slave_id" => $slaveId,
            "power" => $power,
            "group" => $groupName
        ]
    ]);

    add_tag($device["tags"], "{$prefixId}_mode", [
        "name" => "{$displayPrefix} - Mode",
        "description" => $meta . " | Mode opération",
        "type" => "holding",
        "address" => 103 + $base,
        "register" => 103 + $base,
        "rw" => true,
        "read" => true,
        "write" => true,
        "writeUrl" => $hubWriteUrl,
        "equipment" => [
            "ui" => $ui,
            "name" => $name,
            "ip" => $ip,
            "port" => $port,
            "slave_id" => $slaveId,
            "power" => $power,
            "group" => $groupName
        ]
    ]);

    add_tag($device["tags"], "{$prefixId}_consigne_temperature", [
        "name" => "{$displayPrefix} - Consigne température",
        "description" => $meta . " | Valeur en dixièmes de degré, scale 0.1",
        "type" => "holding",
        "address" => 104 + $base,
        "register" => 104 + $base,
        "rw" => true,
        "read" => true,
        "write" => true,
        "scale" => 0.1,
        "unit" => "°C",
        "writeUrl" => $hubWriteUrl,
        "equipment" => [
            "ui" => $ui,
            "name" => $name,
            "ip" => $ip,
            "port" => $port,
            "slave_id" => $slaveId,
            "power" => $power,
            "group" => $groupName
        ]
    ]);

    add_tag($device["tags"], "{$prefixId}_consigne_ventilation", [
        "name" => "{$displayPrefix} - Consigne ventilation",
        "description" => $meta . " | Vitesse ventilation",
        "type" => "holding",
        "address" => 105 + $base,
        "register" => 105 + $base,
        "rw" => true,
        "read" => true,
        "write" => true,
        "writeUrl" => $hubWriteUrl,
        "equipment" => [
            "ui" => $ui,
            "name" => $name,
            "ip" => $ip,
            "port" => $port,
            "slave_id" => $slaveId,
            "power" => $power,
            "group" => $groupName
        ]
    ]);

    add_tag($device["tags"], "{$prefixId}_temperature_ambiante", [
        "name" => "{$displayPrefix} - Température ambiante",
        "description" => $meta . " | Lecture seule, valeur en dixièmes de degré, scale 0.1",
        "type" => "holding",
        "address" => 116 + $base,
        "register" => 116 + $base,
        "rw" => false,
        "read" => true,
        "write" => false,
        "scale" => 0.1,
        "unit" => "°C",
        "equipment" => [
            "ui" => $ui,
            "name" => $name,
            "ip" => $ip,
            "port" => $port,
            "slave_id" => $slaveId,
            "power" => $power,
            "group" => $groupName
        ]
    ]);

    add_tag($device["tags"], "{$prefixId}_puissance", [
        "name" => "{$displayPrefix} - Puissance",
        "description" => $meta . " | Puissance équipement",
        "type" => "holding",
        "address" => 123 + $base,
        "register" => 123 + $base,
        "rw" => false,
        "read" => true,
        "write" => false,
        "unit" => "W",
        "equipment" => [
            "ui" => $ui,
            "name" => $name,
            "ip" => $ip,
            "port" => $port,
            "slave_id" => $slaveId,
            "power" => $power,
            "group" => $groupName
        ]
    ]);

    add_tag($device["tags"], "{$prefixId}_defaut_general", [
        "name" => "{$displayPrefix} - Défaut général",
        "description" => $meta . " | Alarme / défaut général",
        "type" => "discrete",
        "address" => 319 + (64 * ($ui - 1)),
        "register" => 319 + (64 * ($ui - 1)),
        "rw" => false,
        "read" => true,
        "write" => false,
        "equipment" => [
            "ui" => $ui,
            "name" => $name,
            "ip" => $ip,
            "port" => $port,
            "slave_id" => $slaveId,
            "power" => $power,
            "group" => $groupName
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| Export JSON
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="fuxa_tags_clim.json"');

echo json_encode([$device], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;