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
            equipments.name AS equipment_name
        FROM schedules
        LEFT JOIN equipments
            ON equipments.id = schedules.equipment_id
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

<body class="container mt-5">

<h1 class="mb-4">Planning HVAC</h1>

<a href="index.php" class="btn btn-secondary mb-3">
    Retour
</a>

<!-- =========================
     FORMULAIRE AJOUT
========================= -->

<form method="POST" action="save_schedule.php" class="card p-4 mb-5">

    <h4 class="mb-3">Ajouter un planning</h4>

    <label class="form-label">Équipement</label>
    <select name="equipment_id" class="form-control mb-3" required>

        <?php foreach ($equipments as $equipment): ?>
            <option value="<?= (int)$equipment['id'] ?>">
                <?= htmlspecialchars($equipment['name']) ?>
            </option>
        <?php endforeach; ?>

    </select>

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

<!-- =========================
     TABLEAU
========================= -->

<table class="table table-bordered table-striped align-middle">

    <thead class="table-dark">
        <tr>
            <th>Équipement</th>
            <th>Action</th>
            <th>Température</th>
            <th>Prochaine execution</th>
            <th>Repetition</th>
            <th>Exécuté</th>
        </tr>
    </thead>

    <tbody>

        <?php foreach ($schedules as $schedule): ?>
            <tr>
                <td>
                    <?= htmlspecialchars($schedule['equipment_name'] ?? '—') ?>
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
                    <?php if (!empty($schedule['repeat_days'])): ?>
                        <span class="badge bg-info text-dark">Recurrence active</span>
                    <?php elseif (!empty($schedule['executed'])): ?>
                        <span class="badge bg-primary">Oui</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Non</span>
                    <?php endif; ?>
                </td>

            </tr>

        <?php endforeach; ?>

    </tbody>

</table>

</body>

</html>