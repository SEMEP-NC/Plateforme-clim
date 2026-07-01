<?php
require '../config/db.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);

$db = get_db();

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

$url = "http://modbus-hub:8500/read?" . http_build_query([
    "ip" => $eq['ip'],
    "port" => $port,
    "device_id" => $eq['slave_id'],
    "type" => "register",
    "address" => $address,
    "count" => 4
]);

// IMPORTANT : gestion d'erreur propre
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