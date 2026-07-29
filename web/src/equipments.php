<?php
require 'auth.php';
require_login();
require 'config/db.php';
require 'lib/audit.php';

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

$settings_rows = $db->query("
    SELECT * FROM settings
")->fetchAll(PDO::FETCH_ASSOC);
$settings = [];

foreach ($settings_rows as $row) {
    $settings[$row['key']] = $row['value'];
}

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
function getGateStatusLabel($status)
{
    return match((int)$status) {
        0 => "Invalid",
        1 => "Sans contrôle",
        2 => "Autorisation",
        3 => "Interdiction",
        default => "-"
    };
}
function getGateStatusBadge($status)
{
    return match((int)$status) {
        0 => "secondary",
        1 => "success",
        2 => "warning",
        3 => "danger",
        default => "dark"
    };
}
/*
|--------------------------------------------------------------------------
| CREATE GROUP
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    verify_csrf();
    $name = trim($_POST['group_name']);
    if ($name !== '') {
        $db->prepare("INSERT INTO groups_hvac(name) VALUES (?)")->execute([$name]);
        audit(
            'CREATE_GROUP',
            "Création du groupe '$name'",
            'group',
            $db->lastInsertId()
        );
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
    verify_csrf();
    $id = (int)$_POST['group_id'];
    // Récupération du nom avant suppression
    $stmt = $db->prepare("SELECT name FROM groups_hvac WHERE id = ?");
    $stmt->execute([$id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($group) {
        // Suppression des relations
        $db->prepare("
            DELETE FROM equipment_groups 
            WHERE group_id = ?
        ")->execute([$id]);
        // Suppression du groupe
        $db->prepare("
            DELETE FROM groups_hvac 
            WHERE id = ?
        ")->execute([$id]);
        // Log audit
        audit(
            'DELETE_GROUP',
            "Suppression du groupe '{$group['name']}'",
            'group',
            $id
        );
    }
    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| SAVE GROUPS EQUIPMENT (MODAL GROUPS)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_groups']) && isset($_POST['equipments'])) {
    verify_csrf();
    foreach ($_POST['equipments'] as $groupId => $equipmentIds) {
        $groupId = (int)$groupId;
        $db->prepare("DELETE FROM equipment_groups WHERE group_id = ?")->execute([$groupId]);
        foreach ((array)$equipmentIds as $equipmentId) {
            $db->prepare("INSERT INTO equipment_groups (equipment_id, group_id) VALUES (?, ?)")
                ->execute([(int)$equipmentId, $groupId]);
        }
        $stmt = $db->prepare("SELECT name FROM groups_hvac WHERE id=?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        audit(
            'UPDATE_GROUP',
            "Modification des unités du groupe '{$group['name']}'",
            'group',
            $groupId
        );
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
    verify_csrf();
    foreach ($_POST['groups'] as $equipmentId => $groupIds) {
        $equipmentId = (int)$equipmentId;
        $db->prepare("DELETE FROM equipment_groups WHERE equipment_id = ?")->execute([$equipmentId]);
        foreach ((array)$groupIds as $groupId) {
            $db->prepare("INSERT INTO equipment_groups (equipment_id, group_id) VALUES (?, ?)")
                ->execute([$equipmentId, (int)$groupId]);
        }
        $stmt = $db->prepare("SELECT name FROM equipments WHERE id=?");
        $stmt->execute([$equipmentId]);
        $equipment = $stmt->fetch(PDO::FETCH_ASSOC);

        audit(
            'UPDATE_EQUIPMENT_GROUPS',
            "Modification des groupes de l'unité '{$equipment['name']}'",
            'equipment',
            $equipmentId
        );
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
    verify_csrf();

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
    audit(
        'MODIF_EQUIPEMENT',
        'Modification des libellés / localisation equipements'
    );
    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| DELETE EQUIPMENT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_equipment'])) {
    verify_csrf();
    $id = (int)$_POST['id'];
    // Récupération des informations avant suppression
    $stmt = $db->prepare("
        SELECT name 
        FROM equipments 
        WHERE id=?
    ");
    $stmt->execute([$id]);
    $equipment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($equipment) {
        // Suppression après récupération du nom
        $stmt = $db->prepare("
            DELETE FROM equipments 
            WHERE id=?
        ");
        $stmt->execute([$id]);
        audit(
            'DELETE_EQUIPMENT',
            "Suppression de '{$equipment['name']}'",
            'equipment',
            $id
        );
    }
    header("Location: equipments.php");
    exit;
}
    $page_title = "Gestion des équipements";
    require "includes/header.php";
    require "includes/user_menu.php";
?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <style>
        .page-title {
            font-size:2rem;
        }

        .card {
            border:none;
            border-radius:15px;
            box-shadow:0 4px 15px rgba(0,0,0,.08);
        }

        .sortable {
            cursor:pointer;
            user-select:none;
        }

        .sortable:hover {
            background:#eef5ff;
        }
    </style>
    <main class="container flex-grow-1 mt-4">
        <!-- ========================= GROUPES ========================= -->
        <div class="card mb-4">
            <div class="card-header"><strong>Groupes</strong></div>
            <div class="card-body">
                <form method="POST" class="row g-2 mb-3">
                    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
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
                            <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                <th></th>
                            <?php endif; ?>
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
                            <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
                                        <input type="hidden" name="group_id" value="<?= $group['id'] ?>">
                                        <button class="btn btn-danger btn-sm" name="delete_group" onclick="return confirm('Supprimer ce groupe ?')">❌</button>
                                    </form>
                                </td>
                            <?php endif; ?>
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
                    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
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

                                    <strong>Fonctions sur commande filaire</strong>
                                </button>
                            </div>

                            <div class="collapse" id="shieldCollapse">
                                <div class="card-body">

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="g_shield_energy">
                                        <label class="form-check-label" for="g_shield_energy">
                                            Mode economie d'énergie
                                        </label>
                                    </div>

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="g_shield_setpoint">
                                        <label class="form-check-label" for="g_shield_setpoint">
                                            Interdiction modification Consigne
                                        </label>
                                    </div>

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="g_shield_mode">
                                        <label class="form-check-label" for="g_shield_mode">
                                            Interdiction modification Mode
                                        </label>
                                    </div>

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="g_shield_power">
                                        <label class="form-check-label" for="g_shield_power">
                                            Interdiction modification Marche / Arrêt
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
                        <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Unités - <?= htmlspecialchars($group['name']) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Filtre localisation -->
                            <div class="mb-3">
                                <label class="form-label">
                                    Filtrer par localisation
                                </label>
                                <select 
                                    class="form-select group-localisation-filter"
                                    data-group="<?= $group['id'] ?>">
                                    <option value="">Toutes les localisations</option>
                                    <?php foreach ($localisations as $loc): ?>
                                        <option value="<?= htmlspecialchars($loc) ?>">
                                            <?= htmlspecialchars($loc) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Liste équipements -->
                            <div 
                                class="group-equipment-list"
                                data-group="<?= $group['id'] ?>">
                                <?php foreach ($equipments as $equipment): ?>
                                    <?php 
                                    $checked = in_array(
                                        $equipment['id'], 
                                        $groupEquipments[$group['id']] ?? []
                                    ); 
                                    ?>
                                    <div 
                                        class="form-check group-equipment-item"
                                        data-localisation="<?= htmlspecialchars($equipment['localisation'] ?? '') ?>">
                                        <input 
                                            class="form-check-input"
                                            type="checkbox"
                                            name="equipments[<?= $group['id'] ?>][]"
                                            value="<?= $equipment['id'] ?>"
                                            <?= $checked ? 'checked' : '' ?>>
                                        <label class="form-check-label">
                                            <?= htmlspecialchars($equipment['name']) ?>
                                            <small class="text-muted">
                                                (<?= htmlspecialchars($equipment['localisation']) ?>)
                                            </small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
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
                    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
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
                                                class="btn btn-sm"
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
                                    <?php if (!empty($settings['read_gate_status'])): ?>
                                        <th data-sort="gate" class="sortable">
                                            Contrôle externe <span>↕</span>
                                        </th>
                                    <?php endif; ?>
                                    <th data-sort="temp" class="sortable">
                                        Temp ambiance <span>↕</span>
                                    </th>
                                    <th>
                                        Groupes
                                        <div class="dropdown d-inline">
                                            <button 
                                                class="btn btn-sm"
                                                type="button"
                                                data-bs-toggle="dropdown">
                                                🔽
                                            </button>
                                            <ul class="dropdown-menu p-2" style="max-height:250px;overflow:auto">
                                                <li>
                                                    <label class="dropdown-item">
                                                        <input 
                                                            type="radio"
                                                            name="group-filter"
                                                            class="form-check-input me-2 group-filter"
                                                            value=""
                                                            checked>
                                                        Tous
                                                    </label>
                                                </li>
                                                <?php foreach ($groups as $group): ?>
                                                    <li>
                                                        <label class="dropdown-item">
                                                            <input 
                                                                type="radio"
                                                                name="group-filter"
                                                                class="form-check-input me-2 group-filter"
                                                                value="<?= $group['id'] ?>">
                                                            <?= htmlspecialchars($group['name']) ?>
                                                        </label>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </th>
                                    <th>Commandes</th>
                                    <th>Historique</th>
                                    <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                        <th></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($equipments as $equipment): ?>
                                <?php
                                    $equipmentGroups = [];
                                    foreach ($groupEquipments as $groupId => $equipIds) {
                                        if(in_array($equipment['id'], $equipIds)){
                                            $equipmentGroups[] = $groupId;
                                        }
                                    }
                                ?>
                                <tr data-groups="<?= implode(',', $equipmentGroups) ?>">
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
                                    <?php if (!empty($settings['read_gate_status'])): ?>
                                        <td>
                                            <span class="badge bg-<?= getGateStatusBadge($equipment['gate_status']) ?>">
                                                <?= htmlspecialchars(getGateStatusLabel($equipment['gate_status'])) ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
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
                                                <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
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
                        <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
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
                    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
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
                                    <strong>Fonctions sur commande filaire</strong>
                                </div>

                                <div class="card-body">

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="shield_energy">
                                        <label class="form-check-label" for="shield_energy">
                                            Mode economie d'énergie
                                        </label>
                                    </div>

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="shield_setpoint">
                                        <label class="form-check-label" for="shield_setpoint">
                                            Interdiction modification Consigne
                                        </label>
                                    </div>

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="shield_mode">
                                        <label class="form-check-label" for="shield_mode">
                                            Interdiction modification Mode
                                        </label>
                                    </div>

                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="shield_power">
                                        <label class="form-check-label" for="shield_power">
                                            Interdiction modification Marche / Arrêt
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
    </main>
    
   <script src="assets/js/equipments.js" defer></script>

<?php require "includes/footer.php"; ?>
