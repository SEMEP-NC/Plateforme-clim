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

<a href="index.php" class="btn btn-secondary mb-3">Retour</a>

<table class="table table-bordered">
    <thead>
        <tr>
            <th>Nom</th>
            <th>UI</th>
            <th>Puissance</th>
            <th>IP Passerelle</th>
            <th>Slave modbus ID</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach($equipments as $equipment): ?>
            <tr>
                <td><?= $equipment['name'] ?></td>
                <td><?= $equipment['UI'] ?></td>
                <td><?= $equipment['power'] ?></td>
                <td><?= $equipment['ip'] ?></td>
                <td><?= $equipment['slave_id'] ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>