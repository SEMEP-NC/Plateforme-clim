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
    <h1>Équipements</h1>
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
                        <th>Actions</th>
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

                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle" id="equipmentsTable">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>UI</th>
                                <th>Puissance</th>
                                <th>IP</th>
                                <th>Slave</th>
                                <th>Groupes</th>
                                <th>Commandes</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipments as $equipment): ?>
                            <tr>
                                <td>
                                    <input type="text" name="name[<?= $equipment['id'] ?>]" value="<?= htmlspecialchars($equipment['name']) ?>" class="form-control">
                                </td>
                                <td><?= htmlspecialchars($equipment['UI']) ?></td>
                                <td>
                                    <?= is_numeric($equipment['power'])
                                        ? number_format($equipment['power']/10, 1) . ' kW'
                                        : htmlspecialchars($equipment['power']) ?>
                                </td>
                                <td><?= htmlspecialchars($equipment['ip']) ?></td>
                                <td><?= htmlspecialchars($equipment['slave_id']) ?></td>
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
                                    <form method="POST">
                                        <input type="hidden" name="id" value="<?= $equipment['id'] ?>">
                                        <button class="btn btn-danger btn-sm" name="delete_equipment" onclick="return confirm('Supprimer cet équipement ?')">❌</button>
                                    </form>
                                </td>
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
        const modalEl = document.getElementById("commandModal");
        const modal = new bootstrap.Modal(modalEl);

        let lastReadRegisters = [];
        let currentEquipmentId = null;

        /* =========================
        READ + OPEN MODAL
        ========================= */
        document.querySelectorAll(".commandButton").forEach(button => {
            button.addEventListener("click", async () => {

                const id = button.dataset.id;
                currentEquipmentId = id;

                document.getElementById("equipment_id").value = id;

                try {
                    const res = await fetch(`/api/modbus_proxy.php?id=${id}`);
                    const data = await res.json();

                    if (!data.success) throw new Error("Modbus read error");

                    const regs = Array.isArray(data.registers) ? data.registers : [];

                    // sécurité anti None/undefined
                    lastReadRegisters = regs.map(v =>
                        (v === null || v === undefined || isNaN(v)) ? 0 : Number(v)
                    );

                    // fallback si pas 4 registres
                    while (lastReadRegisters.length < 4) {
                        lastReadRegisters.push(0);
                    }

                    // UI update
                    document.getElementById("power").value = lastReadRegisters[0];
                    document.getElementById("mode").value = lastReadRegisters[1];
                    document.getElementById("setpoint").value = lastReadRegisters[2] / 10;
                    document.getElementById("fan").value = lastReadRegisters[3];

                    // reset checkboxes
                    document.querySelectorAll("#commandForm input[type=checkbox]")
                        .forEach(c => c.checked = false);

                    modal.show();

                } catch (e) {
                    console.error(e);
                    alert("Erreur lecture équipement");
                }
            });
        });


        /* =========================
        WRITE (READ-MODIFY-WRITE)
        ========================= */
        document.getElementById("commandForm").addEventListener("submit", async (e) => {
            e.preventDefault();

            const id = currentEquipmentId || document.getElementById("equipment_id").value;

            if (!id) {
                alert("ID équipement manquant");
                return;
            }

            // clone sécurisé
            const regs = [...lastReadRegisters];

            // fallback sécurité
            for (let i = 0; i < 4; i++) {
                if (regs[i] === undefined || regs[i] === null || isNaN(regs[i])) {
                    regs[i] = 0;
                }
            }

            // POWER
            if (document.querySelector('[name="send_power"]').checked) {
                regs[0] = parseInt(document.getElementById("power").value) || 0;
            }

            // MODE
            if (document.querySelector('[name="send_mode"]').checked) {
                regs[1] = parseInt(document.getElementById("mode").value) || 0;
            }

            // SETPOINT (x10 Modbus)
            if (document.querySelector('[name="send_setpoint"]').checked) {
                const sp = parseFloat(document.getElementById("setpoint").value);
                regs[2] = isNaN(sp) ? 0 : Math.round(sp * 10);
            }

            // FAN
            if (document.querySelector('[name="send_fan"]').checked) {
                regs[3] = parseInt(document.getElementById("fan").value) || 0;
            }

            try {
                
                const res = await fetch(`/api/modbus_proxy.php?action=write&id=${id}`, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        registers: regs
                    })
                });

                const data = await res.json();

                if (!data.success) throw new Error(data.error || "Write failed");

                alert("Commande envoyée");
                modal.hide();

            } catch (err) {
                console.error(err);
                alert("Erreur écriture Modbus");
            }
        });
    </script>
</body>
</html>
