<?php
require '../auth.php';
require_login();
require '../config/db.php';
require '../lib/registers.php';

function log_modbus($msg, $data = null) {
    $line = "[MODBUS PROXY] " . $msg;

    if ($data !== null) {
        $line .= " " . json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    error_log($line);
}

function hub_get($params, $timeout = 3) {
    $url = "http://modbus-hub:8500/read?" . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ["success" => false, "error" => $error];
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : ["success" => false, "error" => "Invalid JSON"];
}

function hub_post($path, $body, $timeout = 5) {
    $ch = curl_init("http://modbus-hub:8500/" . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ["success" => false, "error" => $error];
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : ["success" => false, "error" => "Invalid JSON"];
}

header('Content-Type: application/json');

$db = get_db();

$action = $_GET['action'] ?? 'read';
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT ip, port, slave_id, UI, type FROM equipments WHERE id = ?");
$stmt->execute([$id]);
$eq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eq) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Equipment not found"]);
    exit;
}

$port = $eq['port'] ?: 502;
$ui = (int)$eq['UI'];
$type = reg_type($eq['type'] ?? 'GMV');

$cmdAddresses = reg_command_addresses($type, $ui);       // [power,mode,setpoint,fan,min_setpoint]
$shieldOrder = reg_shield_order($type);                  // [energy,setpoint,mode,power,lock]
$shieldAddresses = reg_shield_addresses($type, $ui);      // adresses dans le même ordre

/* =========================
   READ
========================= */
if ($action === 'read') {

    // ---- Registres de commande ----
    $cmdList = array_values($cmdAddresses);
    $cmdStart = min($cmdList);
    $cmdCount = max($cmdList) - $cmdStart + 1;

    $regResponse = hub_get([
        "ip" => $eq['ip'],
        "port" => $port,
        "device_id" => $eq['slave_id'],
        "type" => "register",
        "address" => $cmdStart,
        "count" => $cmdCount,
    ]);

    if (!($regResponse['success'] ?? false)) {
        echo json_encode(["success" => false, "error" => $regResponse['error'] ?? "Erreur lecture registres"]);
        exit;
    }

    $regBlock = $regResponse['registers'] ?? [];
    $registers = [];
    foreach ($cmdAddresses as $key => $addr) {
        $registers[] = $regBlock[$addr - $cmdStart] ?? null;
    }

    // ---- Coils shield ----
    $shieldStart = min($shieldAddresses);
    $shieldCount = max($shieldAddresses) - $shieldStart + 1;

    $coilResponse = hub_get([
        "ip" => $eq['ip'],
        "port" => $port,
        "device_id" => $eq['slave_id'],
        "type" => "coils",
        "address" => $shieldStart,
        "count" => $shieldCount,
    ]);

    if (!($coilResponse['success'] ?? false)) {
        echo json_encode(["success" => false, "error" => $coilResponse['error'] ?? "Erreur lecture coils"]);
        exit;
    }

    $coilBlock = $coilResponse['bits'] ?? [];
    $coils = [];
    foreach ($shieldAddresses as $addr) {
        $coils[] = $coilBlock[$addr - $shieldStart] ?? false;
    }

    echo json_encode([
        "success" => true,
        "type" => $type,
        "registers" => $registers,
        "coils" => $coils,
    ]);

    exit;
}

/* =========================
   WRITE
========================= */
if ($action === 'write') {

    $payload = json_decode(file_get_contents("php://input"), true);

    if (!$payload || !isset($payload['registers'])) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid JSON or missing registers"
        ]);
        exit;
    }

    $registers = $payload['registers'] ?? [];
    $shields = $payload["shields"] ?? [];

    $registers = array_map(function($v) {
        if (!is_numeric($v)) return 0;
        return (int)$v;
    }, $registers);

    while (count($registers) < 5) {
        $registers[] = 0;
    }

    $shields = array_map(function($v) {
        return $v ? true : false;
    }, $shields);

    while (count($shields) < 5) {
        $shields[] = false;
    }

    if (!is_array($registers) || count($registers) !== 5) {
        echo json_encode([
            "success" => false,
            "error" => "Registers must be an array of 5 values"
        ]);
        exit;
    }

    // Écriture registre par registre : les adresses ne sont pas forcément
    // contiguës (cas U-Match), une écriture par bloc écraserait des
    // registres non concernés.
    $cmdKeys = array_keys($cmdAddresses); // ['power','mode','setpoint','fan','min_setpoint']

    $success = true;
    $errors = [];

    foreach ($cmdKeys as $i => $key) {
        $addr = $cmdAddresses[$key];

        $result = hub_post("write", [
            "ip" => $eq['ip'],
            "port" => $port,
            "slave" => $eq['slave_id'],
            "type" => "register",
            "address" => $addr,
            "value" => $registers[$i],
        ]);

        if (!($result['success'] ?? false)) {
            $success = false;
            $errors[] = $result['error'] ?? "Erreur écriture registre $addr";
            log_modbus("WRITE REGISTER ERROR", ["address" => $addr, "result" => $result]);
        }
    }

    // Écriture des coils shield, un par un (même raison que ci-dessus).
    foreach ($shieldOrder as $i => $key) {
        $addr = reg_shield_address($type, $ui, $key);

        $result = hub_post("write", [
            "ip" => $eq['ip'],
            "port" => $port,
            "slave" => $eq['slave_id'],
            "type" => "coil",
            "address" => $addr,
            "value" => (bool)$shields[$i],
        ]);

        if (!($result['success'] ?? false)) {
            $success = false;
            $errors[] = $result['error'] ?? "Erreur écriture coil $addr";
            log_modbus("WRITE COIL ERROR", ["address" => $addr, "result" => $result]);
        }
    }

    echo json_encode([
        "success" => $success,
        "errors" => $errors,
    ]);

    exit;
}

echo json_encode(["success" => false, "error" => "Unknown action"]);
