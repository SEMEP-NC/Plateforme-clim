<?php
require 'config/db.php';
require 'auth.php';
require_admin();
$pdo = get_db();

$id = (int)($_POST['id'] ?? 0);
verify_csrf();
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

header("Location: admin.php");
exit;