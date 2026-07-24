<?php
require 'auth.php';
require_login();
require 'config/db.php';
require 'lib/audit.php';
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)$_POST['id'];

    $stmt = $pdo->prepare("DELETE FROM schedules WHERE id = ?");
    $stmt->execute([$id]);
    audit(
            'DELETE_PLANNING',
            "Planning supprimé -  " . $_POST['id']);
    header("Location: schedules.php");
    exit;
}