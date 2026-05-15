<?php

require 'config/db.php';

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $equipment_id = $_POST['equipment_id'];
    $action = $_POST['action'];
    $temperature = $_POST['temperature'];
    $execution_time = $_POST['execution_time'];

    $stmt = $pdo->prepare(
        "
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
        "
    );

    $stmt->execute([

        ':equipment_id' => $equipment_id,
        ':action' => $action,
        ':temperature' => $temperature,
        ':execution_time' => $execution_time

    ]);

    header('Location: schedules.php');

    exit;
}