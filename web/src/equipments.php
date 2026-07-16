<?php
require 'auth.php';
session_start();
require_login();
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
    SELECT * FROM equipments ORDER BY UI
")->fetchAll(PDO::FETCH_ASSOC);

$localisations = [];

foreach ($equipments as $equipment) {
    if (!empty($equipment['localisation'])) {
        $localisations[] = $equipment['localisation'];
    }
}

$localisations = array_unique($localisations);
sort($localisations);
/* relations */
$equipmentGroups = [];
$groupEquipments = [];

$stmt = $db->query("
    SELECT equipment_id, group_id FROM equipment_groups
");

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $equipmentGroups[$row['equipment_id']][] = $row['group_id'];
    $groupEquipments[$row['group_id']][] = $row['equipment_id'];
}

/*
|--------------------------------------------------------------------------
| CREATE GROUP
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $name = trim($_POST['group_name']);
    if ($name !== '') {
        $db->prepare("INSERT INTO groups_hvac(name) VALUES (?)")->execute([$name]);
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
| SAVE GROUPS EQUIPMENT (MODAL GROUPS)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_groups']) && isset($_POST['equipments'])) {
    foreach ($_POST['equipments'] as $groupId => $equipmentIds) {
        $groupId = (int)$groupId;
        $db->prepare("DELETE FROM equipment_groups WHERE group_id = ?")->execute([$groupId]);
        foreach ((array)$equipmentIds as $equipmentId) {
            $db->prepare("INSERT INTO equipment_groups (equipment_id, group_id) VALUES (?, ?)")
                ->execute([(int)$equipmentId, $groupId]);
        }
    }
    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| SAVE EQUIPMENT GROUPS (MODAL EQUIP)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_equipment_groups']) && isset($_POST['groups'])) {
    foreach ($_POST['groups'] as $equipmentId => $groupIds) {
        $equipmentId = (int)$equipmentId;
        $db->prepare("DELETE FROM equipment_groups WHERE equipment_id = ?")->execute([$equipmentId]);
        foreach ((array)$groupIds as $groupId) {
            $db->prepare("INSERT INTO equipment_groups (equipment_id, group_id) VALUES (?, ?)")
                ->execute([$equipmentId, (int)$groupId]);
        }
    }
    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| SAVE ALL EQUIPMENTS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {

    $stmt = $db->prepare("
        UPDATE equipments 
        SET name = ?, localisation = ?
        WHERE id = ?
    ");

    foreach ($_POST['name'] as $id => $name) {

        $localisation = $_POST['localisation'][$id] ?? '';

        $stmt->execute([
            trim($name),
            trim($localisation),
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
<style>
    .sortable {
        cursor: pointer;
        user-select: none;
    }

    .sortable:hover {
        background-color: #f0f0f0;
    }
</style>
<body class="container mt-5">
    <div class="text-center mt-4">
        <h1 class="fw-bold">Équipements</h1>
    </div>
    <a href="index.php" class="btn btn-secondary mb-3">Retour</a>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>

    <!-- ========================= GROUPES ========================= -->
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
                    <tr>
                        <th>Nom</th>
                        <th>Unités</th>
                        <th>Commandes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group): ?>
                    <tr>
                        <td><?= htmlspecialchars($group['name']) ?></td>
                        <td>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#groupModal<?= $group['id'] ?>">
                                Voir unités
                            </button>
                        </td>
                        <td>
                            <button
                                type="button"
                                class="btn btn-warning btn-sm groupCommandButton"
                                data-id="<?= $group['id'] ?>"
                                data-name="<?= htmlspecialchars($group['name']) ?>"
                            >
                                Commande
                            </button>
                        </td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                <button class="btn btn-danger btn-sm" name="delete_group" onclick="return confirm('Supprimer ce groupe ?')">❌</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
     <!-- ========================= MODALS COMMANDE GROUP ========================= -->   
    <div class="modal fade" id="groupCommandModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="groupCommandForm" class="modal-content">
                <input type="hidden" id="group_id">
                <div class="modal-header">
                    <h5 class="modal-title">Commande groupe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">               
                    <table class="table table-bordered align-middle">
                        <thead>
                            <tr>
                                <th width="60"></th>
                                <th>Paramètre</th>
                                <th>Valeur</th>
                            </tr>
                        </thead>
                        <tr>
                            <td><input class="form-check-input" type="checkbox" id="send_power_group"></td>
                            <td>Marche / Arrêt</td>
                            <td>
                                <select id="g_power" class="form-select">
                                    <option value=""></option>
                                    <option value="170">Marche</option>
                                    <option value="85">Arrêt</option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <td><input class="form-check-input" type="checkbox" id="send_mode_group"></td>
                            <td>Mode</td>
                            <td>
                                <select id="g_mode" class="form-select">
                                    <option value=""></option>
                                    <option value="1">Froid</option>
                                    <option value="2">Déshumidification</option>
                                    <option value="3">Ventilation</option>
                                    <option value="4">Chauffage</option>
                                    <option value="5">Auto</option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <td><input class="form-check-input" type="checkbox" id="send_setpoint_group"></td>
                            <td>Consigne</td>
                            <td>
                                <input id="g_setpoint" type="number" class="form-control" min="16" max="30" step="0.5">
                            </td>
                        </tr>

                        <tr>
                            <td><input class="form-check-input" type="checkbox" id="send_fan_group"></td>
                            <td>Ventilation</td>
                            <td>
                                <select id="g_fan" class="form-select">
                                    <option value=""></option>
                                    <option value="1">Auto</option>
                                    <option value="2">Faible</option>
                                    <option value="3">Moyen</option>
                                    <option value="4">Fort</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td><input class="form-check-input" type="checkbox" id="send_min_setpoint_group"></td>
                            <td>Limite consigne basse</td>
                            <td>
                                <input id="g_min_setpoint" type="number" class="form-control" min="16" max="30" step="0.5">
                            </td>
                        </tr>

                    </table>
                    <div class="card mt-3">
                        <div class="card-header p-0">
                            <button
                                class="btn btn-link text-decoration-none w-100 text-start p-3"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#shieldCollapse"
                                aria-expanded="false"
                                aria-controls="shieldCollapse">

                                <strong>Protections (Shield)</strong>
                            </button>
                        </div>

                        <div class="collapse" id="shieldCollapse">
                            <div class="card-body">

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="g_shield_energy">
                                    <label class="form-check-label" for="g_shield_energy">
                                        Shield Energy Saving
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="g_shield_setpoint">
                                    <label class="form-check-label" for="g_shield_setpoint">
                                        Shield Consigne
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="g_shield_mode">
                                    <label class="form-check-label" for="g_shield_mode">
                                        Shield Mode
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="g_shield_power">
                                    <label class="form-check-label" for="g_shield_power">
                                        Shield Marche / Arrêt
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="g_lock_function">
                                    <label class="form-check-label" for="g_lock_function">
                                        Verrouillage télécommande
                                    </label>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success">Envoyer groupe</button>
                </div>
            </form>
        </div>    
    </div>
  
    <!-- ========================= MODALS GROUP → EQUIP ========================= -->
    <?php foreach ($groups as $group): ?>
        <div class="modal fade" id="groupModal<?= $group['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Unités - <?= htmlspecialchars($group['name']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php foreach ($equipments as $equipment): ?>
                        <?php $checked = in_array($equipment['id'], $groupEquipments[$group['id']] ?? []); ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="equipments[<?= $group['id'] ?>][]" value="<?= $equipment['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                            <label class="form-check-label"><?= htmlspecialchars($equipment['name']) ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="save_groups" class="btn btn-success">Valider</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ========================= EQUIPMENTS ========================= -->
    <div class="card mb-4">
        <div class="card-header">
            <strong>Unités</strong>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                    <div class="d-flex justify-content-between mb-3">
                        <button type="submit" name="save_all" class="btn btn-success">
                            💾 Sauvegarder
                        </button>
                        <!-- <div class="d-flex gap-2">
                            <a href="export_equipments_json.php" class="btn btn-info">
                                📥 Exporter en JSON
                            </a>
                        </div> -->
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle" id="equipmentsTable">
                        <thead>
                            <tr>
                                <th>Localisation
                                    <div class="dropdown d-inline">
                                        <button 
                                            class="btn btn-sm btn-light"
                                            type="button"
                                            data-bs-toggle="dropdown">
                                            🔽
                                        </button>
                                        <ul class="dropdown-menu p-2" style="max-height:250px;overflow:auto">

                                            <?php foreach ($localisations as $loc): ?>
                                                <li>
                                                    <label class="dropdown-item">
                                                        <input 
                                                            type="checkbox"
                                                            class="form-check-input me-2 localisation-filter"
                                                            value="<?= htmlspecialchars($loc) ?>">
                                                        <?= htmlspecialchars($loc) ?>
                                                    </label>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </th>
                                <th data-sort="name" class="sortable">
                                    Nom <span>↕</span>
                                </th>

                                <th data-sort="ui" class="sortable">
                                    UI <span>↕</span>
                                </th>
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <th>Puissance</th>
                                    <th>IP</th>
                                    <th>Slave</th>
                                <?php endif; ?>
                                <th data-sort="state" class="sortable">
                                    État <span>↕</span>
                                </th>

                                <th data-sort="fault" class="sortable">
                                    Défaut <span>↕</span>
                                </th>

                                <th data-sort="temp" class="sortable">
                                    Temp reprise <span>↕</span>
                                </th>
                                <th>Groupes</th>
                                <th>Commandes</th>
                                <th>Historique</th>
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <th></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipments as $equipment): ?>
                            <tr>
                                <td data-localisation="<?= htmlspecialchars($equipment['localisation'] ?? '') ?>">
                                    <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                        <input 
                                            type="text"
                                            name="localisation[<?= $equipment['id'] ?>]"
                                            value="<?= htmlspecialchars($equipment['localisation'] ?? '') ?>"
                                            class="form-control">
                                    <?php else: ?>
                                        <?= htmlspecialchars($equipment['localisation'] ?? '') ?>
                                    <?php endif; ?>
                                </td>
                                <td data-sort="<?= htmlspecialchars($equipment['name']) ?>">
                                    <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                        <input 
                                            type="text"
                                            name="name[<?= $equipment['id'] ?>]"
                                            value="<?= htmlspecialchars($equipment['name']) ?>"
                                            class="form-control"
                                            oninput="this.parentElement.dataset.sort=this.value">
                                    <?php else: ?>
                                        <?= htmlspecialchars($equipment['name']) ?>
                                    <?php endif; ?>
                                </td>

                                
                                <td data-sort="<?= (int)$equipment['UI'] ?>">
                                    <?= htmlspecialchars($equipment['UI']) ?>
                                </td>
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <td>
                                        <?= is_numeric($equipment['power'])
                                            ? number_format($equipment['power']/10, 1) . ' kW'
                                            : htmlspecialchars($equipment['power']) ?>
                                    </td>
                                <?php endif; ?>
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <td><?= htmlspecialchars($equipment['ip']) ?></td>
                                    <td><?= htmlspecialchars($equipment['slave_id']) ?></td>
                                <?php endif; ?>
                                <td data-sort="<?= !empty($equipment['state']) ? 1 : 0 ?>">
                                    <?php if (!empty($equipment['state'])): ?>
                                        <span class="badge bg-success">ON</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">OFF</span>
                                    <?php endif; ?>
                                </td>
                                <td data-sort="<?= !empty($equipment['fault']) ? 1 : 0 ?>">
                                    <?php if (!empty($equipment['fault'])): ?>
                                        <span class="badge bg-danger blink">DÉFAUT</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">NORMAL</span>
                                    <?php endif; ?>
                                </td>
                                <td data-sort="<?= $equipment['return_temp'] ?? -999 ?>">
                                    <?= $equipment['return_temp'] !== null
                                        ? number_format($equipment['return_temp'], 1) . ' °C'
                                        : '-' ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#equipModal<?= $equipment['id'] ?>">
                                        Groupes
                                    </button>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-warning btn-sm commandButton"
                                        data-id="<?= $equipment['id'] ?>"
                                        data-ui="<?= $equipment['UI'] ?>"
                                        data-ip="<?= htmlspecialchars($equipment['ip']) ?>"
                                        data-port="<?= $equipment['port'] ?? 502 ?>"
                                        data-name="<?= htmlspecialchars($equipment['name']) ?>">
                                        Commande
                                    </button>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-info btn-sm historyButton"
                                        data-id="<?= $equipment['id'] ?>"
                                        data-name="<?= htmlspecialchars($equipment['name']) ?>">
                                        Historique
                                    </button>
                                </td>
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="id" value="<?= $equipment['id'] ?>">
                                            <button class="btn btn-danger btn-sm" name="delete_equipment" onclick="return confirm('Supprimer cet équipement ?')">❌</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================= MODALS EQUIP → GROUP ========================= -->
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
                        <?php $checked = in_array($group['id'], $equipmentGroups[$equipment['id']] ?? []); ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="groups[<?= $equipment['id'] ?>][]" value="<?= $group['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                            <label class="form-check-label"><?= htmlspecialchars($group['name']) ?></label>
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
    <!-- ========================= MODALS COMMANDE EQUIP ========================= -->
    <div class="modal fade" id="commandModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="commandForm">
                <input type="hidden" id="equipment_id" name="equipment_id">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            Commande unité
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <table class="table table-bordered align-middle">
                            <thead>
                                <tr>
                                    <th width="60"></th>
                                    <th>Paramètre</th>
                                    <th>Valeur</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><input class="form-check-input" type="checkbox" name="send_power"></td>
                                    <td>Marche / Arrêt</td>
                                    <td>
                                        <select class="form-select" id="power" name="power">
                                            <option value="170">Marche</option>
                                            <option value="85">Arrêt</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td><input class="form-check-input" type="checkbox" name="send_mode"></td>
                                    <td>Mode</td>
                                    <td>
                                        <select class="form-select" id="mode" name="mode">
                                            <option value="1">Froid</option>
                                            <option value="2">Déshumidification</option>
                                            <option value="3">Ventilation</option>
                                            <option value="4">Chauffage</option>
                                            <option value="5">Automatique</option>
                                            <option value="6">Plancher chauffant</option>
                                            <option value="7">Chauffage rapide</option>
                                            <option value="8">Heat Supply</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td><input class="form-check-input" type="checkbox" name="send_setpoint"></td>
                                    <td>Consigne</td>
                                    <td><input class="form-control" type="number" min="16" max="30" step="0.5" id="setpoint" name="setpoint"></td>
                                </tr>
                                <tr>
                                    <td><input class="form-check-input" type="checkbox" name="send_fan"> </td>
                                    <td>Vitesse ventilation</td>
                                    <td>
                                        <select class="form-select" id="fan" name="fan">
                                            <option value="1">Auto</option>
                                            <option value="2">Faible</option>
                                            <option value="3">Moyen faible</option>
                                            <option value="4">Moyen</option>
                                            <option value="5">Moyen fort</option>
                                            <option value="6">Fort</option>
                                            <option value="7">Turbo</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <input class="form-check-input" type="checkbox" name="send_min_setpoint">
                                    </td>
                                    <td>Limite basse consigne</td>
                                    <td>
                                        <input
                                            class="form-control"
                                            type="number"
                                            min="10"
                                            max="30"
                                            step="0.5"
                                            id="min_setpoint"
                                            name="min_setpoint"
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="card mt-3">
                            <div class="card-header">
                                <strong>Protections (Shield)</strong>
                            </div>

                            <div class="card-body">

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="shield_energy">
                                    <label class="form-check-label" for="shield_energy">
                                        Shield Energy Saving
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="shield_setpoint">
                                    <label class="form-check-label" for="shield_setpoint">
                                        Shield Consigne
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="shield_mode">
                                    <label class="form-check-label" for="shield_mode">
                                        Shield Mode
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="shield_power">
                                    <label class="form-check-label" for="shield_power">
                                        Shield Marche / Arrêt
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="lock_function">
                                    <label class="form-check-label" for="lock_function">
                                        Verrouillage télécommande
                                    </label>
                                </div>

                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success" data-id="<?= $equipment['id'] ?>">Envoyer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <!-- ========================= MODALS COURBES ========================= -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 id="historyTitle"></h5>

                    <button class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <canvas id="historyChart" height="650"></canvas>

                </div>

            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const equipModalEl = document.getElementById("commandModal");
        const equipModal = new bootstrap.Modal(equipModalEl);

        const groupModalEl = document.getElementById("groupCommandModal");
        const groupModal = new bootstrap.Modal(groupModalEl);

        let lastReadRegisters = [];
        let currentEquipmentId = null;
        let currentGroupId = null;

        /* =========================
        EQUIPMENT : OPEN + READ
        ========================= */
        document.querySelectorAll(".commandButton").forEach(button => {
            button.addEventListener("click", async () => {

                const id = button.dataset.id;
                currentEquipmentId = id;

                document.getElementById("equipment_id").value = id;

                try {
                    const res = await fetch(`/api/modbus_proxy.php?id=${id}`);
                    const data = await res.json();

                    if (!data.success) {
                        throw new Error(data.error || "Modbus read error");
                    }

                    const regs = Array.isArray(data.registers) ? data.registers : [];
                    const shields = Array.isArray(data.coils) ? data.coils : [];
                    // reset checkboxes
                    document.querySelectorAll("#commandForm input[type=checkbox]")
                        .forEach(c => c.checked = false);

                    document.getElementById("shield_energy").checked  = !!shields[0];
                    document.getElementById("shield_setpoint").checked = !!shields[1];
                    document.getElementById("shield_mode").checked     = !!shields[2];
                    document.getElementById("shield_power").checked    = !!shields[3];
                    document.getElementById("lock_function").checked   = !!shields[4];

                    lastReadRegisters = regs.map(v =>
                        (v === null || v === undefined || isNaN(v)) ? 0 : Number(v)
                    );

                    while (lastReadRegisters.length < 4) {
                        lastReadRegisters.push(0);
                    }

                    // UI
                    document.getElementById("power").value = lastReadRegisters[0];
                    document.getElementById("mode").value = lastReadRegisters[1];
                    document.getElementById("setpoint").value = lastReadRegisters[2] / 10;
                    document.getElementById("fan").value = lastReadRegisters[3];
                    document.getElementById("min_setpoint").value = lastReadRegisters[4] / 10;

                    

                    equipModal.show();

                } catch (e) {
                    console.error(e);
                    alert("Erreur lecture équipement");
                }
            });
        });


        /* =========================
        EQUIPMENT : WRITE
        ========================= */
        document.getElementById("commandForm").addEventListener("submit", async (e) => {
            e.preventDefault();

            const id = currentEquipmentId || document.getElementById("equipment_id").value;

            if (!id) {
                alert("ID équipement manquant");
                return;
            }

            const regs = [...lastReadRegisters];

            for (let i = 0; i < 5; i++) {
                if (regs[i] === undefined || regs[i] === null || isNaN(regs[i])) {
                    regs[i] = 0;
                }
            }

            if (document.querySelector('[name="send_power"]').checked) {
                regs[0] = parseInt(document.getElementById("power").value) || 0;
            }

            if (document.querySelector('[name="send_mode"]').checked) {
                regs[1] = parseInt(document.getElementById("mode").value) || 0;
            }

            if (document.querySelector('[name="send_setpoint"]').checked) {
                const sp = parseFloat(document.getElementById("setpoint").value);
                regs[2] = isNaN(sp) ? 0 : Math.round(sp * 10);
            }

            if (document.querySelector('[name="send_fan"]').checked) {
                regs[3] = parseInt(document.getElementById("fan").value) || 0;
            }
            if (document.querySelector('[name="send_min_setpoint"]').checked) {
                const minSp = parseFloat(document.getElementById("min_setpoint").value);
                regs[4] = isNaN(minSp) ? 0 : Math.round(minSp * 10);
            }

            const shields = [
                document.getElementById("shield_energy").checked,
                document.getElementById("shield_setpoint").checked,
                document.getElementById("shield_mode").checked,
                document.getElementById("shield_power").checked,
                document.getElementById("lock_function").checked
            ];

            try {
                const res = await fetch(`/api/modbus_proxy.php?action=write&id=${id}`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ registers: regs, shields: shields })
                });

                const data = await res.json();

                if (!data.success) throw new Error(data.error || "Write failed");

                alert("Commande envoyée");
                equipModal.hide();

            } catch (err) {
                console.error(err);
                alert("Erreur écriture Modbus");
            }
        });


        /* =========================
        GROUP : OPEN MODAL
        ========================= */
        document.querySelectorAll(".groupCommandButton").forEach(btn => {
            btn.addEventListener("click", () => {

                currentGroupId = btn.dataset.id;
                document.getElementById("group_id").value = currentGroupId;

                // RESET CHECKBOX GROUP
                document.querySelectorAll("#groupCommandForm input[type=checkbox]")
                    .forEach(c => c.checked = false);

                document.querySelectorAll("#groupCommandForm select")
                    .forEach(s => s.value = "");

                document.querySelectorAll("#groupCommandForm input[type=number]")
                    .forEach(i => i.value = "");
                // RESET INPUTS OPTIONNELS
                document.getElementById("g_setpoint").value = "24";
                document.getElementById("g_min_setpoint").value = "24";

                groupModal.show();
            });
        });


        /* =========================
        GROUP : WRITE (BROADCAST)
        ========================= */
        document.getElementById("groupCommandForm").addEventListener("submit", async (e) => {
            e.preventDefault();

            document.getElementById("groupCommandModal").addEventListener("show.bs.modal", () => {
                const shieldCollapse = document.getElementById("shieldCollapse");
                bootstrap.Collapse.getOrCreateInstance(shieldCollapse, {
                    toggle: false
                }).hide();
            });
            if (!currentGroupId) {
                alert("Groupe manquant");
                return;
            }

            const registers = {};

            if (document.getElementById("send_power_group").checked) {
                const v = document.getElementById("g_power").value;
                if (v !== "") registers.power = parseInt(v);
            }

            if (document.getElementById("send_mode_group").checked) {
                const v = document.getElementById("g_mode").value;
                if (v !== "") registers.mode = parseInt(v);
            }

            if (document.getElementById("send_setpoint_group").checked) {
                const v = document.getElementById("g_setpoint").value;
                if (v !== "") registers.setpoint = Math.round(parseFloat(v));
            }

            if (document.getElementById("send_fan_group").checked) {
                const v = document.getElementById("g_fan").value;
                if (v !== "") registers.fan = parseInt(v);
            }
            if (document.getElementById("send_min_setpoint_group").checked) {
                const v = document.getElementById("g_min_setpoint").value;
                if (v !== "") registers.min_setpoint = Math.round(parseFloat(v));
            }

            const payload = {
                group_id: currentGroupId,
                registers: registers
            };

            const shieldCollapse = document.getElementById("shieldCollapse");

            if (shieldCollapse.classList.contains("show")) {
                payload.shields = [
                    document.getElementById("g_shield_energy").checked,
                    document.getElementById("g_shield_setpoint").checked,
                    document.getElementById("g_shield_mode").checked,
                    document.getElementById("g_shield_power").checked,
                    document.getElementById("g_lock_function").checked
                ];
            }
            
            try {
                const res = await fetch("/api/modbus_group_proxy.php?action=write_group", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(payload)
                });

                const text = await res.text();

                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error("RAW RESPONSE:", text);
                    throw new Error("Invalid JSON from server");
                }

                if (!data.success) throw new Error(data.error || "Group write failed");

                alert("Commande groupe envoyée");
                groupModal.hide();

            } catch (err) {
                console.error(err);
                alert("Erreur commande groupe");
            }
        });

        //script modal courbe
        let historyChart = null;

        function toUTCPlus11(dateStr) {
            // équipement DB en UTC -> conversion locale +11
            const d = new Date(dateStr);
            return new Date(d.getTime() + 11 * 60 * 60 * 1000);
        }

        document.querySelectorAll(".historyButton").forEach(button => {
            button.addEventListener("click", async function () {

                try {
                    const id = this.dataset.id;
                    const name = this.dataset.name;

                    document.getElementById("historyTitle").textContent = name;

                    const response = await fetch("equipment_history.php?id=" + id);

                    if (!response.ok) {
                        throw new Error("HTTP " + response.status);
                    }

                    const data = await response.json();

                    if (!Array.isArray(data)) {
                        console.error("API invalid:", data);
                        return;
                    }

                    const labels = data.map(p => toUTCPlus11(p.created_at));

                    const retour = data.map(p => p.return_temp);
                    const consigne = data.map(p => p.setpoint);
                    const ext = data.map(p => p.outside_temp);

                    // ON/OFF (0/10 demandé)
                    const state = data.map(p => (p.state ? 10 : 0));

                    const modalEl = document.getElementById("historyModal");
                    const canvas = document.getElementById("historyChart");

                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

                    modal.show();

                    modalEl.addEventListener("shown.bs.modal", function handler() {

                        modalEl.removeEventListener("shown.bs.modal", handler);

                        if (historyChart) {
                            historyChart.destroy();
                        }

                        const ctx = canvas.getContext("2d");

                        historyChart = new Chart(ctx, {
                            type: "line",
                            data: {
                                labels,
                                datasets: [
                                    {
                                        label: "Retour",
                                        data: retour,
                                        borderColor: "#0d6efd",
                                        tension: 0.35,
                                        pointRadius: 0,
                                        yAxisID: "y"
                                    },
                                    {
                                        label: "Consigne",
                                        data: consigne,
                                        borderColor: "#198754",
                                        tension: 0.35,
                                        pointRadius: 0,
                                        yAxisID: "y"
                                    },
                                    {
                                        label: "Extérieur",
                                        data: ext,
                                        borderColor: "#fd7e14",
                                        tension: 0.35,
                                        pointRadius: 0,
                                        yAxisID: "y"
                                    },
                                    {
                                        label: "ON/OFF",
                                        data: state,
                                        borderColor: "#dc3545",
                                        stepped: true,
                                        pointRadius: 0,
                                        yAxisID: "yState"
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,

                                interaction: {
                                    mode: "index",
                                    intersect: false
                                },

                                plugins: {
                                    legend: { position: "top" },

                                    zoom: {
                                        pan: {
                                            enabled: true,
                                            mode: "x"
                                        },
                                        zoom: {
                                            wheel: { enabled: true },
                                            pinch: { enabled: true },
                                            mode: "x"
                                        }
                                    }
                                },

                                scales: {
                                    x: {
                                        type: "time",
                                        time: {
                                            tooltipFormat: "dd/MM/yyyy HH:mm",
                                            displayFormats: {
                                                minute: "HH:mm",
                                                hour: "dd/MM HH:mm"
                                            }
                                        },
                                        ticks: {
                                            source: "auto"
                                        }
                                    },

                                    y: {
                                        min: 0,
                                        max: 35,
                                        title: {
                                            display: true,
                                            text: "Température (°C)"
                                        }
                                    },

                                    yState: {
                                        position: "right",
                                        min: 0,
                                        max: 10,
                                        ticks: {
                                            stepSize: 10,
                                            callback: v => v === 10 ? "ON" : "OFF"
                                        },
                                        grid: {
                                            drawOnChartArea: false
                                        }
                                    }
                                }
                            }
                        });
                    }, { once: true });

                } catch (err) {
                    console.error("ERROR:", err);
                }
            });
        });
        /* =========================
        TRI TABLE UNITÉS
        ========================= */

        const sortDirections = {};

        document.querySelectorAll("#equipmentsTable th.sortable").forEach(th => {

            th.addEventListener("click", function () {

                const table = document.getElementById("equipmentsTable");
                const tbody = table.querySelector("tbody");

                const key = this.dataset.sort;

                // position réelle de la colonne cliquée
                const colIndex = Array.from(
                    this.parentElement.children
                ).indexOf(this);

                sortDirections[key] = !sortDirections[key];


                const rows = Array.from(tbody.querySelectorAll("tr"));

                rows.sort((a, b) => {

                    let cellA = a.cells[colIndex];
                    let cellB = b.cells[colIndex];

                    let valA = "";
                    let valB = "";


                    // Cas Nom avec input admin
                    const inputA = cellA.querySelector("input");
                    const inputB = cellB.querySelector("input");

                    if (inputA) {
                        valA = inputA.value;
                    } else {
                        valA = cellA.innerText;
                    }

                    if (inputB) {
                        valB = inputB.value;
                    } else {
                        valB = cellB.innerText;
                    }


                    valA = valA.trim();
                    valB = valB.trim();


                    // Colonnes numériques
                    if (["ui", "temp"].includes(key)) {

                        let numA = parseFloat(valA.replace(",", "."));
                        let numB = parseFloat(valB.replace(",", "."));

                        numA = isNaN(numA) ? -9999 : numA;
                        numB = isNaN(numB) ? -9999 : numB;

                        return sortDirections[key]
                            ? numB - numA
                            : numA - numB;
                    }


                    return sortDirections[key]
                        ? valB.localeCompare(valA, "fr")
                        : valA.localeCompare(valB, "fr");

                });


                rows.forEach(row => tbody.appendChild(row));


                // Mise à jour icônes
                document.querySelectorAll("#equipmentsTable th.sortable span")
                    .forEach(span => span.textContent = "↕");

                this.querySelector("span").textContent =
                    sortDirections[key] ? "↓" : "↑";

            });

        });
        /* =========================
        FILTRE TABLE UNITÉS
        ========================= */
        document.querySelectorAll(".localisation-filter").forEach(cb => {
            cb.addEventListener("change", function () {
                const selected = Array.from(
                    document.querySelectorAll(".localisation-filter:checked")
                )
                .map(cb => cb.value.toLowerCase());

                document
                    .querySelectorAll("#equipmentsTable tbody tr")
                    .forEach(row => {
                        const localisation = row.cells[0]
                            .dataset.localisation
                            .toLowerCase();

                        if (
                            selected.length === 0 ||
                            selected.includes(localisation)
                        ) {
                            row.style.display = "";
                        } else {
                            row.style.display = "none";
                        }
                    });
            });
        });
    </script>
</body>
</html>
