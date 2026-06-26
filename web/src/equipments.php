<?php
require 'config/db.php';

$db = get_db();

/*
|--------------------------------------------------------------------------
id;| Helpers

            $name = trim($name);
            $ip = trim($ips[$id] ?? '');
            $port = (int)($ports[$id] ?? 502);
            $slaveId = (int)($slaveIds[$id] ?? 1);
            $power = ($powers[$id] ?? '') !== '' ? (int)$powers[$id] : null;
            $ui = (int)($uis[$id] ?? 1);
            $enabled = isset($enableds[$id]) ? 1 : 0;

            if ($hasGroupId) {
                $groupId = ($groupIds[$id] ?? '') !== '' ? (int)$groupIds[$id] : null;

                $stmt = $db->prepare("
                    UPDATE equipments
                    SET 
                        name = ?,
                        ip = ?,
                        port = ?,
                        slave_id = ?,
                        power = ?,
                        UI = ?,
                        enabled = ?,
                        group_id = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $ip,
                    $port,
                    $slaveId,
                    $power,
                    $ui,
                    $enabled,
                    $groupId,
                    $id
                ]);
            } else {
                $stmt = $db->prepare("
                    UPDATE equipments
                    SET 
                        name = ?,
                        ip = ?,
                        port = ?,
                        slave_id = ?,
                        power = ?,
                        UI = ?,
                        enabled = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $ip,
                    $port,
                    $slaveId,
                    $power,
                    $ui,
                    $enabled,
                    $id
                ]);
            }
        }

        $message = "Équipements sauvegardés avec succès.";
    } catch (Exception $e) {
        $error = "Erreur sauvegarde : " . $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Chargement groupes
|--------------------------------------------------------------------------
*/
$groups = [];

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
| Chargement équipements
|--------------------------------------------------------------------------
*/
$equipments = [];

