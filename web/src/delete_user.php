<?php
require 'config/db.php';
session_start();

$pdo = get_db();

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    die("Invalid ID");
}

/*
|-----------------------------
| PROTECTION: ne pas supprimer soi-même
|-----------------------------
*/
if ($_SESSION['user']['id'] == $id) {
    die("Impossible de supprimer votre propre compte");
}

$stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
$stmt->execute([$id]);

header("Location: users.php");
exit;