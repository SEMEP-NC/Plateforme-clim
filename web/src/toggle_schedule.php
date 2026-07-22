<?php
require 'auth.php';
session_start();
require 'config/db.php';
require 'lib/audit.php';
$pdo = get_db();

$id = (int)$_POST['id'];

$pdo->prepare("
    UPDATE schedules
    SET enabled = NOT enabled
    WHERE id = ?
")->execute([$id]);
audit(
        'TOOGLE_PLANNING',
        "Toogle planning modifié -  " . $_POST['id']);
header("Location: schedules.php");