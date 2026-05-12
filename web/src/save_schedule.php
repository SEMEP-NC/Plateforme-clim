<?php
require 'config/db.php';

$equipment_id = $_POST['equipment_id'];
$action = $_POST['action'];
$temperature = $_POST['temperature'];
$execution_time = $_POST['execution_time'];

$stmt = $pdo->prepare(
    "INSERT INTO schedules(
        equipment_id,
        action,
        temperature,
        execution_time
    ) VALUES (?, ?, ?, ?)"
);

$stmt->execute([
    $equipment_id,
    $action,
    $temperature,
    $execution_time
]);

header('Location: schedules.php');