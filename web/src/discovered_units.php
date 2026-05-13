<?php
require 'config/db.php';

$units = $pdo->query("SELECT * FROM discovered_units ORDER BY last_seen DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Clims détectées (Gree)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-5">

<h1>Climatiseurs détectés automatiquement</h1>

<a href="index.php" class="btn btn-secondary mb-3">Retour</a>

<table class="table table-bordered">
    <thead>
        <tr>
            <th>ID Device</th>
            <th>IP</th>
            <th>Nom</th>
            <th>Modèle</th>
            <th>Dernière vue</th>
            <th>Online</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach($units as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['device_id']) ?></td>
                <td><?= htmlspecialchars($u['ip']) ?></td>
                <td><?= htmlspecialchars($u['name']) ?></td>
                <td><?= htmlspecialchars($u['model']) ?></td>
                <td><?= $u['last_seen'] ?></td>
                <td><?= $u['online'] ? '🟢' : '🔴' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
<script>
setInterval(() => location.reload(), 30000);
</script>