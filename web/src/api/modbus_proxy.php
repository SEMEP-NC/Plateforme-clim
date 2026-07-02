<?php

require '../config/db.php';

function log_modbus($msg, $data = null) {
    $line = "[MODBUS PROXY] " . $msg;

    if ($data !== null) {
        $line .= " " . json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    error_log($line);
}

header('Content-Type: application/json');

$db = get_db();

$action = $_GET['action'] ?? 'read';
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT ip, port, slave_id, UI FROM equipments WHERE id = ?");
$stmt->execute([$id]);
$eq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eq) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Equipment not found"]);
    exit;
}

$port = $eq['port'] ?: 502;
$ui = (int)$eq['UI'];
$address = 102 + 25 * ($ui - 1);

/* =========================
   READ
========================= */
if ($action === 'read') {

    $url = "http://modbus-hub:8500/read?" . http_build_query([
        "ip" => $eq['ip'],
        "port" => $port,
        "device_id" => $eq['slave_id'],
        "type" => "register",
        "address" => $address,
        "count" => 5
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);

    $response = curl_exec($ch);

    if ($response === false) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "error" => curl_error($ch)
        ]);
        exit;
    }

    curl_close($ch);

    echo $response;
    exit;
}

/* =========================
   WRITE
========================= */
if ($action === 'write') {

    header('Content-Type: application/json');

    $payload = json_decode(file_get_contents("php://input"), true);



    if (!$payload || !isset($payload['registers'])) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid JSON or missing registers"
        ]);
        exit;
    }

    $registers = $payload['registers'] ?? [];


    $registers = array_map(function($v) {
        if (!is_numeric($v)) return 0;
        return (int)$v;
    }, $registers);

    // force taille fixe 4
    while (count($registers) < 5) {
        $registers[] = 0;
    }

    if (!is_array($registers) || count($registers) !== 5) {
        echo json_encode([
            "success" => false,
            "error" => "Registers must be an array of 5 values"
        ]);
        exit;
    }


    // URL modbus-hub
    $url = "http://modbus-hub:8500/write";

    $requestBody = [
        "ip" => $eq['ip'],
        "port" => $port,
        "device_id" => $eq['slave_id'],
        "type" => "register",
        "address" => $address,
        "count" => 5,
        "values" => array_map('intval', $registers)
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));

   

    $response = curl_exec($ch);

    if ($response === false) {

        log_modbus("CURL ERROR", [
            "error" => curl_error($ch)
        ]);

        echo json_encode([
            "success" => false,
            "error" => curl_error($ch)
        ]);

        curl_close($ch);
        exit;
    }

    curl_close($ch);

    // renvoyer brut modbus-hub
    echo $response;
    exit;
}