<?php
require 'config/db.php';

$db = get_db();

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
| Cette API PHP est appelée par FUXA.
| Elle lit les registres via le Modbus Hub.
*/
$hubBaseUrl = getenv('HUB_BASE_URL') ?: 'http://10.0.0.39:8500/read';

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

function read_hub_value($hubBaseUrl, $ip, $address) {
    if ($ip === '' || $address === null) {
        return null;
    }

    $url = $hubBaseUrl . '?' . http_build_query([
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

        foreach ($json as $v) {
            if (is_scalar($v)) {
                return $v;
            }
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/
$equipments = $db->query("SELECT * FROM equipments ORDER BY UI ASC, name ASC")
    ->fetchAll(PDO::FETCH_ASSOC);

$result = [];

foreach ($equipments as $equipment) {
    $ui = (int)$equipment['UI'];
    $base = 25 * ($ui - 1);
    $ip = trim($equipment['ip'] ?? '');
    $name = trim($equipment['name'] ?? ('UI-' . $ui));
    $prefix = 'ui' . $ui . '_' . clean_id($name);

    $powerRaw = $equipment['power'] ?? null;
    $powerKw = is_numeric($powerRaw) ? ((float)$powerRaw / 10) : null;

    $onOff = read_hub_value($hubBaseUrl, $ip, 102 + $base);
    $mode = read_hub_value($hubBaseUrl, $ip, 103 + $base);
    $tempSetRaw = read_hub_value($hubBaseUrl, $ip, 104 + $base);
    $fan = read_hub_value($hubBaseUrl, $ip, 105 + $base);
    $roomTempRaw = read_hub_value($hubBaseUrl, $ip, 116 + $base);
    $fault = read_hub_value($hubBaseUrl, $ip, 319 + (64 * ($ui - 1)));

    $result[$prefix . '_marche_arret'] = is_numeric($onOff) ? (float)$onOff : $onOff;
    $result[$prefix . '_mode'] = is_numeric($mode) ? (float)$mode : $mode;
    $result[$prefix . '_consigne_temperature'] = is_numeric($tempSetRaw) ? ((float)$tempSetRaw / 10) : $tempSetRaw;
    $result[$prefix . '_consigne_ventilation'] = is_numeric($fan) ? (float)$fan : $fan;
    $result[$prefix . '_temperature_ambiante'] = is_numeric($roomTempRaw) ? ((float)$roomTempRaw / 10) : $roomTempRaw;
    $result[$prefix . '_puissance'] = $powerKw;
    $result[$prefix . '_defaut_general'] = is_numeric($fault) ? (float)$fault : $fault;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
