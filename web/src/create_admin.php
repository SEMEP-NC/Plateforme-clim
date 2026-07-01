<?php
session_start();
require 'config/db.php';

$pdo = get_db();

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (!$username || !$password) {
    die("Invalid input");
}

// sécurité : empêcher création multiple admins via endpoint
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
if ($stmt->fetchColumn() > 0) {
    die("Admin already exists");
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("
    INSERT INTO users (username, password_hash, role)
    VALUES (?, ?, 'admin')
");

$stmt->execute([$username, $hash]);

header("Location: login.php");
exit;