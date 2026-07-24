<?php
require 'auth.php';
require_login();
require 'config/db.php';
require 'lib/audit.php';
$pdo = get_db();
verify_csrf();
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