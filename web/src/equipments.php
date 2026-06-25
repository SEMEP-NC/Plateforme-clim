<?php
require 'config/db.php';
$db = get_db();

/* DATA */
$groups = $db->query("SELECT * FROM groups_hvac ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$equipments = $db->query("SELECT * FROM equipments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* relations */
$equipmentGroups = [];
$groupEquipments = [];

$stmt = $db->query("SELECT equipment_id, group_id FROM equipment_groups");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $equipmentGroups[$row['equipment_id']][] = $row['group_id'];
    $groupEquipments[$row['group_id']][] = $row['equipment_id'];
}

/* CREATE GROUP */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $name = trim($_POST['group_name']);
    if ($name !== '') {
        $db->prepare("INSERT INTO groups_hvac(name) VALUES (?)")->execute([$name]);
    }
    header("Location: equipments.php");
    exit;
}

/* DELETE GROUP */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group'])) {
    $id = (int)$_POST['group_id'];
    $db->prepare("DELETE FROM equipment_groups WHERE group_id=?")->execute([$id]);
    $db->prepare("DELETE FROM groups_hvac WHERE id=?")->execute([$id]);
    header("Location: equipments.php");
    exit;
}

/* SAVE GROUPS */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_groups']) && isset($_POST['equipments'])) {
    foreach ($_POST['equipments'] as $groupId => $equipmentIds) {
        $groupId = (int)$groupId;
        $db->prepare("DELETE FROM equipment_groups WHERE group_id=?")->execute([$groupId]);
        foreach ((array)$equipmentIds as $equipmentId) {
            $db->prepare("INSERT INTO equipment_groups (equipment_id, group_id) VALUES (?, ?)")
                ->execute([(int)$equipmentId, $groupId]);
        }
    }
    header("Location: equipments.php");
    exit;
}

/* SAVE EQUIPMENT GROUPS */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_equipment_groups']) && isset($_POST['groups'])) {
    foreach ($_POST['groups'] as $equipmentId => $groupIds) {
        $equipmentId = (int)$equipmentId;
        $db->prepare("DELETE FROM equipment_groups WHERE equipment_id=?")->execute([$equipmentId]);
        foreach ((array)$groupIds as $groupId) {
            $db->prepare("INSERT INTO equipment_groups (equipment_id, group_id) VALUES (?, ?)")
                ->execute([$equipmentId, (int)$groupId]);
        }
    }
    header("Location: equipments.php");
    exit;
}

/* SAVE ALL */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
    foreach ($_POST['name'] as $id => $name) {
        $db->prepare("UPDATE equipments SET name=? WHERE id=?")
            ->execute([trim($name), (int)$id]);
    }
    header("Location: equipments.php");
    exit;
}

/* DELETE EQUIPMENT */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_equipment'])) {
    $id = (int)$_POST['id'];
    $db->prepare("DELETE FROM equipment_groups WHERE equipment_id=?")->execute([$id]);
    $db->prepare("DELETE FROM equipments WHERE id=?")->execute([$id]);
    header("Location: equipments.php");
    exit;
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
<div class="card-header"><strong>Groupes</strong></div>
<div class="card-body">

<form method="POST" class="row g-2 mb-3">
<div class="col-md-8">
<input type="text" name="group_name" class="form-control" placeholder="Nouveau groupe">
</div>
<div class="col-md-4">
<button class="btn btn-primary w-100" name="create_group">Ajouter</button>
</div>
</form>

<table class="table table-bordered">
<thead>
<tr><th>Nom</th><th>Unités</th><th>Actions</th></tr>
</thead>
<tbody>
<?php foreach ($groups as $group): ?>
<tr>
<td><?= htmlspecialchars($group['name']) ?></td>
<td>
<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#groupModal<?= $group['id'] ?>">
Voir unités
</button>
</td>
<td>
<form method="POST">
<input type="hidden" name="group_id" value="<?= $group['id'] ?>">
<button class="btn btn-danger btn-sm" name="delete_group">❌</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</div>
</div>

<!-- EQUIPMENTS -->
<div class="card mb-4">
<div class="card-header"><strong>Unités</strong></div>
<div class="card-body">

<div class="d-flex justify-content-between mb-3">
<a href="export_equipments_json.php" class="btn btn-info">📥 JSON</a>

<button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalExportFuxa">
🏷️ Export FUXA
</button>
</div>

</div>
</div>

<!-- MODAL FUXA -->
<div class="modal fade" id="modalExportFuxa">
<div class="modal-dialog">
<div class="modal-content">

<div class="modal-header">
<h5 class="modal-title">Exporter FUXA</h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
Exporter les équipements vers FUXA.
</div>

<div class="modal-footer">
<button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>

<button class="btn btn-primary" onclick="downloadFuxa()">
⬇️ Télécharger JSON
</button>
</div>

</div>
</div>
</div>

<script>
function downloadFuxa() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalExportFuxa'));
    if (modal) modal.hide();

    setTimeout(() => {
        window.location.href = 'export_fuxa.php';
    }, 300);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>