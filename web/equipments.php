<?php
require 'config/db.php';

$db = get_db();

function h($value): string
{
   groupId > 0 && $hasGroupsTable) {    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        try {
            if ($hasEquipmentGroupsTable) {
                $stmt = $db->prepare("DELETE FROM equipment_groups WHERE group_id = ?");
                $stmt->execute([$groupId]);
            }

            if ($hasGroupIdColumn) {
                $stmt = $db->prepare("UPDATE equipments SET group_id = NULL WHERE group_id = ?");
                $stmt->execute([$groupId]);
            }

            $stmt = $db->prepare("DELETE FROM groups_hvac WHERE id = ?");
            $stmt->execute([$groupId]);

            $message = "Groupe supprimé.";
        } catch (Exception $e) {
            $error = "Erreur suppression groupe : " . $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Supprimer équipement
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_equipment'])) {
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);

    if ($equipmentId > 0) {
        try {
            if ($hasEquipmentGroupsTable) {
                $stmt = $db->prepare("DELETE FROM equipment_groups WHERE equipment_id = ?");
                $stmt->execute([$equipmentId]);
            }

            $stmt = $db->prepare("DELETE FROM equipments WHERE id = ?");
            $stmt->execute([$equipmentId]);

            $message = "Équipement supprimé.";
        } catch (Exception $e) {
            $error = "Erreur suppression équipement : " . $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Sauvegarder équipements
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
    try {
        $names = $_POST['name'] ?? [];
        $enableds = $_POST['enabled'] ?? [];

        foreach ($names as $id => $name) {
            $id = (int)$id;
            $name = trim($name);
            $enabled = isset($enableds[$id]) ? 1 : 0;

            if ($hasEnabledColumn) {
                $stmt = $db->prepare("
                    UPDATE equipments
                    SET name = ?, enabled = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $enabled, $id]);
            } else {
                $stmt = $db->prepare("
                    UPDATE equipments
                    SET name = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $id]);
            }
        }

        $message = "Équipements sauvegardés.";
    } catch (Exception $e) {
        $error = "Erreur sauvegarde : " . $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Sauvegarder groupes d'un équipement
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_equipment_groups'])) {
    $equipmentId = (int)($_POST['equipment_id'] ?? 0);
    $selectedGroups = $_POST['selected_groups'] ?? [];

    try {
        if ($equipmentId > 0 && $hasEquipmentGroupsTable) {
            $stmt = $db->prepare("DELETE FROM equipment_groups WHERE equipment_id = ?");
            $stmt->execute([$equipmentId]);

            foreach ($selectedGroups as $groupId) {
                $stmt = $db->prepare("
                    INSERT INTO equipment_groups (equipment_id, group_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$equipmentId, (int)$groupId]);
            }

            $message = "Groupes sauvegardés.";
        } elseif ($equipmentId > 0 && $hasGroupIdColumn) {
            $groupId = isset($selectedGroups[0]) ? (int)$selectedGroups[0] : null;

            $stmt = $db->prepare("
                UPDATE equipments
                SET group_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$groupId, $equipmentId]);

            $message = "Groupe sauvegardé.";
        }
    } catch (Exception $e) {
        $error = "Erreur sauvegarde groupes : " . $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Charger groupes
|--------------------------------------------------------------------------
*/
if ($hasGroupsTable) {
    try {
        $groups = $db->query("
            SELECT *
            FROM groups_hvac
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $groups = [];
    }
}

/*
|--------------------------------------------------------------------------
| Charger équipements
|--------------------------------------------------------------------------
*/
try {
    $equipments = $db->query("
        SELECT *
        FROM equipments
        ORDER BY UI ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $equipments = [];
    $error = "Erreur chargement équipements : " . $e->getMessage();
}

/*
|--------------------------------------------------------------------------
| Charger associations groupes / équipements
|--------------------------------------------------------------------------
*/
try {
    if ($hasEquipmentGroupsTable) {
        $rows = $db->query("
            SELECT 
                eg.equipment_id,
                g.id,
                g.name
            FROM equipment_groups eg
            INNER JOIN groups_hvac g ON g.id = eg.group_id
            ORDER BY g.name
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $equipmentGroups[(int)$row['equipment_id']][] = $row;
        }
    } elseif ($hasGroupIdColumn && $hasGroupsTable) {
        foreach ($equipments as $eq) {
            if (!empty($eq['group_id'])) {
                foreach ($groups as $group) {
                    if ((int)$group['id'] === (int)$eq['group_id']) {
                        $equipmentGroups[(int)$eq['id']][] = $group;
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    $equipmentGroups = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Équipements</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f5f6f8;
        }

        .page-container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 35px 20px;
        }

        h1 {
            font-size: 2.3rem;
            margin-bottom: 10px;
        }

        .card {
            margin-bottom: 25px;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

        .name-input {
            min-width: 260px;
        }

        .btn-delete {
            min-width: 42px;
        }

        .group-badge {
            margin-right: 4px;
            margin-bottom: 4px;
        }
    </style>
</head>

<body>
<div class="page-container">

    <h1>Équipements</h1>

    <a href="index.php" class="btn btn-secondary mb-3">
        Retour
    </a>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Groupes -->
    <div class="card">
        <div class="card-header">
            <strong>Groupes</strong>
        </div>

        <div class="card-body">
            <form method="POST" class="row g-2 mb-3">
                <div class="col-md-9">
                    <input
                        type="text"
                        name="group_name"
                        class="form-control"
                        placeholder="Nouveau groupe"
                    >
                </div>

                <div class="col-md-3">
                    <button type="submit" name="add_group" class="btn btn-primary w-100">
                        Ajouter
                    </button>
                </div>
            </form>

            <table class="table table-bordered mb-0">
                <thead>
                <tr>
                    <th>Nom</th>
                    <th style="width: 160px;">Actions</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($groups)): ?>
                    <tr>
                        <td colspan="2" class="text-muted">
                            Aucun groupe créé.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($groups as $group): ?>
                        <tr>
                            <td><?= h($group['name'] ?? '') ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Supprimer ce groupe ?');">
                                    <input type="hidden" name="group_id" value="<?= h($group['id'] ?? '') ?>">
                                    <button type="submit" name="delete_group" class="btn btn-danger btn-sm btn-delete">
                                        ❌
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Unités -->
    <div class="card">
        <div class="card-header">
            <strong>Unités</strong>
        </div>

        <div class="card-body">

            <form method="POST">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <button type="submit" name="save_all" class="btn btn-success">
                        💾 Sauvegarder
                    </button>

                    <a href="export_equipments_json.php" class="btn btn-info">
                        📥 Exporter en JSON
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                        <tr>
                            <th>Actif</th>
                            <th>ID</th>
                            <th>Nom</th>
                            <th>UI</th>
                            <th>Puissance</th>
                            <th>IP</th>
                            <th>Slave</th>
                            <th>Groupes</th>
                            <th style="width: 160px;">Actions</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if (empty($equipments)): ?>
                            <tr>
                                <td colspan="9" class="text-muted">
                                    Aucun équipement sauvegardé.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($equipments as $eq): ?>
                                <?php
                                $equipmentId = (int)($eq['id'] ?? 0);
                                $enabled = isset($eq['enabled']) ? (int)$eq['enabled'] : 1;
                                $eqGroups = $equipmentGroups[$equipmentId] ?? [];
                                ?>

                                <tr>
                                    <td class="text-center">
                                        <input
                                            type="checkbox"
                                            name="enabled[<?= $equipmentId ?>]"
                                            value="1"
                                            class="form-check-input"
                                            <?= $enabled ? 'checked' : '' ?>
                                        >
                                    </td>

                                    <td><?= h($equipmentId) ?></td>

                                    <td>
                                        <input
                                            type="text"
                                            name="name[<?= $equipmentId ?>]"
                                            class="form-control name-input"
                                            value="<?= h($eq['name'] ?? '') ?>"
                                        >
                                    </td>

                                    <td><?= h($eq['UI'] ?? '') ?></td>

                                    <td><?= h(format_power($eq['power'] ?? '')) ?></td>

                                    <td><?= h($eq['ip'] ?? '') ?></td>

                                    <td><?= h($eq['slave_id'] ?? '') ?></td>

                                    <td>
                                        <?php if (!empty($eqGroups)): ?>
                                            <?php foreach ($eqGroups as $group): ?>
                                                <span class="badge text-bg-secondary group-badge">
                                                    <?= h($group['name'] ?? '') ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <button
                                            type="button"
                                            class="btn btn-primary btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#groupsModal<?= $equipmentId ?>"
                                        >
                                            Groupes
                                        </button>
                                    </td>

                                    <td>
                                        <button
                                            type="submit"
                                            name="delete_equipment"
                                            value="1"
                                            class="btn btn-danger btn-sm btn-delete"
                                            formaction="equipments.php"
                                            formmethod="POST"
                                            onclick="document.getElementById('delete-equipment-id').value='<?= $equipmentId ?>'; return confirm('Supprimer cet équipement ?');"
                                        >
                                            ❌
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <input type="hidden" id="delete-equipment-id" name="equipment_id" value="">
            </form>

        </div>
    </div>

</div>

<?php foreach ($equipments as $eq): ?>
    <?php
    $equipmentId = (int)($eq['id'] ?? 0);
    $eqGroups = $equipmentGroups[$equipmentId] ?? [];
    $selectedGroupIds = array_map(fn($g) => (int)$g['id'], $eqGroups);
    ?>

    <div class="modal fade" id="groupsModal<?= $equipmentId ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">

                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            Groupes — <?= h($eq['name'] ?? '') ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <input type="hidden" name="equipment_id" value="<?= $equipmentId ?>">

                        <?php if (empty($groups)): ?>
                            <div class="text-muted">
                                Aucun groupe disponible.
                            </div>
                        <?php else: ?>
                            <?php foreach ($groups as $group): ?>
                                <?php $groupId = (int)($group['id'] ?? 0); ?>

                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="<?= $hasEquipmentGroupsTable ? 'checkbox' : 'radio' ?>"
                                        name="selected_groups[]"
                                        value="<?= $groupId ?>"
                                        id="eq<?= $equipmentId ?>group<?= $groupId ?>"
                                        <?= in_array($groupId, $selectedGroupIds, true) ? 'checked' : '' ?>
                                    >

                                    <label
                                        class="form-check-label"
                                        for="eq<?= $equipmentId ?>group<?= $groupId ?>"
                                    >
                                        <?= h($group['name'] ?? '') ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Annuler
                        </button>

                        <button type="submit" name="save_equipment_groups" class="btn btn-primary">
                            Sauvegarder
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
``
}

function table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function format_power($power): string
{
    if ($power === null || $power === '') {
        return '';
    }

    $power = (float)$power;

    if ($power >= 10) {
        return number_format($power / 10, 1, '.', '') . ' kW';
    }

    return number_format($power, 1, '.', '') . ' kW';
}

$message = '';
$error = '';

$hasGroupsTable = table_exists($db, 'groups_hvac');
$hasEquipmentGroupsTable = table_exists($db, 'equipment_groups');
$hasGroupIdColumn = column_exists($db, 'equipments', 'group_id');
$hasEnabledColumn = column_exists($db, 'equipments', 'enabled');

$groups = [];
$equipments = [];
$equipmentGroups = [];

/*
|--------------------------------------------------------------------------
| Ajouter groupe
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_group'])) {
    $groupName = trim($_POST['group_name'] ?? '');

    if (!$hasGroupsTable) {
        $error = "La table groups_hvac n'existe pas.";
    } elseif ($groupName === '') {
        $error = "Le nom du groupe est obligatoire.";
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO groups_hvac (name) VALUES (?)");
            $stmt->execute([$groupName]);
            $message = "Groupe ajouté.";
        } catch (Exception $e) {
            $error = "Erreur ajout groupe : " . $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Supprimer groupe
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_group'])) {
    $groupId = (int)($_POST['group_id'] ?? 0);

