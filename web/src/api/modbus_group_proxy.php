<?php
require '../config/db.php';
$action = $_GET['action'] ?? null;
$db = get_db();

if ($action === 'write_group') {

    header('Content-Type: application/json');

    $payload = json_decode(file_get_contents("php://input"), true);

    if (!$payload || !isset($payload['group_id'])) {
        echo json_encode(["success" => false, "error" => "missing group_id"]);
        exit;
    }

    $groupId = (int)$payload['group_id'];

    // récupérer équipements du groupe
    $stmt = $db->prepare("
        SELECT e.ip, e.slave_id, e.id, e.UI
        FROM equipments e
        JOIN equipment_groups eg ON eg.equipment_id = e.id
        WHERE eg.group_id = ?
    ");

    $stmt->execute([$groupId]);
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$equipments) {
        echo json_encode(["success" => false, "error" => "empty group"]);
        exit;
    }

    $registers = $payload['registers'] ?? [];

    // normalisation tableau
    if (isset($registers[0])) {
        $regs = array_map(fn($v) => is_numeric($v) ? (int)$v : 0, $registers);
    } else {
        // mapping partiel → fallback 4 registers
        $regs = [0,0,0,0];

        if (isset($registers['power'])) $regs[0] = (int)$registers['power'];
        if (isset($registers['mode'])) $regs[1] = (int)$registers['mode'];
        if (isset($registers['setpoint'])) $regs[2] = (int)($registers['setpoint'] * 10);
        if (isset($registers['fan'])) $regs[3] = (int)$registers['fan'];
    }

    $url = "http://modbus-hub:8500/write";

    $results = [];

    foreach ($equipments as $eq) {
        $address = 102 + 25 * ((int)$eq['UI'] - 1);
        $requestBody = [
            "ip" => $eq['ip'],
            "port" => 502,
            "device_id" => $eq['slave_id'],
            "type" => "register",
            "address" => $address,
            "count" => 4,
            "values" => $regs,
            "UI" => $eq['UI']
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));

        $response = curl_exec($ch);
        curl_close($ch);

        $results[] = [
            "equipment_id" => $eq['id'],
            "response" => json_decode($response, true)
        ];
    }

    echo json_encode([
        "success" => true,
        "group_id" => $groupId,
        "results" => $results
    ]);

    exit;
}
?>