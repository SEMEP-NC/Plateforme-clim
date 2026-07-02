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
    foreach ($_POST['name'] as $id => $name) {
        $db->prepare("UPDATE equipments SET name=? WHERE id=?")->execute([trim($name), (int)$id]);
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

<body class="container mt-5">
    <div class="text-center mt-4">
        <h1 class="fw-bold">Équipements</h1>
    </div>
    <a href="index.php" class="btn btn-secondary mb-3">Retour</a>

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

                    </table>
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
                        <div class="d-flex gap-2">
                            <a href="export_equipments_json.php" class="btn btn-info">
                                📥 Exporter en JSON
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle" id="equipmentsTable">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>UI</th>
                                <th>Puissance</th>
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <th>IP</th>
                                    <th>Slave</th>
                                <?php endif; ?>
                                <th>Groupes</th>
                                <th>Commandes</th>
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <th></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipments as $equipment): ?>
                            <tr>
                                <td>
                                    <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                        <input type="text" name="name[<?= $equipment['id'] ?>]" value="
                                    <?php endif; ?>
                                    <?= htmlspecialchars($equipment['name']) ?>
                                    <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                        " class="form-control">
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($equipment['UI']) ?></td>
                                <td>
                                    <?= is_numeric($equipment['power'])
                                        ? number_format($equipment['power']/10, 1) . ' kW'
                                        : htmlspecialchars($equipment['power']) ?>
                                </td>
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <td><?= htmlspecialchars($equipment['ip']) ?></td>
                                    <td><?= htmlspecialchars($equipment['slave_id']) ?></td>
                                <?php endif; ?>
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
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success" data-id="<?= $equipment['id'] ?>">Envoyer</button>
                    </div>
                </div>
            </form>
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

                    // reset checkboxes
                    document.querySelectorAll("#commandForm input[type=checkbox]")
                        .forEach(c => c.checked = false);

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

            try {
                const res = await fetch(`/api/modbus_proxy.php?action=write&id=${id}`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ registers: regs })
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

                groupModal.show();
            });
        });


        /* =========================
        GROUP : WRITE (BROADCAST)
        ========================= */
        document.getElementById("groupCommandForm").addEventListener("submit", async (e) => {
            e.preventDefault();

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

            const payload = {
                group_id: currentGroupId,
                registers: registers
            };

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
    </script>
</body>
</html>
