<?php

require 'config/db.php';

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $equipment_id = (int)$_POST['equipment_id'];

    $action = ($_POST['action'] === '' || !isset($_POST['action']))
        ? null
        : $_POST['action'];

    $temperature = ($_POST['temperature'] === '' || !isset($_POST['temperature']))
        ? null
        : (int)$_POST['temperature'];

    $execution_time = $_POST['execution_time'];

    // conversion +11 -> UTC
    $dt = new DateTime($execution_time, new DateTimeZone('+11:00'));
    $dt->setTimezone(new DateTimeZone('UTC'));
    $execution_time_utc = $dt->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO schedules
        (
            equipment_id,
            action,
            temperature,
            execution_time,
            executed
        )
        VALUES
        (
            :equipment_id,
            :action,
            :temperature,
            :execution_time,
            0
        )
    ");

    // equipment_id
    $stmt->bindValue(':equipment_id', $equipment_id, PDO::PARAM_INT);

    // action (NULL safe)
    if ($action === null) {
        $stmt->bindValue(':action', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
    }

    // temperature (NULL safe)
    if ($temperature === null) {
        $stmt->bindValue(':temperature', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':temperature', $temperature, PDO::PARAM_INT);
    }

    // execution time
    $stmt->bindValue(':execution_time', $execution_time_utc, PDO::PARAM_STR);

    $stmt->execute();

    header('Location: schedules.php');
    exit;
}