try {
    if ($hasGroupsTable && $hasGroupId) {
        $equipments = $db->query("
            SELECT 
                e.*,
                g.name AS group_name
            FROM equipments e
            LEFT JOIN groups_hvac g ON g.id = e.group_id
            ORDER BY 
                COALESCE(g.name, 'Sans groupe'),
                e.UI,
                e.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $equipments = $db->query("
            SELECT *
            FROM equipments
            ORDER BY UI, name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error = "Erreur chargement équipements : " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Équipements climatisation</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >

    <style>
        body {
            background: #f5f7fa;
        }

        .page-title {
            font-weight: 700;
        }

        .card {
            border-radius: 14px;
        }

        .table thead th {
            white-space: nowrap;
            vertical-align: middle;
        }

        .table td {
            vertical-align: middle;
        }

        .form-control,
        .form-select {
            min-width: 90px;
        }

        .small-input {
            max-width: 95px;
        }

        .ip-input {
            min-width: 140px;
        }

        .name-input {
            min-width: 180px;
        }

        .group-badge {
            font-size: 0.85rem;
        }
    </style>
</head>

<body>
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-1">❄️ Équipements climatisation</h1>
            <p class="text-muted mb-0">
                Gestion des équipements sauvegardés, groupes et export JSON FUXA.
            </p>
        </div>

        <div>
            <a href="index.php" class="btn btn-outline-secondary">
                ⬅️ Retour
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= h($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($hasGroupsTable): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <strong>📁 Groupes</strong>
            </div>

            <div class="card-body">
                <form method="POST" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Nouveau groupe</label>
                        <input 
                            type="text" 
                            name="group_name" 
                            class="form-control" 
                            placeholder="Ex : Bureaux, Atelier, Salle serveur"
                        >
                    </div>

                    <div class="col-md-auto">
                        <button type="submit" name="add_group" class="btn btn-primary">
                            ➕ Ajouter le groupe
                        </button>
                    </div>
                </form>

                <?php if (!empty($groups)): ?>
                    <div class="mt-3">
                        <?php foreach ($groups as $group): ?>
                            <span class="badge text-bg-secondary me-1 group-badge">
                                <?= h($group['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted mt-3">
                        Aucun groupe créé pour le moment.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>📋 Liste des équipements</strong>

                <span class="badge text-bg-info">
                    <?= count($equipments) ?> équipement(s)
                </span>
            </div>

            <div class="card-body">

                <div class="d-flex justify-content-between mb-3">
                    <button type="submit" name="save_all" class="btn btn-success">
                        💾 Sauvegarder
                    </button>

                    <a href="export_equipments_json.php" class="btn btn-info">
                        📥 Exporter en JSON
                    </a>
                </div>

                <?php if (empty($equipments)): ?>
                    <div class="alert alert-warning mb-0">
                        Aucun équipement sauvegardé.
                    </div>
                <?php else: ?>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Actif</th>
                                    <th>ID</th>
                                    <th>Nom</th>
                                    <th>UI</th>
                                    <th>IP</th>
                                    <th>Port</th>
                                    <th>Slave ID</th>
                                    <th>Puissance</th>

                                    <?php if ($hasGroupsTable && $hasGroupId): ?>
                                        <th>Groupe</th>
                                    <?php endif; ?>

                                    <th>Créé le</th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($equipments as $eq): ?>
                                <?php
                                    $id = (int)$eq['id'];
                                    $enabled = isset($eq['enabled']) ? (int)$eq['enabled'] : 1;
                                ?>

                                <tr>
                                    <td class="text-center">
                                        <input 
                                            type="checkbox" 
                                            name="enabled[<?= $id ?>]" 
                                            value="1" 
                                            class="form-check-input"
                                            <?= $enabled ? 'checked' : '' ?>
                                        >
                                    </td>

                                    <td>
                                        <?= $id ?>
                                    </td>

                                    <td>
                                        <input 
                                            type="text" 
                                            name="name[<?= $id ?>]" 
                                            class="form-control name-input"
                                            value="<?= h($eq['name'] ?? '') ?>"
                                        >
                                    </td>

                                    <td>
                                        <input 
                                            type="number" 
                                            name="UI[<?= $id ?>]" 
                                            class="form-control small-input"
                                            value="<?= h($eq['UI'] ?? '') ?>"
                                            min="1"
                                        >
                                    </td>

                                    <td>
                                        <input 
                                            type="text" 
                                            name="ip[<?= $id ?>]" 
                                            class="form-control ip-input"
                                            value="<?= h($eq['ip'] ?? '') ?>"
                                        >
                                    </td>

                                    <td>
                                        <input 
                                            type="number" 
                                            name="port[<?= $id ?>]" 
                                            class="form-control small-input"
                                            value="<?= h($eq['port'] ?? 502) ?>"
                                            min="1"
                                        >
                                    </td>

                                    <td>
                                        <input 
                                            type="number" 
                                            name="slave_id[<?= $id ?>]" 
                                            class="form-control small-input"
                                            value="<?= h($eq['slave_id'] ?? 1) ?>"
                                            min="1"
                                        >
                                    </td>

                                    <td>
                                        <input 
                                            type="number" 
                                            name="power[<?= $id ?>]" 
                                            class="form-control small-input"
                                            value="<?= h($eq['power'] ?? '') ?>"
                                            min="0"
                                        >
                                    </td>

                                    <?php if ($hasGroupsTable && $hasGroupId): ?>
                                        <td>
                                            <select 
                                                name="group_id[<?= $id ?>]" 
                                                class="form-select"
                                            >
                                                <option value="">Sans groupe</option>

                                                <?php foreach ($groups as $group): ?>
                                                    <option 
                                                        value="<?= h($group['id']) ?>"
                                                        <?= isset($eq['group_id']) && (int)$eq['group_id'] === (int)$group['id'] ? 'selected' : '' ?>
                                                    >
                                                        <?= h($group['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    <?php endif; ?>

                                    <td>
                                        <?= h($eq['created_at'] ?? '') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>

                <div class="d-flex justify-content-between mt-3">
                    <button type="submit" name="save_all" class="btn btn-success">
                        💾 Sauvegarder
                    </button>

                    <a href="export_equipments_json.php" class="btn btn-info">
                        📥 Exporter en JSON
                    </a>
                </div>

            </div>
        </div>
    </form>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
|--------------------------------------------------------------------------
*/
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

/*
|--------------------------------------------------------------------------
| Structure DB
|--------------------------------------------------------------------------
*/
$hasGroupsTable = table_exists($db, 'groups_hvac');
$hasGroupId = column_exists($db, 'equipments', 'group_id');

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Ajouter un groupe
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_group'])) {
    if ($hasGroupsTable) {
        $groupName = trim($_POST['group_name'] ?? '');

        if ($groupName === '') {
            $error = "Le nom du groupe ne peut pas être vide.";
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO groups_hvac (name) VALUES (?)");
                $stmt->execute([$groupName]);
                $message = "Groupe ajouté avec succès.";
            } catch (Exception $e) {
                $error = "Erreur ajout groupe : " . $e->getMessage();
            }
        }
    } else {
        $error = "La table groups_hvac n'existe pas.";
    }
}

/*
|--------------------------------------------------------------------------
| Sauvegarder les équipements
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
    try {
        $names = $_POST['name'] ?? [];
        $ips = $_POST['ip'] ?? [];
        $ports = $_POST['port'] ?? [];
        $slaveIds = $_POST['slave_id'] ?? [];
        $powers = $_POST['power'] ?? [];
        $uis = $_POST['UI'] ?? [];
        $enableds = $_POST['enabled'] ?? [];
        $groupIds = $_POST['group_id'] ?? [];

        foreach ($names as $id => $name) {
