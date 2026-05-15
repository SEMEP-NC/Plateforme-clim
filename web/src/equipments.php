<?php
require 'config/db.php';
$db = get_db();
$equipments = $db->query("SELECT * FROM equipments")->fetchAll();
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

<form method="POST" action="save_equipment.php" class="mb-5">

    <input type="text" name="name" placeholder="Nom" class="form-control mb-2" required>

    <input type="text" name="ip" placeholder="IP" class="form-control mb-2" required>

    <input type="number" name="slave_id" placeholder="Slave ID" class="form-control mb-2" required>

    <button class="btn btn-primary">Ajouter</button>

</form>

<table class="table table-bordered">
    <thead>
        <tr>
            <th>ID</th>
            <th>Nom</th>
            <th>IP</th>
            <th>Slave ID</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach($equipments as $equipment): ?>
            <tr>
                <td><?= $equipment['id'] ?></td>
                <td><?= $equipment['name'] ?></td>
                <td><?= $equipment['ip'] ?></td>
                <td><?= $equipment['slave_id'] ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>