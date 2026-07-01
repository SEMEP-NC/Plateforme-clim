<?php
require 'config/db.php';

$db = get_db();

$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT ip, port, slave_id, UI FROM equipments WHERE id = ?");
$stmt->execute([$id]);
$eq = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eq) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Equipment not found"]);
    exit;
}

// fallback port
$port = $eq['port'] ?: 502;

// calcul adresse selon UI
$ui = (int)$eq['UI'];
$address = 102 + 25 * ($ui - 1);

// appel modbus-hub (réseau docker)
$url = "http://modbus-hub:8500/read?" . http_build_query([
    "ip" => $eq['ip'],
    "port" => $port,
    "device_id" => $eq['slave_id'],
    "type" => "register",
    "address" => $address,
    "count" => 4
]);

$response = file_get_contents($url);

header('Content-Type: application/json');
echo $response;