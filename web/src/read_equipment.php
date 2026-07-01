<?php

require 'config/db.php';

$db = get_db();

$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
SELECT
    ip,
    slave_id,
    UI
FROM equipments
WHERE id=?
");

$stmt->execute([$id]);

$equipment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$equipment) {
    http_response_code(404);
    exit(json_encode([
        "success"=>false,
        "error"=>"Equipment not found"
    ]));
}

$base = 102 + (25 * ($equipment["UI"] - 1));

$payload = [
    "ip"=>$equipment["ip"],
    "port"=>502,
    "device_id"=>(int)$equipment["slave_id"],
    "address"=>$base,
    "count"=>4,
    "type"=>"register"
];

$ch = curl_init("http://modbus-hub:8500/read");

curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>[
        "Content-Type: application/json"
    ],
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POSTFIELDS=>json_encode($payload)
]);

$response = curl_exec($ch);

curl_close($ch);

$data = json_decode($response,true);

if (!$data["success"]) {

    exit(json_encode($data));

}

$r = $data["registers"];

echo json_encode([

    "success"=>true,

    "power"=>$r[0],

    "mode"=>$r[1],

    "setpoint"=>$r[2]/10,

    "fan"=>$r[3]

]);