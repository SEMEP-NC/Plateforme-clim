<?php

require 'config/db.php';

$pdo = get_db();

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
    ORDER BY execution_time ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| EQUIPMENTS LIST
|--------------------------------------------------------------------------
*/

$equipments = $pdo->query("
    SELECT id, name
    FROM equipments
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

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
        <select name="action" class="form-control mb-3" required>
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

    <label class="form-label">Date d'exécution</label>
    <input
        type="datetime-local"
        name="execution_time"
        class="form-control mb-3"
        required
    >

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
            <th>ID</th>
            <th>Équipement</th>
            <th>Action</th>
            <th>Température</th>
            <th>Exécution</th>
            <th>Exécuté</th>
        </tr>
    </thead>

    <tbody>

        <?php foreach ($schedules as $schedule): ?>

            <tr>

                <td>
                    <?= (int)$schedule['id'] ?>
                </td>

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
                    <?= 
                    $dt = new DateTime($schedule['execution_time'], new DateTimeZone('UTC'));
                    $dt->setTimezone(new DateTimeZone('+11:00'));
                    echo $dt->format('Y-m-d H:i') 
                    ?>
                </td>

                <td>
                    <?php if (!empty($schedule['executed'])): ?>
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