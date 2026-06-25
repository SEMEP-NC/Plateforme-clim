<?php
require 'config/db.php';

$db = get_db();

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/

$groups = $db->query("
    SELECT * FROM groups_hvac ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$equipments = $db->query("
    SELECT * FROM equipments ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

/* relations */
$equipmentGroups = [];

$stmt = $db->query("
    SELECT equipment_id, group_id FROM equipment_groups
");

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $equipmentGroups[$row['equipment_id']][] = $row['group_id'];
}

/*
|--------------------------------------------------------------------------
| CREATE GROUP
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {

    $name = trim($_POST['group_name']);

    if ($name !== '') {
        $db->prepare("
            INSERT INTO groups_hvac(name) VALUES (?)
        ")->execute([$name]);
    }

    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| DELETE GROUP
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group'])) {

    $id = (int)$_POST['group_id'];

    $db->prepare("DELETE FROM equipment_groups WHERE group_id=?")->execute([$id]);
    $db->prepare("DELETE FROM groups_hvac WHERE id=?")->execute([$id]);

    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| SAVE GROUPS (EQUIPMENT → GROUPS)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_equipment_groups'])) {

    $db->prepare("DELETE FROM equipment_groups")->execute();

    foreach ($_POST['groups'] ?? [] as $equipmentId => $groupIds) {

        foreach ((array)$groupIds as $groupId) {

            $db->prepare("
                INSERT INTO equipment_groups (equipment_id, group_id)
                VALUES (?, ?)
            ")->execute([
                (int)$equipmentId,
                (int)$groupId
            ]);
        }
    }

    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| SAVE ALL EQUIPMENTS (NAME ONLY)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {

    foreach ($_POST['name'] as $id => $name) {

        $db->prepare("
            UPDATE equipments SET name=? WHERE id=?
        ")->execute([
            trim($name),
            (int)$id
        ]);
    }

    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| DELETE EQUIPMENT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_equipment'])) {

    $id = (int)$_POST['id'];

    $db->prepare("DELETE FROM equipment_groups WHERE equipment_id=?")->execute([$id]);
    $db->prepare("DELETE FROM equipments WHERE id=?")->execute([$id]);

    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| EXPORT JSON
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_json'])) {

    $export = [
        'exported_at' => date('Y-m-d H:i:s'),
        'equipments'  => []
    ];

    foreach ($equipments as $equipment) {

        $groupNames = [];

        foreach (($equipmentGroups[$equipment['id']] ?? []) as $groupId) {
            foreach ($groups as $group) {
                if ((int)$group['id'] === (int)$groupId) {
                    $groupNames[] = $group['name'];
                }
            }
        }

        $export['equipments'][] = [
            'id'       => (int)$equipment['id'],
            'name'     => $equipment['name'],
            'UI'       => (int)$equipment['UI'],
            'power'    => is_numeric($equipment['power'])
                ? (float)($equipment['power'] / 10)
                : $equipment['power'],
            'ip'       => $equipment['ip'],
            'slave_id' => (int)$equipment['slave_id'],
            'groups'   => $groupNames
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=equipements_' . date('Ymd_His') . '.json');

    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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

<form method="POST" class="d-inline">
    <button type="submit" name="export_json" value="1" class="btn btn-primary mb-3 ms-2">
        📥 Export JSON
    </button>
</form>

<form method="POST">

    <button type="submit" name="save_all" class="btn btn-success mb-3">
        💾 Sauvegarder
    </button>

    <table class="table table-bordered table-striped align-middle">

        <thead>
        <tr>
            <th>Nom</th>
            <th>UI</th>
            <th>Puissance</th>
            <th>IP</th>
            <th>Slave</th>
            <th>Groupes</th>
            <th>Actions</th>
        </tr>
        </thead>

        <tbody>

        <?php foreach ($equipments as $equipment): ?>
            <tr>

                <td>
                    <input type="text"
                           name="name[<?= $equipment['id'] ?>]"
                           value="<?= htmlspecialchars($equipment['name']) ?>"
                           class="form-control">
                </td>

                <td><?= htmlspecialchars($equipment['UI']) ?></td>

                <td>
                    <?= is_numeric($equipment['power'])
                        ? number_format($equipment['power']/10,1).' kW'
                        : htmlspecialchars($equipment['power']) ?>
                </td>

                <td><?= htmlspecialchars($equipment['ip']) ?></td>
                <td><?= htmlspecialchars($equipment['slave_id']) ?></td>

                <td>
                    <button type="button"
                            class="btn btn-primary btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#equipModal<?= $equipment['id'] ?>">
                        Groupes
                    </button>
                </td>

                <td>
                    <form method="POST" onsubmit="return confirm('Supprimer ?');">
                        <input type="hidden" name="id" value="<?= $equipment['id'] ?>">
                        <button class="btn btn-danger btn-sm" name="delete_equipment">
                            ❌
                        </button>
                    </form>
                </td>

            </tr>
        <?php endforeach; ?>

        </tbody>
    </table>
</form>

<!-- MODALS -->
<?php foreach ($equipments as $equipment): ?>
<div class="modal fade" id="equipModal<?= $equipment['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">Groupes - <?= htmlspecialchars($equipment['name']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <?php foreach ($groups as $group): ?>

                    <?php
                    $checked = in_array($group['id'], $equipmentGroups[$equipment['id']] ?? []);
                    ?>

                    <div class="form-check">
                        <input class="form-check-input"
                               type="checkbox"
                               name="groups[<?= $equipment['id'] ?>][]"
                               value="<?= $group['id'] ?>"
                               <?= $checked ? 'checked' : '' ?>>

                        <label class="form-check-label">
                            <?= htmlspecialchars($group['name']) ?>
                        </label>
                    </div>

                <?php endforeach; ?>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" name="save_equipment_groups" class="btn btn-success">Valider</button>
            </div>

        </form>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>