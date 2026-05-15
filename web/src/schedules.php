<?php

require 'config/db.php';

$pdo = get_db();

$schedules = $pdo->query(
    "
    SELECT
        schedules.*,
        equipments.name AS equipment_name
    FROM schedules
    JOIN equipments
        ON equipments.id = schedules.equipment_id
    ORDER BY execution_time ASC
    "
)->fetchAll(PDO::FETCH_ASSOC);

$equipments = $pdo->query(
    "
    SELECT *
    FROM equipments
    "
)->fetchAll(PDO::FETCH_ASSOC);

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

    <h1 class="mb-4">
        Planning HVAC
    </h1>

    <form
        method="POST"
        action="save_schedule.php"
        class="card p-4 mb-5"
    >

        <h4 class="mb-3">
            Ajouter un planning
        </h4>

        <select
            name="equipment_id"
            class="form-control mb-3"
            required
        >

            <?php foreach($equipments as $equipment): ?>

                <option value="<?= $equipment['id'] ?>">

                    <?= htmlspecialchars($equipment['name']) ?>

                </option>

            <?php endforeach; ?>

        </select>

        <select
            name="action"
            class="form-control mb-3"
        >

            <option value="ON">
                ON
            </option>

            <option value="OFF">
                OFF
            </option>

        </select>

        <input
            type="number"
            name="temperature"
            class="form-control mb-3"
            placeholder="Température"
        >

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

    <table class="table table-bordered table-striped">

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

            <?php foreach($schedules as $schedule): ?>

                <tr>

                    <td>
                        <?= $schedule['id'] ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($schedule['equipment_name']) ?>
                    </td>

                    <td>

                        <?php if($schedule['action'] == 'ON'): ?>

                            <span class="badge bg-success">
                                ON
                            </span>

                        <?php else: ?>

                            <span class="badge bg-danger">
                                OFF
                            </span>

                        <?php endif; ?>

                    </td>

                    <td>

                        <?= $schedule['temperature'] ?> °C

                    </td>

                    <td>

                        <?= $schedule['execution_time'] ?>

                    </td>

                    <td>

                        <?php if($schedule['executed']): ?>

                            <span class="badge bg-primary">
                                Oui
                            </span>

                        <?php else: ?>

                            <span class="badge bg-warning text-dark">
                                Non
                            </span>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>

        </tbody>

    </table>

</body>

</html>