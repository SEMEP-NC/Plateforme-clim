<?php
require 'config/db.php';
require 'auth.php';

require_admin();

$pdo = get_db();

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$role = $_POST['role'] ?? 'user';

if ($username === '' || $password === '') {
    die("Invalid input");
}

if (!in_array($role, ['user', 'admin'])) {
    die("Invalid role");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (username, password_hash, role)
    VALUES (?, ?, ?)
");

$stmt->execute([$username, $hash, $role]);

header("Location: users.php");
exit;