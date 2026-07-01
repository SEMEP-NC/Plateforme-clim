<?php
require 'auth.php';
session_start();
require 'config/db.php';
$pdo = get_db();

$id = (int)$_POST['id'];

$stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ?");
$stmt->execute([$id]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$s) exit;

$stmt = $pdo->prepare("
    INSERT INTO schedules
        (equipment_id, group_id, action, temperature, execution_time, repeat_days, executed, enabled)
    VALUES
        (?, ?, ?, ?, ?, ?, 0, 1)
");

$stmt->execute([
    $s['equipment_id'],
    $s['group_id'],
    $s['action'],
    $s['temperature'],
    $s['execution_time'],
    $s['repeat_days']
]);

header("Location: schedules.php");