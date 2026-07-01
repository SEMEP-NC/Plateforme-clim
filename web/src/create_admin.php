<?php
session_start();
require 'config/db.php';

$pdo = get_db();

$username = $_POST['username'] ?? 'admin';
$password = $_POST['password'] ?? '';
$password_confirm = $_POST['password_confirm'] ?? '';

if (!$username || !$password) {
    die("Invalid input");
}

if ($password !== $password_confirm) {
    die("Passwords do not match");
}

if (strlen($password) < 6) {
    die("Password too short");
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