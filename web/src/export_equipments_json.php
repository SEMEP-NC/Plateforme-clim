<?php
require 'config/db.php';

$db = get_db();

// Récupérer tous les équipements
$equipments = $db->query("SELECT * FROM equipments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Récupérer tous les groupes
$groups = $db->query("SELECT * FROM groups_hvac ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les associations équipement → groupe
$stmt = $db->query("SELECT equipment_id, group_id FROM equipment_groups");
$equipmentGroups = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $equipmentGroups[$row['equipment_id']][] = $row['group_id'];
}

// Créer un tableau pour mapper les IDs de groupe à leurs noms
$groupNamesById = [];
foreach ($groups as $group) {
    $groupNamesById[$group['id']] = $group['name'];
}

// Préparer les données pour l'export
$exportData = [];
foreach ($equipments as $equipment) {
    // Récupérer les noms des groupes associés
    $groupNames = [];
    if (isset($equipmentGroups[$equipment['id']])) {
        foreach ($equipmentGroups[$equipment['id']] as $groupId) {
            if (isset($groupNamesById[$groupId])) {
                $groupNames[] = $groupNamesById[$groupId];
            }
        }
    }

    // Formater la puissance
    $power = is_numeric($equipment['power'])
        ? number_format($equipment['power']/10, 1) . ' kW'
        : $equipment['power'];

    $exportData[] = [
        'Nom' => $equipment['name'],
        'UI' => $equipment['UI'],
        'Puissance' => $power,
        'Groupes' => implode(', ', $groupNames)
    ];
}

// Définir les headers pour le téléchargement
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="equipements_' . date('Y-m-d_H-i-s') . '.json"');

// Encoder et afficher le JSON
echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
?>