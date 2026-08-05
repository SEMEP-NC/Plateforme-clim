<?php
/*
|--------------------------------------------------------------------------
| EXPORT_HISTORY_CSV.PHP
|--------------------------------------------------------------------------
| Exporte au format CSV l'historique des équipements (T°, ON/OFF, défauts...).
| Accessible aux utilisateurs connectés (user/admin).
| Paramètres GET optionnels :
|   id          -> id d'un équipement précis (0 ou absent = tous)
|   date_start  -> date de début (YYYY-MM-DD)
|   date_end    -> date de fin (YYYY-MM-DD)
*/

require 'auth.php';
require_login();
require 'config/db.php';
require 'lib/audit.php';

$db = get_db();

$equipmentId = intval($_GET['id'] ?? 0);
$dateStart   = trim($_GET['date_start'] ?? '');
$dateEnd     = trim($_GET['date_end'] ?? '');

$where = [];
$params = [];

if ($equipmentId > 0) {
    $where[] = "h.equipment_id = ?";
    $params[] = $equipmentId;
}

if ($dateStart !== '') {
    $where[] = "h.created_at >= ?";
    $params[] = $dateStart . ' 00:00:00';
}

if ($dateEnd !== '') {
    $where[] = "h.created_at <= ?";
    $params[] = $dateEnd . ' 23:59:59';
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Limite de sécurité pour éviter un export démesuré
$limit = 100000;

$stmt = $db->prepare("
    SELECT
        h.created_at,
        e.name AS equipment_name,
        e.UI AS equipment_ui,
        h.state,
        h.fault,
        h.setpoint,
        h.return_temp,
        h.outside_temp,
        h.gate_status
    FROM equipment_history h
    LEFT JOIN equipments e ON e.id = h.equipment_id
    $sqlWhere
    ORDER BY h.created_at ASC
    LIMIT $limit
");
$stmt->execute($params);

$equipmentLabel = 'tous';
if ($equipmentId > 0) {
    $nameStmt = $db->prepare("SELECT name FROM equipments WHERE id=?");
    $nameStmt->execute([$equipmentId]);
    $equipmentLabel = $nameStmt->fetchColumn() ?: ('id_' . $equipmentId);
}

audit(
    'EXPORT_HISTORY_CSV',
    'Export CSV de l\'historique équipement : ' . $equipmentLabel
);

$filenameSafe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $equipmentLabel);
$filename = 'historique_' . $filenameSafe . '_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

// BOM UTF-8 pour une ouverture correcte dans Excel
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Date',
    'Equipement',
    'UI',
    'Etat',
    'Defaut',
    'Consigne (C)',
    'Temperature retour (C)',
    'Temperature exterieure (C)',
    'Statut contact externe'
], ';');

$tz = new DateTimeZone('Pacific/Noumea');

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $date = new DateTime($row['created_at'], new DateTimeZone('UTC'));
    $date->setTimezone($tz);

    fputcsv($out, [
        $date->format('d/m/Y H:i:s'),
        $row['equipment_name'] ?? ('id ' . $equipmentId),
        $row['equipment_ui'],
        $row['state'] ? 'ON' : 'OFF',
        $row['fault'] ? 'OUI' : 'NON',
        $row['setpoint'],
        $row['return_temp'],
        $row['outside_temp'],
        $row['gate_status'],
    ], ';');
}

fclose($out);
exit;
