<?php
require 'config/db.php';

$db = get_db();

/*
|--------------------------------------------------------------------------
| fuxa_write.php
|--------------------------------------------------------------------------
| API d'ecriture pour la telecommande universelle FUXA.
|
| Exemples :
|   Marche :       /fuxa_write.php?cmd=power&value=1
|   Arret :        /fuxa_write.php?cmd=power&value=0
|   Mode :         /fuxa_write.php?cmd=mode&value=1
|   Temperature :  /fuxa_write.php?cmd=temp&value=24
|   Ventilation :  /fuxa_write.php?cmd=fan&value=2
|
| Optionnel : forcer une UI directement :
|   /fuxa_write.php?ui=2&cmd=temp&value=23
|
| Si ui n'est pas fourni, le fichier utilise l'UI selectionnee par :
|   /fuxa_select.php?ui=X
|--------------------------------------------------------------------------
*/

$stateFile = __DIR__ . '/fuxa_selected_ui.json';
$hubWriteUrl = getenv('HUB_WRITE_URL') ?: 'http://10.0.0.39:8500/write';

function json_response($data, $httpCode = 200)
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_selected_ui($stateFile)
{
    if (!file_exists($stateFile)) {
        return 1;
    }

    $raw = @file_get_contents($stateFile);
    if ($raw === false || trim($raw) === '') {
        return 1;
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['selected_ui'])) {
        return 1;
    }

    $ui = (int)$json['selected_ui'];
    return $ui > 0 ? $ui : 1;
}

function normalize_power_value($value)
{
    $v = strtolower(trim((string)$value));

    if (in_array($v, ['1', 'on', 'true', 'marche', 'start'], true)) {
        return 1;
    }

    if (in_array($v, ['0', 'off', 'false', 'arret', 'arrêt', 'stop'], true)) {
        return 0;
    }

    return null;
}

