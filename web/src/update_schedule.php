<?php
require 'config/db.php';

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id = (int)($_POST['id'] ?? 0);

    $action = $_POST['action'] ?: null;
    $temperature = $_POST['temperature'] !== '' ? (int)$_POST['temperature'] : null;
    $execution_time = $_POST['execution_time'] ?? null;

    if (!$id || !$execution_time) {
        die("Invalid data");
    }

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

    header("Location: schedules.php");
    exit;
}