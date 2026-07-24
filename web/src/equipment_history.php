<?php
require 'auth.php';
require_login();

require "config/db.php";

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);

try {
    $conn = get_db();

    $stmt = $conn->prepare("
        SELECT created_at, setpoint, return_temp, outside_temp, state, fault
        FROM equipment_history
        WHERE equipment_id = ?
        ORDER BY created_at DESC
        LIMIT 1000
    ");

    $stmt->execute([$id]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($rows);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "error" => $e->getMessage()
    ]);
}