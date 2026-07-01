<?php
require 'auth.php';
session_start();
require 'config/db.php';

$db = get_db();

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
| FUXA doit pouvoir joindre le Modbus Hub sur cette adresse.
| Si besoin, la variable d'environnement HUB_URL_EXTERNAL peut surcharger.
*/
$hubReadUrl = getenv('HUB_URL_EXTERNAL') ?: 'http://localhost:8500/read';
$hubWriteUrl = getenv('HUB_WRITE_URL_EXTERNAL') ?: 'http://localhost:8500/write';

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function clean_id($value) {
    $value = (string)$value;

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim($value, '_');

    return $value !== '' ? $value : 'equipement';
}

/*
| Le hub renvoie : { "success": true, "cached": false, "registers": [ ... ] }
| ou registers[N] correspond directement au registre Modbus N.
| Pour que FUXA (device WebAPI) sache extraire la bonne valeur de la
| reponse JSON, le champ "address" du tag doit utiliser la syntaxe
| confirmee cote FUXA/hub : registers:[102]  (deux-points avant le crochet)
*/
function jsonata_register_address($register) {
    return 'registers:[' . (int)$register . ']';
}

function add_tag(&$tags, $tagId, $name, $type, $register, $rw, $description, $extra = []) {
    $tag = array_merge([
        'name' => $name,
        'type' => $type,
        // Adresse utilisee par FUXA pour lire la valeur dans la reponse JSON du hub
        'address' => jsonata_register_address($register),
        // Numero de registre Modbus brut, conserve pour reference / ecriture
        'register' => $register,
        'rw' => $rw,
        'read' => true,
        'write' => $rw,
        'description' => $description
    ], $extra);

    $tags[$tagId] = $tag;
}

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/
$equipments = $db->query("SELECT * FROM equipments ORDER BY UI ASC, name ASC")
    ->fetchAll(PDO::FETCH_ASSOC);

$groups = $db->query("SELECT * FROM groups_hvac ORDER BY name ASC")
    ->fetchAll(PDO::FETCH_ASSOC);

$groupNamesById = [];
foreach ($groups as $group) {
    $groupNamesById[(int)$group['id']] = $group['name'];
}

$equipmentGroups = [];
$stmt = $db->query("SELECT equipment_id, group_id FROM equipment_groups");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $equipmentId = (int)$row['equipment_id'];
    $groupId = (int)$row['group_id'];

    if (isset($groupNamesById[$groupId])) {
        $equipmentGroups[$equipmentId][] = $groupNamesById[$groupId];
    }
}

/*
|--------------------------------------------------------------------------
| DEVICE UNIQUE FUXA
|--------------------------------------------------------------------------
| Tous les equipements sont regroupes dans un seul appareil FUXA.
*/
$device = [
    'id' => 'climatisations',
    'name' => 'Climatisations',
    'type' => 'WebAPI',
    'enabled' => true,
    'polling' => 5000,
    'property' => [
        'address' => $hubReadUrl,
        'method' => 'GET',
        'format' => 'JSON'
    ],
    'tags' => []
];

/*
|--------------------------------------------------------------------------
| REGISTRES GREE
|--------------------------------------------------------------------------
| Pour chaque UI : base = 25 * (UI - 1)
|
| 102 + base : Marche / Arret
| 103 + base : Mode
| 104 + base : Consigne temperature, valeur / 10
| 105 + base : Consigne ventilation
| 116 + base : Temperature ambiante, valeur / 10
| 123 + base : Puissance / information lecture
|
| Defaut general : 408 + 64 * (UI - 1) (coil)
*/
foreach ($equipments as $equipment) {
    $equipmentId = (int)$equipment['id'];
    $ui = (int)$equipment['UI'];
    $base = 25 * ($ui - 1);

    $name = trim($equipment['name'] ?? ('UI-' . $ui));
    $ip = trim($equipment['ip'] ?? '');
    $port = (int)($equipment['port'] ?? 502);
    $slaveId = (int)($equipment['slave_id'] ?? 1);
    $powerRaw = $equipment['power'] ?? '';

    if (is_numeric($powerRaw)) {
        $powerLabel = number_format(((float)$powerRaw) / 10, 1, '.', '') . ' kW';
    } else {
        $powerLabel = (string)$powerRaw;
    }

    $groupList = $equipmentGroups[$equipmentId] ?? [];
    $groupLabel = !empty($groupList) ? implode(', ', $groupList) : 'Sans groupe';

    $prefixId = 'ui' . $ui . '_' . clean_id($name);
    $displayPrefix = 'UI ' . $ui . ' - ' . $name;

    $meta = 'UI: ' . $ui
        . ' | Nom: ' . $name
        . ' | Puissance: ' . $powerLabel
        . ' | IP: ' . $ip
        . ' | Port: ' . $port
        . ' | Slave: ' . $slaveId
        . ' | Groupe: ' . $groupLabel;

    add_tag(
        $device['tags'],
        $prefixId . '_marche_arret',
        $displayPrefix . ' - Marche / Arret',
        'holding',
        102 + $base,
        true,
        $meta . ' | Commande marche/arret Modbus',
        [
            'writeUrl' => $hubWriteUrl,
            'equipment' => [
                'id' => $equipmentId,
                'ui' => $ui,
                'name' => $name,
                'power' => $powerLabel,
                'ip' => $ip,
                'port' => $port,
                'slave_id' => $slaveId,
                'groups' => $groupList
            ]
        ]
    );

    add_tag(
        $device['tags'],
        $prefixId . '_mode',
        $displayPrefix . ' - Mode',
        'holding',
        103 + $base,
        true,
        $meta . ' | Mode operation',
        ['writeUrl' => $hubWriteUrl]
    );

    add_tag(
        $device['tags'],
        $prefixId . '_consigne_temperature',
        $displayPrefix . ' - Consigne temperature',
        'holding',
        104 + $base,
        true,
        $meta . ' | Consigne temperature. Valeur en dixiemes de degre.',
        [
            'scale' => 0.1,
            'unit' => '°C',
            'writeUrl' => $hubWriteUrl
        ]
    );

    add_tag(
        $device['tags'],
        $prefixId . '_consigne_ventilation',
        $displayPrefix . ' - Consigne ventilation',
        'holding',
        105 + $base,
        true,
        $meta . ' | Vitesse / consigne ventilation',
        ['writeUrl' => $hubWriteUrl]
    );

    add_tag(
        $device['tags'],
        $prefixId . '_temperature_ambiante',
        $displayPrefix . ' - Temperature ambiante',
        'holding',
        116 + $base,
        false,
        $meta . ' | Temperature ambiante. Lecture seule. Valeur en dixiemes de degre.',
        [
            'scale' => 0.1,
            'unit' => '°C'
        ]
    );

    add_tag(
        $device['tags'],
        $prefixId . '_puissance',
        $displayPrefix . ' - Puissance',
        'holding',
        123 + $base,
        false,
        $meta . ' | Puissance / information lecture seule',
        ['unit' => 'W']
    );

    add_tag(
        $device['tags'],
        $prefixId . '_defaut_general',
        $displayPrefix . ' - Defaut general',
        'coil',
        408 + (64 * ($ui - 1)),
        false,
        $meta . ' | Defaut general / alarme'
    );
}

/*
|--------------------------------------------------------------------------
| EXPORT
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="equipments_export.json"');

echo json_encode([$device], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;