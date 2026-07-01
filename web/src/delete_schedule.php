<?php
require 'auth.php';
session_start();
require 'config/db.php';

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id = (int)$_POST['id'];

    $stmt = $pdo->prepare("DELETE FROM schedules WHERE id = ?");
    $stmt->execute([$id]);

    header("Location: schedules.php");
    exit;
}