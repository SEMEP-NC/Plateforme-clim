<?php
require ' get_db();require 'config/db.php';

$hub = "http://10.0.0.39:8500/read";

/* récupérer équipements */
$equipments = $db->query("SELECT * FROM equipments ORDER BY UI")->fetchAll();

$tags = [];

foreach ($equipments as $eq) {

    $ui  = (int)$eq['UI'];
    $ip  = $eq['ip'];
    $port = $eq['port'];
    $sid = $eq['slave_id'];

    $name = "UI{$ui}_".preg_replace('/[^a-zA-Z0-9_]/', '_', $eq['name']);

    /* adresses GREE */
    $base = 25 * ($ui - 1);

    $addr_onoff = 102 + $base;
    $addr_mode  = 103 + $base;
    $addr_temp  = 104 + $base;
    $addr_fan   = 105 + $base;
    $addr_tamb  = 116 + $base;
    $addr_fault = 319 + 64 * ($ui - 1);

    $common = "ip=$ip&port=$port&device_id=$sid";

    /* ON/OFF */
    $tags[$name.'_OnOff'] = [
        'id' => $name.'_OnOff',
        'name' => "$ui ON/OFF",
        'type' => 'HoldingRegister',
        'getUrl' => "$hub?$common&type=register&address=$addr_onoff&count=1",
        'setUrl' => "http://10.0.0.39:8500/write?$common&type=register&address=$addr_onoff&value={{value}}"
    ];

    /* MODE */
    $tags[$name.'_Mode'] = [
        'id' => $name.'_Mode',
        'name' => "$ui Mode",
        'type' => 'HoldingRegister',
        'getUrl' => "$hub?$common&type=register&address=$addr_mode&count=1"
    ];

    /* CONSIGNE TEMP */
    $tags[$name.'_Temp'] = [
        'id' => $name.'_Temp',
        'name' => "$ui Temp consigne",
        'type' => 'HoldingRegister',
        'options' => ['divisor'=>10],
        'getUrl' => "$hub?$common&type=register&address=$addr_temp&count=1"
    ];

    /* VENTILATION */
    $tags[$name.'_Fan'] = [
        'id' => $name.'_Fan',
        'name' => "$ui Ventilation",
        'type' => 'HoldingRegister',
        'getUrl' => "$hub?$common&type=register&address=$addr_fan&count=1"
    ];

    /* TEMP AMBIANTE */
    $tags[$name.'_Tamb'] = [
        'id' => $name.'_Tamb',
        'name' => "$ui Temp ambiante",
        'type' => 'HoldingRegister',
        'options' => ['divisor'=>10],
        'getUrl' => "$hub?$common&type=register&address=$addr_tamb&count=1"
    ];

    /* DEFAUT */
    $tags[$name.'_Fault'] = [
        'id' => $name.'_Fault',
        'name' => "$ui Défaut",
        'type' => 'Coil',
        'getUrl' => "$hub?$common&type=coils&address=$addr_fault&count=1"
    ];
}

/* device unique */
$device = [[
    'id' => 'clim',
    'name' => 'Climatisation',
    'type' => 'WebAPI',
    'enabled' => true,
    'polling' => 5000,
    'property' => [
        'address' => $hub,
        'method' => 'GET',
        'format' => 'JSON'
    ],
    'tags' => $tags
]];

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="fuxa_clim.json"');

echo json_encode($device, JSON_PRETTY_PRINT);

