<?php
require 'config/db.php';

$db = get_db();

/*
|--------------------------------------------------------------------------
| fuxa_selected_read.php
|--------------------------------------------------------------------------
| Retourne les valeurs de la clim actuellement sélectionnée.
| URL FUXA : http://10.0.0.39:8085/fuxa_selected_read.php
*/

$stateFile = __DIR__ . '/fuxa_selected_ui.json';
$hubReadUrl = getenv('HUB_READ_URL') ?: 'http://10.0.0.39:8500/read';

function read_state_ui($stateFile) {
    if (!file_exists($stateFile)) {
        return 1;
    }

    $raw = file_get_contents($stateFile);
    $json = json_decode($raw, true);

    if (!is_array($json) || empty($json['selected_ui'])) {
        return 1;
    }

    return (int)$json['selected_ui'];
}

function read_hub_value($hubReadUrl, $ip, $address) {
    if ($ip === '' || $address === null) {
        return null;
    }

    $url = $hubReadUrl . '?' . http_build_query([
        'ip' => $ip,
        'address' => $address
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 3,
            'ignore_errors' => true
        ]
    ]);

    $raw = @file_get_contents($url, false, $context);

    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $json = json_decode($raw, true);

    if ($json === null) {
        $raw = trim($raw);
        return is_numeric($raw) ? (float)$raw : null;
    }

    if (is_array($json)) {
        foreach (['value', 'result', 'data', 'read', 'response'] as $key) {
            if (isset($json[$key]) && is_scalar($json[$key])) {
                return $json[$key];
            }
        }

        foreach (['values', 'registers'] as $key) {
            if (isset($json[$key]) && is_array($json[$key]) && isset($json[$key][0])) {
                return $json[$key][0];
            }
        }

        foreach ($json as $value) {
            if (is_scalar($value)) {
                return $value;
            }
        }
    }

    return null;
}

$selectedUi = read_state_ui($stateFile);

$stmt = $db->prepare("SELECT * FROM equipments WHERE UI = ? LIMIT 1");
$stmt->execute([$selectedUi]);
$equipment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$equipment) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'selected_ui' => $selectedUi,
        'error' => 'Aucun equipement trouve pour cette UI'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$ui = (int)$equipment['UI'];
$base = 25 * ($ui - 1);
$ip = trim($equipment['ip'] ?? '');
$name = trim($equipment['name'] ?? ('UI-' . $ui));
$powerRaw = $equipment['power'] ?? null;
$powerKw = is_numeric($powerRaw) ? ((float)$powerRaw / 10) : null;

$onOff = read_hub_value($hubReadUrl, $ip, 102 + $base);
$mode = read_hub_value($hubReadUrl, $ip, 103 + $base);
$tempSetRaw = read_hub_value($hubReadUrl, $ip, 104 + $base);
$fan = read_hub_value($hubReadUrl, $ip, 105 + $base);
$roomTempRaw = read_hub_value($hubReadUrl, $ip, 116 + $base);
$fault = read_hub_value($hubReadUrl, $ip, 319 + (64 * ($ui - 1)));

$result = [
    'ok' => true,
    'selected_ui' => $ui,
    'selected_name' => $name,
    'selected_ip' => $ip,
    'selected_power_kw' => $powerKw,

    'marche_arret' => is_numeric($onOff) ? (float)$onOff : $onOff,
    'mode' => is_numeric($mode) ? (float)$mode : $mode,
    'consigne_temperature' => is_numeric($tempSetRaw) ? ((float)$tempSetRaw / 10) : $tempSetRaw,
    'consigne_ventilation' => is_numeric($fan) ? (float)$fan : $fan,
    'temperature_ambiante' => is_numeric($roomTempRaw) ? ((float)$roomTempRaw / 10) : $roomTempRaw,
    'defaut_general' => is_numeric($fault) ? (float)$fault : $fault
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
