<?php

require 'config/db.php';

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $equipment_id = (int)($_POST['equipment_id'] ?? 0);

    $action = empty($_POST['action'])
        ? null
        : $_POST['action'];

    if ($action !== null && !in_array($action, ['ON', 'OFF'], true)) {
        http_response_code(400);
        die('Action invalide.');
    }

    $temperature = ($_POST['temperature'] === '' || !isset($_POST['temperature']))
        ? null
        : (int)$_POST['temperature'];

    if ($temperature !== null && ($temperature < 16 || $temperature > 30)) {
        http_response_code(400);
        die('Température invalide.');
    }

    if ($action === null && $temperature === null) {
        http_response_code(400);
        die('Veuillez choisir une action ou une température.');
    }

    $execution_time = $_POST['execution_time'] ?? '';

    if ($equipment_id <= 0 || $execution_time === '') {
        http_response_code(400);
        die('Formulaire incomplet.');
    }

    $dt = new DateTime($execution_time, new DateTimeZone('+11:00'));
    $dt->setTimezone(new DateTimeZone('UTC'));
    $execution_time_utc = $dt->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO schedules
            (equipment_id, action, temperature, execution_time, executed)
        VALUES
            (:equipment_id, :action, :temperature, :execution_time, 0)
    ");

    $stmt->bindValue(':equipment_id', $equipment_id, PDO::PARAM_INT);

    if ($action === null) {
        $stmt->bindValue(':action', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
    }

    if ($temperature === null) {
        $stmt->bindValue(':temperature', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':temperature', $temperature, PDO::PARAM_INT);
    }

    $stmt->bindValue(':execution_time', $execution_time_utc, PDO::PARAM_STR);
    $stmt->execute();

    header('Location: schedules.php');
    exit;
}