function call_modbus_hub_write($hubWriteUrl, $ip, $port, $slaveId, $address, $value)
{
    /*
     * Ton hub accepte d'apres les tests :
     *   GET /write?ip=...&address=...&value=...
     *
     * On garde aussi un essai complet avec port/slave_id au cas ou.
     */
    $attempts = [
        [
            'method' => 'GET',
            'url' => $hubWriteUrl . '?' . http_build_query([
                'ip' => $ip,
                'address' => $address,
                'value' => $value
            ]),
            'body' => null
        ],
        [
            'method' => 'GET',
            'url' => $hubWriteUrl . '?' . http_build_query([
                'ip' => $ip,
                'port' => $port,
                'slave_id' => $slaveId,
                'address' => $address,
                'value' => $value
            ]),
            'body' => null
        ]
    ];

    $history = [];

    foreach ($attempts as $attempt) {
        $context = stream_context_create([
            'http' => [
                'method' => $attempt['method'],
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);

        $raw = @file_get_contents($attempt['url'], false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';

        $history[] = [
            'method' => $attempt['method'],
            'url' => $attempt['url'],
            'status' => $statusLine,
            'response' => $raw
        ];

        if (
            strpos($statusLine, '200') !== false ||
            strpos($statusLine, '201') !== false ||
            strpos($statusLine, '204') !== false
        ) {
            return [true, $attempt['method'], $attempt['url'], $statusLine, $raw, $history];
        }
    }

    $last = end($history);
    return [false, $last['method'] ?? null, $last['url'] ?? null, $last['status'] ?? null, $last['response'] ?? null, $history];
}

/*
|--------------------------------------------------------------------------
| PARAMETRES
|--------------------------------------------------------------------------
*/

$ui = isset($_GET['ui']) ? (int)$_GET['ui'] : read_selected_ui($stateFile);
$cmd = strtolower(trim($_GET['cmd'] ?? ''));
$value = $_GET['value'] ?? null;

if ($ui <= 0) {
    json_response([
        'ok' => false,
        'error' => 'UI invalide.',
        'example' => 'fuxa_write.php?ui=2&cmd=power&value=1'
    ], 400);
}

if ($cmd === '' || $value === null || $value === '') {
    json_response([
        'ok' => false,
        'error' => 'Parametres requis manquants : cmd et value.',
        'examples' => [
            'fuxa_write.php?cmd=power&value=1',
            'fuxa_write.php?cmd=power&value=0',
            'fuxa_write.php?cmd=temp&value=24',
            'fuxa_write.php?cmd=fan&value=2',
            'fuxa_write.php?cmd=mode&value=1'
        ]
    ], 400);
}

/*
|--------------------------------------------------------------------------
| EQUIPEMENT
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare('SELECT * FROM equipments WHERE UI = ? LIMIT 1');
$stmt->execute([$ui]);
$equipment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$equipment) {
    json_response([
        'ok' => false,
        'ui' => $ui,
        'error' => 'Aucun equipement trouve pour cette UI.'
    ], 404);
}

$ip = trim($equipment['ip'] ?? '');
$port = (int)($equipment['port'] ?? 502);
$slaveId = (int)($equipment['slave_id'] ?? 1);
$name = $equipment['name'] ?? '';
$base = 25 * ($ui - 1);

if ($ip === '') {
    json_response([
        'ok' => false,
        'ui' => $ui,
        'name' => $name,
        'error' => 'IP equipement vide.'
    ], 400);
}

/*
|--------------------------------------------------------------------------
| MAPPING COMMANDES GREE
|--------------------------------------------------------------------------
| UI n : base = 25 * (UI - 1)
|
| Marche / Arret       : 102 + base ; ON=0xAA=170 ; OFF=0x55=85
| Mode                 : 103 + base
| Consigne temperature : 104 + base ; valeur en dixiemes de degre
| Ventilation          : 105 + base
|--------------------------------------------------------------------------
*/

$address = null;
$writeValue = null;

switch ($cmd) {
    case 'power':
    case 'onoff':
    case 'marche_arret':
    case 'marche':
    case 'arret':
    case 'arrêt':
        $power = normalize_power_value($value);
        if ($power === null) {
            json_response([
                'ok' => false,
                'error' => 'Valeur power invalide. Utiliser 1/on/marche ou 0/off/arret.',
                'received_value' => $value
            ], 400);
        }
        $address = 102 + $base;
        $writeValue = ($power === 1) ? 0xAA : 0x55;
        break;

    case 'mode':
        if (!is_numeric($value)) {
            json_response([
                'ok' => false,
                'error' => 'Valeur mode invalide. Utiliser une valeur numerique.',
                'received_value' => $value
            ], 400);
        }
        $address = 103 + $base;
        $writeValue = (int)$value;
        break;

    case 'temp':
    case 'temperature':
    case 'consigne_temperature':
        if (!is_numeric($value)) {
            json_response([
                'ok' => false,
                'error' => 'Valeur temperature invalide. Exemple : value=24',
                'received_value' => $value
            ], 400);
        }

        $temp = (float)$value;
        if ($temp < 16 || $temp > 30) {
            json_response([
                'ok' => false,
                'error' => 'Temperature hors plage. Plage acceptee : 16 a 30 degC.',
                'received_value' => $value
            ], 400);
        }

        $address = 104 + $base;
        $writeValue = (int)round($temp * 10);
        break;

    case 'fan':
    case 'ventilation':
    case 'consigne_ventilation':
        if (!is_numeric($value)) {
            json_response([
                'ok' => false,
                'error' => 'Valeur ventilation invalide. Utiliser 0, 1, 2 ou 3.',
                'received_value' => $value
            ], 400);
        }

        $fan = (int)$value;
        if ($fan < 0 || $fan > 3) {
            json_response([
                'ok' => false,
                'error' => 'Ventilation hors plage. Valeurs acceptees : 0, 1, 2, 3.',
                'received_value' => $value
            ], 400);
        }

        $address = 105 + $base;
        $writeValue = $fan;
        break;

    default:
        json_response([
            'ok' => false,
            'error' => 'Commande inconnue.',
            'cmd' => $cmd,
            'allowed_cmd' => ['power', 'mode', 'temp', 'fan']
        ], 400);
}

/*
|--------------------------------------------------------------------------
| ECRITURE MODBUS HUB
|--------------------------------------------------------------------------
*/

list($ok, $usedMethod, $usedUrl, $statusLine, $rawResponse, $history) = call_modbus_hub_write(
    $hubWriteUrl,
    $ip,
    $port,
    $slaveId,
    $address,
    $writeValue
);

if (!$ok) {
    json_response([
        'ok' => false,
        'error' => 'Echec ecriture Modbus Hub.',
        'ui' => $ui,
        'name' => $name,
        'ip' => $ip,
        'port' => $port,
        'slave_id' => $slaveId,
        'cmd' => $cmd,
        'requested_value' => $value,
        'written_value' => $writeValue,
        'address' => $address,
        'hub_write_url' => $hubWriteUrl,
        'last_status' => $statusLine,
        'last_response' => $rawResponse,
        'attempts' => $history
    ], 502);
}

json_response([
    'ok' => true,
    'ui' => $ui,
    'name' => $name,
    'ip' => $ip,
    'port' => $port,
    'slave_id' => $slaveId,
    'cmd' => $cmd,
    'requested_value' => $value,
    'written_value' => $writeValue,
    'address' => $address,
    'hub_method' => $usedMethod,
    'hub_status' => $statusLine,
    'hub_url' => $usedUrl,
    'hub_response' => $rawResponse
]);
