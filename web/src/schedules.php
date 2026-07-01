<?php

    require 'config/db.php';

    $pdo = get_db();

    $dayLabels = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    /*
    |--------------------------------------------------------------------------
    | SCHEDULE LIST
    |--------------------------------------------------------------------------
    */

    $schedules = $pdo->query("
        SELECT
            schedules.*,
            equipments.name AS equipment_name,
            groups_hvac.name AS group_name
        FROM schedules
        LEFT JOIN equipments
            ON equipments.id = schedules.equipment_id
        LEFT JOIN groups_hvac
            ON groups_hvac.id = schedules.group_id
        ORDER BY schedules.execution_time ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | EQUIPMENTS LIST
    |--------------------------------------------------------------------------
    */

    $equipments = $pdo->query("
        SELECT id, name
        FROM equipments
        WHERE enabled = 1
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | GROUPS LIST
    |--------------------------------------------------------------------------
    */

    $groups = $pdo->query("
        SELECT id, name
        FROM groups_hvac
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    function format_repeat_days($value, $dayLabels) {
        if (!$value) {
            return 'Non';
        }

        $labels = [];

        foreach (explode(',', $value) as $day) {
            $day = (int)trim($day);
            if (isset($dayLabels[$day])) {
                $labels[] = $dayLabels[$day];
            }
        }

        return $labels ? implode(', ', $labels) : 'Non';
    }

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Planning HVAC</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<body class="container mt-5">

<h1 class="mb-4">Planning HVAC</h1>

<a href="index.php" class="btn btn-secondary mb-3">
    Retour
</a>
<table>
 
    
<!-- =========================
     FORMULAIRE AJOUT
========================= -->
    <td style="vertical-align: top;">
        <form method="POST" action="save_schedule.php" class="card p-4 mb-5">

            <h4 class="mb-3">Ajouter un planning</h4>

            <label class="form-label">Cible</label>

            <select name="equipment_id" class="form-control mb-3">
                <option value="">— Aucun équipement —</option>
                <?php foreach ($equipments as $equipment): ?>
                    <option value="<?= (int)$equipment['id'] ?>">
                        Équipement : <?= htmlspecialchars($equipment['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="group_id" class="form-control mb-3">
                <option value="">— Aucun groupe —</option>
                <?php foreach ($groups as $group): ?>
                    <option value="<?= (int)$group['id'] ?>">
                        Groupe : <?= htmlspecialchars($group['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>


            <small class="text-muted">
                Choisir soit un équipement, soit un groupe (pas les deux).
            </small>

            <label class="form-label">Action</label>
                <select name="action" class="form-control mb-3">
                    <option value="">Aucun changement</option>
                    <option value="ON">ON</option>
                    <option value="OFF">OFF</option>
                </select>

            <label class="form-label">Température (°C)</label>
                <select name="temperature" class="form-control mb-3">

                    <option value="">
                        Aucun changement
                    </option>

                    <?php for ($t = 16; $t <= 30; $t++): ?>

                        <option value="<?= $t ?>">
                            <?= $t ?> °C
                        </option>

                    <?php endfor; ?>

                </select>

            <label class="form-label">Premiere execution (heure locale UTC+11)</label>
            <input type="datetime-local" name="execution_time" class="form-control mb-3" required>

            <label class="form-label">Repeter chaque semaine</label>
            <div class="row mb-3">
                <?php foreach ($dayLabels as $day => $label): ?>
                    <div class="col-md-3 col-sm-6">
                        <div class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="repeat_days[]"
                                value="<?= $day ?>"
                                id="repeat_day_<?= $day ?>"
                            >
                            <label class="form-check-label" for="repeat_day_<?= $day ?>">
                                <?= htmlspecialchars($label) ?>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <button class="btn btn-success">
                Ajouter Planning
            </button>

        </form>
    </td>
    <td style="width:20px;"></td>
<!-- =========================
     TABLEAU
========================= -->
    <td style="vertical-align: top;">
        <table class="table table-bordered table-striped align-middle">

            <thead class="table-dark">
                <tr>
                    <th>Actif</th>
                    <th>Équipement</th>
                    <th>Action</th>
                    <th>Température</th>
                    <th>Prochaine execution</th>
                    <th>Repetition</th>
                    <th>Exécuté</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($schedules as $schedule): ?>
                    <tr>
                        <td>
                            <form method="POST" action="toggle_schedule.php">
                                <input type="hidden" name="id" value="<?= $schedule['id'] ?>">
                                <button class="btn btn-sm <?= $schedule['enabled'] ? 'btn-success' : 'btn-secondary' ?>">
                                    <?= $schedule['enabled'] ? 'Actif' : 'Inactif' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <?php if (!empty($schedule['group_name'])): ?>
                                <span class="badge bg-primary">
                                    Groupe : <?= htmlspecialchars($schedule['group_name']) ?>
                                </span>
                            <?php elseif (!empty($schedule['equipment_name'])): ?>
                                <span class="badge bg-secondary">
                                    Équipement : <?= htmlspecialchars($schedule['equipment_name']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($schedule['action'] === 'ON'): ?>
                                <span class="badge bg-success">
                                    ON
                                </span>
                            <?php elseif ($schedule['action'] === 'OFF'): ?>
                                <span class="badge bg-danger">
                                    OFF
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    Aucun changement
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($schedule['temperature'] !== null): ?>
                                <?= htmlspecialchars($schedule['temperature']) ?> °C
                            <?php else: ?>
                                <span class="text-muted">
                                    Aucun changement
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                                $dt = (new DateTime($schedule['execution_time'], new DateTimeZone('UTC')))
                                    ->setTimezone(new DateTimeZone('+11:00'));
                            ?>
                            <?= $dt->format('Y-m-d H:i') ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                format_repeat_days(
                                    $schedule['repeat_days'] ?? '',
                                    $dayLabels
                                )
                            ) ?>
                        </td>
                        <td>
                            <?php if (!empty($schedule['executed'])): ?>
                                <span class="badge bg-success">Oui</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Non</span>
                            <?php endif; ?>
                        </td>
                        <td class="d-flex gap-1">
                            <button
                                class="btn btn-sm btn-primary editScheduleBtn"
                                data-id="<?= $schedule['id'] ?>"
                                data-action="<?= htmlspecialchars($schedule['action']) ?>"
                                data-temperature="<?= htmlspecialchars($schedule['temperature']) ?>"
                                data-execution="<?= htmlspecialchars($schedule['execution_time']) ?>"
                                data-repeat="<?= htmlspecialchars($schedule['repeat_days']) ?>"
                            >
                                Modifier
                            </button>

                            <form method="POST" action="duplicate_schedule.php">
                                <input type="hidden" name="id" value="<?= $schedule['id'] ?>">
                                <button class="btn btn-sm btn-outline-primary">
                                    Dupliquer
                                </button>
                            </form>

                            <form method="POST" action="delete_schedule.php">
                                <input type="hidden" name="id" value="<?= $schedule['id'] ?>">
                                <button class="btn btn-sm btn-danger" onclick="return confirm('Supprimer ce planning ?')">
                                    Supprimer
                                </button>
                            </form>

                        </td>
                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>
    </td>
</table>
<div class="modal fade" id="editScheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="update_schedule.php" class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">Modifier planning</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <input type="hidden" name="id" id="edit_id">

                <label class="form-label">Action</label>
                <select name="action" id="edit_action" class="form-control mb-3">
                    <option value="">Aucun changement</option>
                    <option value="ON">ON</option>
                    <option value="OFF">OFF</option>
                </select>

                <label class="form-label">Température</label>
                <select name="temperature" id="edit_temperature" class="form-control mb-3">
                    <option value="">Aucun changement</option>
                    <?php for ($t=16;$t<=30;$t++): ?>
                        <option value="<?= $t ?>"><?= $t ?>°C</option>
                    <?php endfor; ?>
                </select>

                <label class="form-label">Execution</label>
                <input type="datetime-local" name="execution_time" id="edit_execution" class="form-control mb-3">

                <label class="form-label">Repeat days</label>
                <div>
                    <?php foreach ($dayLabels as $day => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input edit_repeat" type="checkbox" name="repeat_days[]" value="<?= $day ?>">
                            <label class="form-check-label"><?= $label ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button class="btn btn-success">Sauvegarder</button>
            </div>

        </form>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {

        const editModalEl = document.getElementById('editScheduleModal');
        const editModal = new bootstrap.Modal(editModalEl);

        document.querySelectorAll('.editScheduleBtn').forEach(btn => {

            btn.addEventListener('click', () => {

                document.getElementById('edit_id').value = btn.dataset.id || '';
                const action = btn.dataset.action;
                document.getElementById('edit_action').value =
                    (action === 'ON' || action === 'OFF') ? action : '';
                const temp = btn.dataset.temperature;
                document.getElementById('edit_temperature').value =
                    (temp >= 16 && temp <= 30) ? temp : '';

                let dt = btn.dataset.execution || '';

                if (dt) {
                    dt = dt.replace(' ', 'T');

                    // IMPORTANT: interprétation UTC correcte
                    const date = new Date(dt + 'Z');

                    const pad = n => String(n).padStart(2, '0');

                    const formatted =
                        date.getFullYear() + '-' +
                        pad(date.getMonth() + 1) + '-' +
                        pad(date.getDate()) + 'T' +
                        pad(date.getHours()) + ':' +
                        pad(date.getMinutes());

                    document.getElementById('edit_execution').value = formatted;
                }

                // reset repeat
                document.querySelectorAll('.edit_repeat').forEach(cb => cb.checked = false);

                if (btn.dataset.repeat) {
                    const days = btn.dataset.repeat.split(',');

                    document.querySelectorAll('.edit_repeat').forEach(cb => {
                        cb.checked = days.includes(cb.value);
                    });
                }

                editModal.show();
            });
        });

        const form = document.querySelector('#editScheduleModal form');

        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const res = await fetch('update_schedule.php', {
                    method: 'POST',
                    body: new FormData(form)
                });

                const data = await res.json();

                if (!data.success) {
                    alert('Erreur update');
                    return;
                }

                location.reload();
            });
        }

    });
</script>

</body>

</html>