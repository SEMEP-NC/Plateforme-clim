<?php
/**
 * export_fuxa.php  —  à placer dans web/
 *
 * Génère un JSON importable dans FUXA (Import devices) avec 3 TAGs par
 * équipement : ON/OFF (coil), Température consigne, Puissance détectée.
 *
 * Les noms de colonnes correspondent à la vraie table `equipments` :
 *   id, name, UI, ip, slave_id, power, modbus_port (default 502)
 */

require 'config/db.php';
$db = get_db();

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="fuxa_tags_clim.json"');

// URL du Modbus-Hub visible depuis FUXA (adapter si FUXA est sur une autre machine)
$hub_read  = getenv('HUB_URL_EXTERNAL')       ?: 'http://localhost:8500/read';
$hub_write = getenv('HUB_WRITE_URL_EXTERNAL') ?: 'http://localhost:8500/write';

$equipments = $db->query("
    SELECT id, name, UI, ip, slave_id, power, modbus_port
    FROM equipments
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($equipments)) {
    echo json_encode([]);
    exit;
}

$fuxa_devices = [];

foreach ($equipments as $eq) {
    $ui   = (int)$eq['UI'];
    $ip   = $eq['ip'];
    $port = isset($eq['modbus_port']) && $eq['modbus_port'] ? (int)$eq['modbus_port'] : 502;
    $sid  = (int)$eq['slave_id'];

    // Adresses Modbus (formule du projet)
    $addr_onoff = 102 + 25 * ($ui - 1);   // coil commande ON/OFF
    $addr_temp  = 104 + 25 * ($ui - 1);   // registre température (×10 = °C)
    $addr_power = 123 + 25 * ($ui - 1);   // registre puissance détectée

    $device_id = 'clim_' . $eq['id'];

    $tags = [];

    // ── TAG 1 : ON/OFF (coil) ────────────────────────────────────────────────
    $tid = $device_id . '_OnOff';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' ON/OFF',
        'type'    => 'Coil',
        'address' => $addr_onoff,
        'options' => ['rawTrue' => 170, 'rawFalse' => 85],
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=coils&address={$addr_onoff}&count=1",
        'setUrl'  => "{$hub_write}?ip={$ip}&port={$port}&device_id={$sid}&type=coil&address={$addr_onoff}&value={{value}}",
    ];

    // ── TAG 2 : Température consigne ─────────────────────────────────────────
    $tid = $device_id . '_Temp';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' Temp consigne (°C)',
        'type'    => 'HoldingRegister',
        'address' => $addr_temp,
        'options' => ['divisor' => 10],
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_temp}&count=1",
        'setUrl'  => "{$hub_write}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_temp}&value={{value}}",
    ];

    // ── TAG 3 : Puissance détectée (lecture seule) ───────────────────────────
    $tid = $device_id . '_Power';
    $tags[$tid] = [
        'id'      => $tid,
        'name'    => $eq['name'] . ' Puissance (W)',
        'type'    => 'HoldingRegister',
        'address' => $addr_power,
        'options' => [],
        'getUrl'  => "{$hub_read}?ip={$ip}&port={$port}&device_id={$sid}&type=register&address={$addr_power}&count=1",
    ];

    $fuxa_devices[] = [
        'id'       => $device_id,
        'name'     => $eq['name'],
        'type'     => 'WebAPI',
        'enabled'  => true,
        'polling'  => 5000,
        'property' => [
            'address' => $hub_read,
            'method'  => 'GET',
            'format'  => 'JSON',
        ],
        'tags'     => $tags,
    ];
}

echo json_encode($fuxa_devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
