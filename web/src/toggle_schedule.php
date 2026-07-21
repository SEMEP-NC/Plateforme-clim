<?php

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
        'DISABLE_PLANNING',
        "Planning désactivé -  " . $_POST['id']);
header("Location: schedules.php");