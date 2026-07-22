<?php
require 'auth.php';
session_start();
require 'config/db.php';
require 'lib/audit.php';
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $equipment_id = !empty($_POST['equipment_id'])
        ? (int)$_POST['equipment_id']
        : null;

    $group_id = !empty($_POST['group_id'])
        ? (int)$_POST['group_id']
        : null;

    if (($equipment_id === null && $group_id === null) ||
        ($equipment_id !== null && $group_id !== null)) {
        http_response_code(400);
        die('Veuillez choisir un équipement OU un groupe (un seul).');
    }
    $action = empty($_POST['action'])
        ? null
        : $_POST['action'];

    if ($action !== null && !in_array($action, ['ON', 'OFF'], true)) {
        http_response_code(400);
        die('Action invalide.');
    }

    $temperature = ($_POST['temperature'] === '' || !isset($_POST['temperature']))
        ? null
        : (int)$_POST['temperature'];

    if ($temperature !== null && ($temperature < 16 || $temperature > 30)) {
        http_response_code(400);
        die('Température invalide.');
    }

    if ($action === null && $temperature === null) {
        http_response_code(400);
        die('Veuillez choisir une action ou une température.');
    }

    $execution_time = $_POST['execution_time'] ?? '';

    if ($execution_time === '') {
        http_response_code(400);
        die('Formulaire incomplet.');
    }

    // obligation : soit equipment soit group
    if (($equipment_id === null || $equipment_id <= 0) && ($group_id === null || $group_id <= 0)) {
        http_response_code(400);
        die('Veuillez sélectionner un équipement ou un groupe.');
    }

    $dt = new DateTime($execution_time, new DateTimeZone('+11:00'));
    $dt->setTimezone(new DateTimeZone('UTC'));
    $execution_time_utc = $dt->format('Y-m-d H:i:s');

    $repeat_days_raw = $_POST['repeat_days'] ?? [];
    $repeat_days = [];

    if (is_array($repeat_days_raw)) {
        foreach ($repeat_days_raw as $day) {
            $day = (int)$day;
            if ($day >= 1 && $day <= 7) {
                $repeat_days[] = $day;
            }
        }
    }

    $repeat_days = array_values(array_unique($repeat_days));
    sort($repeat_days);
    $repeat_days_value = count($repeat_days) > 0 ? implode(',', $repeat_days) : null;

    $stmt = $pdo->prepare("
        INSERT INTO schedules
            (equipment_id, group_id, action, temperature, execution_time, repeat_days, executed)
        VALUES
            (:equipment_id, :group_id, :action, :temperature, :execution_time, :repeat_days, 0)
    ");

    if ($equipment_id === null) {
        $stmt->bindValue(':equipment_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':equipment_id', $equipment_id, PDO::PARAM_INT);
    }

    if ($group_id === null) {
        $stmt->bindValue(':group_id', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':group_id', $group_id, PDO::PARAM_INT);
    }

    if ($action === null) {
        $stmt->bindValue(':action', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
    }

    if ($temperature === null) {
        $stmt->bindValue(':temperature', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':temperature', $temperature, PDO::PARAM_INT);
    }

    $stmt->bindValue(':execution_time', $execution_time_utc, PDO::PARAM_STR);
    
    if ($repeat_days_value === null) {
        $stmt->bindValue(':repeat_days', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':repeat_days', $repeat_days_value, PDO::PARAM_STR);
    }

    $stmt->execute();
    audit(
            'ADD_PLANNING',
            "Planning ajouté sur equipment -  " . $_POST['equipment_id']);
    header('Location: schedules.php');
    exit;
}