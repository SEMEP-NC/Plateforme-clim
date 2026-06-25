<?php
/**
 * export_fuxa.php
 * Génère UN SEUL device FUXA avec tous les équipements
 */

require 'config/db.php';

try {
    $db = get_db();
} catch (PDOException $e) {
    die("Erreur DB : " . $e->getMessage());
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="fuxa_tags_clim.json"');

/* ✅ URLs HUB */
$hub_read  = getenv('HUB_URL_EXTERNAL')       ?: 'http://10.0.0.39:8500/read';
$hub_write = getenv('HUB_WRITE_URL_EXTERNAL') ?: 'http://10.0.0.39:8500/write';

/* ✅ SELECT avec vraie colonne port */
$equipments = $db->query("
    SELECT id, name, UI, ip, port, slave_id, power
    FROM equipments
    ORDER BY UI
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($equipments)) {
    echo json_encode([]);
    exit;
}

$tags = [];

foreach ($equipments as $eq) {

    $ui = (int)$eq['UI'];
    if ($ui <= 0) continue;

    $ip   = $eq['ip'];
    $port = !empty($eq['port']) ? (int)$eq['port'] : 502;
    $sid  = (int)$eq['slave_id'];
    $n    = $ui;

    /* ✅ Adresses GREE */
    $addr_onoff    = 102 + 25 * ($n - 1);
    $addr_mode     = 103 + 25 * ($n - 1);
    $addr_temp     = 104 + 25 * ($n - 1);
    $addr_speed    = 105 + 25 * ($n - 1);
    $addr_tambiant = 116 + 25 * ($n - 1);
    $addr_power    = 123 + 25 * ($n - 1);
    $addr_fault    = 319 + 64 * ($n - 1);

    $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $eq['name']);
    $pfx  = "UI{$ui}_{$safe}";

    // ───────────── ON/OFF ─────────────
    $tid = $pfx . '_OnOff';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' ON/OFF',
        'type'    => 'HoldingRegister',
        'address' => $addr_onoff,
        'options' => ['rawTrue' => 170, 'rawFalse' => 85],
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_onoff}&count=1",
        'setUrl'  => "{$hub_write}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_onoff}&value={{value}}",
    ];

    // ───────────── MODE ─────────────
    $tid = $pfx . '_Mode';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' Mode',
        'type'    => 'HoldingRegister',
        'address' => $addr_mode,
        'options' => [
            'enumValues' => [
                0 => 'Invalide',
                1 => 'Froid',
                2 => 'Déshumidification',
                3 => 'Ventilation',
                4 => 'Chaud',
                5 => 'Auto',
                6 => 'Plancher chauffant',
                7 => 'Chaud rapide',
                8 => 'Apport chaleur',
            ]
        ],
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_mode}&count=1",
        'setUrl'  => "{$hub_write}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_mode}&value={{value}}",
    ];

    // ───────────── TEMP CONSIGNE ─────────────
    $tid = $pfx . '_TempConsigne';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' Temp consigne (°C)',
        'type'    => 'HoldingRegister',
        'address' => $addr_temp,
        'options' => ['divisor' => 10],
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_temp}&count=1",
        'setUrl'  => "{$hub_write}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_temp}&value={{value}}",
    ];

    // ───────────── VENTILATION ─────────────
    $tid = $pfx . '_VitesseVentil';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' Vitesse ventilateur',
        'type'    => 'HoldingRegister',
        'address' => $addr_speed,
        'options' => [
            'enumValues' => [
                0 => 'Invalide',
                1 => 'Auto',
                2 => 'Bas',
                3 => 'Moyen-bas',
                4 => 'Moyen',
                5 => 'Moyen-haut',
                6 => 'Haut',
                7 => 'Turbo',
            ]
        ],
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_speed}&count=1",
        'setUrl'  => "{$hub_write}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_speed}&value={{value}}",
    ];

    // ───────────── TEMP AMBIANTE ─────────────
    $tid = $pfx . '_TempAmbiante';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' Temp ambiante (°C)',
        'type'    => 'HoldingRegister',
        'address' => $addr_tambiant,
        'options' => ['divisor' => 10],
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_tambiant}&count=1",
    ];

    // ───────────── PUISSANCE ─────────────
    $tid = $pfx . '_Puissance';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' Puissance (hW)',
        'type'    => 'HoldingRegister',
        'address' => $addr_power,
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_power}&count=1",
    ];

    // ───────────── DEFAUT ─────────────
    $tid = $pfx . '_Defaut';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' Défaut général',
        'type'    => 'Coil',
        'address' => $addr_fault,
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=coils&address={$addr_fault}&count=1",
    ];
}

/* ✅ UN SEUL DEVICE */
$fuxa_device = [[
    'id'       => 'clim_central',
    'name'     => 'Climatisation',
    'type'     => 'WebAPI',
    'enabled'  => true,
    'polling'  => 5000,
    'property' => [
        'address' => $hub_read,
        'method'  => 'GET',
        'format'  => 'JSON',
    ],
    'tags'     => $tags,
]];

echo json_encode($fuxa_device, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);