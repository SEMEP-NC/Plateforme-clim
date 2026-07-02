<?php

require 'config/db.php';
$pdo = get_db();

$id = (int)$_POST['id'];

$pdo->prepare("
    UPDATE schedules
    SET enabled = NOT enabled
    WHERE id = ?
")->execute([$id]);

header("Location: schedules.php");