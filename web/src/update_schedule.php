<?php
require 'config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = get_db();

    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?: null;

    $temperature = ($_POST['temperature'] === '' || !isset($_POST['temperature']))
        ? null
        : (int)$_POST['temperature'];

    $execution_time = $_POST['execution_time'] ?? null;

    if (!$id || !$execution_time) {
        throw new Exception("Invalid data");
    }

    /**
     * IMPORTANT :
     * datetime-local = heure locale SANS timezone
     * donc on considère que c'est UTC+11 côté UI
     */
    $dt = new DateTime($execution_time, new DateTimeZone('+11:00'));
    $dt->setTimezone(new DateTimeZone('UTC'));

    $repeat_days = $_POST['repeat_days'] ?? [];
    $repeat_days = array_values(array_filter($repeat_days, fn($d) => $d >= 1 && $d <= 7));
    $repeat_days_str = $repeat_days ? implode(',', $repeat_days) : null;

    $stmt = $pdo->prepare("
        UPDATE schedules
        SET action = :action,
            temperature = :temperature,
            execution_time = :execution_time,
            repeat_days = :repeat_days
        WHERE id = :id
    ");

    $stmt->execute([
        ':id' => $id,
        ':action' => $action,
        ':temperature' => $temperature,
        ':execution_time' => $dt->format('Y-m-d H:i:s'),
        ':repeat_days' => $repeat_days_str
    ]);

    echo json_encode([
        "success" => true,
        "id" => $id
    ]);
    exit;

} catch (Throwable $e) {

    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
    exit;
}