<?php
require '../auth.php';
require_login();
require '../config/db.php';
require '../lib/registers.php';

function group_hub_post($body, $timeout = 5) {
    $ch = curl_init("http://modbus-hub:8500/write");
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
        SELECT e.ip, e.port, e.slave_id, e.id, e.UI, e.type
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

    $hasShields = array_key_exists('shields', $payload);
    $shields = $hasShields ? $payload['shields'] : [];

    // normalisation tableau : [power, mode, setpoint, fan, min_setpoint]
    if (isset($registers[0])) {
        $regs = array_map(fn($v) => is_numeric($v) ? (int)$v : 0, $registers);
    } else {
        $regs = [0, 0, 0, 0, 0];

        if (isset($registers['power'])) $regs[0] = (int)$registers['power'];
        if (isset($registers['mode'])) $regs[1] = (int)$registers['mode'];
        if (isset($registers['setpoint'])) $regs[2] = (int)($registers['setpoint'] * 10);
        if (isset($registers['fan'])) $regs[3] = (int)$registers['fan'];
        if (isset($registers['min_setpoint'])) $regs[4] = (int)($registers['min_setpoint'] * 10);
    }

    while (count($regs) < 5) {
        $regs[] = 0;
    }

    if ($hasShields) {
        $shields = array_map(function($v) {
            return (bool)$v;
        }, $shields);

        while (count($shields) < 5) {
            $shields[] = false;
        }
    }

    $results = [];

    foreach ($equipments as $eq) {

        $type = reg_type($eq['type'] ?? 'GMV');
        $ui = (int)$eq['UI'];
        $port = $eq['port'] ?: 502;

        $cmdAddresses = reg_command_addresses($type, $ui);
        $cmdKeys = array_keys($cmdAddresses);

        $equipmentSuccess = true;
        $equipmentErrors = [];

        // Écriture registre par registre (adresses non contiguës pour U-Match).
        foreach ($cmdKeys as $i => $key) {
            $addr = $cmdAddresses[$key];

            $result = group_hub_post([
                "ip" => $eq['ip'],
                "port" => $port,
                "slave" => $eq['slave_id'],
                "type" => "register",
                "address" => $addr,
                "value" => $regs[$i],
                "UI" => $eq['UI'],
            ]);

            if (!($result['success'] ?? false)) {
                $equipmentSuccess = false;
                $equipmentErrors[] = $result['error'] ?? "Erreur écriture registre $addr";
            }
        }

        // Écriture des coils shield, un par un.
        if ($hasShields) {
            $shieldOrder = reg_shield_order($type);

            foreach ($shieldOrder as $i => $key) {
                $addr = reg_shield_address($type, $ui, $key);

                $result = group_hub_post([
                    "ip" => $eq['ip'],
                    "port" => $port,
                    "slave" => $eq['slave_id'],
                    "type" => "coil",
                    "address" => $addr,
                    "value" => (bool)$shields[$i],
                    "UI" => $eq['UI'],
                ]);

                if (!($result['success'] ?? false)) {
                    $equipmentSuccess = false;
                    $equipmentErrors[] = $result['error'] ?? "Erreur écriture coil $addr";
                }
            }
        }

        $results[] = [
            "equipment_id" => $eq['id'],
            "success" => $equipmentSuccess,
            "errors" => $equipmentErrors,
        ];
    }

    echo json_encode([
        "success" => true,
        "group_id" => $groupId,
        "results" => $results
    ]);

    exit;
}
