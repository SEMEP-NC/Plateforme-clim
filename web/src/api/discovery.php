<?php
require '../config/db.php';

header('Content-Type: application/json');

$pdo = get_db();

$units = $pdo->query("SELECT * FROM discovered_units ORDER BY last_seen DESC")->fetchAll();

echo json_encode($units);
