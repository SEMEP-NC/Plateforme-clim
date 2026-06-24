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

/* relations equipment -> groups */
$equipmentGroups = [];

$stmt = $db->query("
    SELECT equipment_id, group_id
    FROM equipment_groups
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
            INSERT INTO groups_hvac(name)
            VALUES (?)
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
| SAVE GROUPS (EQUIPMENT ↔ GROUPS)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_groups'])) {

    foreach ($_POST['groups'] as $equipmentId => $groupIds) {

        $equipmentId = (int)$equipmentId;

        $db->prepare("
            DELETE FROM equipment_groups
            WHERE equipment_id = ?
        ")->execute([$equipmentId]);

        foreach ((array)$groupIds as $groupId) {

            $db->prepare("
                INSERT INTO equipment_groups (equipment_id, group_id)
                VALUES (?, ?)
            ")->execute([
                $equipmentId,
                (int)$groupId
            ]);
        }
    }

    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| SAVE EQUIPMENTS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {

    foreach ($_POST['name'] as $id => $name) {

        $db->prepare("
            UPDATE equipments
            SET name = ?
            WHERE id = ?
        ")->execute([
            trim($name),
            (int)$id
        ]);
    }

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

<!-- =========================
     GROUPES
========================= -->
<div class="card mb-4">
    <div class="card-header"><strong>Groupes</strong></div>

    <div class="card-body">

        <form method="POST" class="row g-2 mb-3">
            <div class="col-md-8">
                <input type="text" name="group_name" class="form-control" placeholder="Nouveau groupe">
            </div>

            <div class="col-md-4">
                <button class="btn btn-primary w-100" name="create_group">
                    Ajouter
                </button>
            </div>
        </form>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th width="120">Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($groups as $group): ?>
                <tr>
                    <td><?= htmlspecialchars($group['name']) ?></td>

                    <td>
                        <form method="POST">
                            <input type="hidden" name="group_id" value="<?= $group['id'] ?>">

                            <button class="btn btn-danger btn-sm"
                                name="delete_group"
                                onclick="return confirm('Supprimer ?')">
                                ❌
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    </div>
</div>

<!-- =========================
     ÉQUIPEMENTS
========================= -->
<div class="card mb-4">
    <div class="card-header"><strong>Unités</strong></div>
        <div class="card-body">
        <form method="POST">
            <button type="submit" name="save_all" class="btn btn-success mb-3">
                💾 Sauvegarder
            </button>
            <table class="table table-striped table-bordered align-middle">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>UI</th>
                    <th>Puissance</th>
                    <th>IP</th>
                    <th>Slave</th>
                    <th>Groupes</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($equipments as $eq): ?>
                <tr>
                <td>
                    <input class="form-control"
                        name="name[<?= $eq['id'] ?>]"
                        value="<?= htmlspecialchars($eq['name']) ?>">
                </td>
                <td><?= htmlspecialchars($eq['UI']) ?></td>
                <td>
                    <?= is_numeric($eq['power'])
                        ? number_format($eq['power']/10,1).' kW'
                        : htmlspecialchars($eq['power']) ?>
                </td>
                <td><?= htmlspecialchars($eq['ip']) ?></td>
                <td><?= htmlspecialchars($eq['slave_id']) ?></td>
                <td>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm open-group-modal"
                        data-id="<?= $eq['id'] ?>"
                        data-name="<?= htmlspecialchars($eq['name']) ?>"
                    >
                        Gérer
                    </button>
                </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        </form>
    </div>
</div>

<script>
const equipmentGroups = <?= json_encode($equipmentGroups) ?>;

document.querySelectorAll('.open-group-modal').forEach(btn => {

    btn.addEventListener('click', () => {

        const id = btn.dataset.id;
        const name = btn.dataset.name;

        document.getElementById('equipmentId').value = id;
        document.getElementById('modalTitle').innerText = "Groupes - " + name;

        // reset checkboxes
        document.querySelectorAll('.group-checkbox').forEach(cb => {
            cb.checked = false;

            const gid = cb.dataset.groupId;

            if (equipmentGroups[id] && equipmentGroups[id].includes(parseInt(gid))) {
                cb.checked = true;
            }

            // rewrite name dynamically
            cb.name = `groups[${id}][]`;
        });

        new bootstrap.Modal(document.getElementById('groupModal')).show();
    });

});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>