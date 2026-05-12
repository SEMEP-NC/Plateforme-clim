<?php
require 'config/db.php';

$name = $_POST['name'];
$ip = $_POST['ip'];
$slave_id = $_POST['slave_id'];

$stmt = $pdo->prepare(
    "INSERT INTO equipments(name, ip, slave_id)
     VALUES (?, ?, ?)"
);

$stmt->execute([
    $name,
    $ip,
    $slave_id
]);

header('Location: equipments.php');