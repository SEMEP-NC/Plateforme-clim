<?php
require 'config/db.php';

$schedules = $pdo->query(
    "SELECT schedules.*, equipments.name AS equipment_name
     FROM schedules
     JOIN equipments ON equipments.id = schedules.equipment_id
     ORDER BY execution_time ASC"
)->fetchAll();

$equipments = $pdo->query("SELECT * FROM equipments")->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Planning</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-5">

<h1>Planning</h1>

<form method="POST" action="save_schedule.php" class="mb-5">

    <select name="equipment_id" class="form-control mb-2" required>
        <?php foreach($equipments as $equipment): ?>
            <option value="<?= $equipment['id'] ?>">
                <?= $equipment['name'] ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="action" class="form-control mb-2">
        <option value="ON">ON</option>
        <option value="OFF">OFF</option>
    </select>

    <input type="number" name="temperature" placeholder="Température" class="form-control mb-2">

    <input type="datetime-local" name="execution_time" class="form-control mb-2" required>

    <button class="btn btn-success">Ajouter Planning</button>

</form>

<table class="table table-bordered">

    <thead>
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
            <td><?= $schedule['id'] ?></td>
            <td><?= $schedule['equipment_name'] ?></td>
            <td><?= $schedule['action'] ?></td>
            <td><?= $schedule['temperature'] ?></td>
            <td><?= $schedule['execution_time'] ?></td>
            <td><?= $schedule['executed'] ?></td>
        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

</body>
</html>