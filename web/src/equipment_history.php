<?php
/*
|--------------------------------------------------------------------------
| EQUIPMENT_HISTORY.PHP
|--------------------------------------------------------------------------
| Fournit l'historique d'un équipement pour le graphique du modal
| "Historique".
|
| Paramètres GET :
|   id          -> id équipement (obligatoire)
|   date_start  -> début période YYYY-MM-DD
|   date_end    -> fin période YYYY-MM-DD
|
| Gestion volume :
|   - <= 30 jours : données complètes
|   - > 30 jours  : 1 point sur 2
|
| La base est purgée au-delà de 90 jours.
|--------------------------------------------------------------------------
*/

require 'auth.php';
require_login();

require "config/db.php";

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        "error" => "Equipement invalide"
    ]);
    exit;
}


$dateStart = trim($_GET['date_start'] ?? '');
$dateEnd   = trim($_GET['date_end'] ?? '');


try {

    $conn = get_db();


    /*
    |--------------------------------------------------------------------------
    | Gestion dates
    |--------------------------------------------------------------------------
    */

    $end = ($dateEnd !== '')
        ? new DateTime($dateEnd . ' 23:59:59')
        : new DateTime();


    $start = ($dateStart !== '')
        ? new DateTime($dateStart . ' 00:00:00')
        : (clone $end)->modify('-1 day');


    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }


    $rangeDays = (
        $end->getTimestamp() - $start->getTimestamp()
    ) / 86400;


    /*
    |--------------------------------------------------------------------------
    | Lecture historique brut
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
            created_at,
            setpoint,
            return_temp,
            outside_temp,
            state,
            fault
        FROM equipment_history
        WHERE equipment_id = ?
          AND created_at BETWEEN ? AND ?
        ORDER BY created_at ASC
        LIMIT 100000
    ");


    $stmt->execute([
        $id,
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s')
    ]);


    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);



    /*
    |--------------------------------------------------------------------------
    | Réduction au-delà de 30 jours
    |--------------------------------------------------------------------------
    */

    $sampled = false;


    if ($rangeDays > 30) {

        $sampled = true;

        $rows = array_values(
            array_filter(
                $rows,
                function ($value, $key) {
                    return ($key % 2) === 0;
                },
                ARRAY_FILTER_USE_BOTH
            )
        );
    }



    /*
    |--------------------------------------------------------------------------
    | Conversion des types JSON
    |--------------------------------------------------------------------------
    */

    foreach ($rows as &$row) {

        $row['setpoint'] =
            $row['setpoint'] !== null
                ? (float)$row['setpoint']
                : null;

        $row['return_temp'] =
            $row['return_temp'] !== null
                ? (float)$row['return_temp']
                : null;

        $row['outside_temp'] =
            $row['outside_temp'] !== null
                ? (float)$row['outside_temp']
                : null;

        $row['state'] = (int)$row['state'];
        $row['fault'] = (int)$row['fault'];
    }


    echo json_encode([
        "sampled" => $sampled,
        "sample_factor" => $sampled ? 2 : 1,
        "points" => $rows
    ]);


} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "error" => $e->getMessage()
    ]);
}