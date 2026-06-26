<?php
require 'config/db.php';
$db = get_db();

/* DATA */
$groups = $db->query("SELECT * FROM groups_hvac ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$equipments = $db->query("SELECT * FROM equipments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* relations */
$equipmentGroups = [];

$stmt = $db->query("SELECT equipment_id, group_id FROM equipment_groups");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $equipmentGroups[$row['equipment_id']][] = $row['group_id'];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Équipements</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="container mt-5">

<h1>Équipements</h1>

<a href="index.php" class="btn btn-secondary mb-3">Retour</a>

<div class="card mb-4">
    <div class="card-header"><strong>Unités</strong></div>
    <div class="card-body">

        <!-- ✅ SEUL BOUTON CONSERVÉ -->
        <div class="mb-3">
            <a href="export_equipments_json.php" class="btn btn-info">
                📥 Exporter en JSON
            </a>
        </div>

        <!-- Tableau simple -->
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>UI</th>
                    <th>IP</th>
                    <th>Port</th>
                    <th>Slave</th>
                    <th>Puissance</th>
                    <th>Groupes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($equipments as $eq): ?>
                <tr>
                    <td><?= htmlspecialchars($eq['name']) ?></td>
                    <td><?= $eq['UI'] ?></td>
                    <td><?= $eq['ip'] ?></td>
                    <td><?= $eq['port'] ?></td>
                    <td><?= $eq['slave_id'] ?></td>
                    <td><?= $eq['power'] ? $eq['power']/10 . " kW" : "" ?></td>

                    <td>
                        <?php
                        if (isset($equipmentGroups[$eq['id']])) {
                            foreach ($equipmentGroups[$eq['id']] as $gid) {
                                foreach ($groups as $g) {
                                    if ($g['id'] == $gid) {
                                        echo htmlspecialchars($g['name']) . "<br>";
                                    }
                                }
                            }
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>