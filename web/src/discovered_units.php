<?php
require 'config/db.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $start_ip = $_POST['start_ip'];
    $end_ip = $_POST['end_ip'];
    $ports = $_POST['ports'];
    $slave_ids = $_POST['slave_ids'];

    $stmt = $db->prepare("
        UPDATE discovery_config
        SET
            start_ip=?,
            end_ip=?,
            ports=?,
            slave_ids=?
        WHERE id=1
    ");

    $stmt->execute([
        $start_ip,
        $end_ip,
        $ports,
        $slave_ids
    ]);
     // LANCEMENT DISCOVERY
    if (isset($_POST['run_discovery'])) {

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL,
            "http://clim_scheduler:5001/run-discovery"
        );

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {

            $discovery_result =
                "Scheduler error: " . curl_error($ch);

        } else {

            $discovery_result = json_decode($response, true);
        }

        curl_close($ch);
    }
}

$config = $db->query("SELECT * FROM discovery_config LIMIT 1")
             ->fetch(PDO::FETCH_ASSOC);
             
$units = $db->query("SELECT * FROM discovered_units ORDER BY last_seen DESC")->fetchAll();
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
<div style="
    border:1px solid #ccc;
    padding:20px;
    margin-bottom:20px;
    border-radius:10px;
">

    <h2>Configuration Discovery</h2>

    <form method="POST" id="discoveryForm">

        <div style="margin-bottom:10px;">
            <label>START IP</label><br>
            <input
                type="text"
                name="start_ip"
                value="<?= htmlspecialchars($config['start_ip']) ?>"
                style="width:300px;"
            >
        </div>

        <div style="margin-bottom:10px;">
            <label>END IP</label><br>
            <input
                type="text"
                name="end_ip"
                value="<?= htmlspecialchars($config['end_ip']) ?>"
                style="width:300px;"
            >
        </div>

        <div style="margin-bottom:10px;">
            <label>PORTS (comma separated)</label><br>
            <input
                type="text"
                name="ports"
                value="<?= htmlspecialchars($config['ports']) ?>"
                style="width:300px;"
            >
        </div>

        <div style="margin-bottom:10px;">
            <label>SLAVE IDS (comma separated)</label><br>
            <input
                type="text"
                name="slave_ids"
                value="<?= htmlspecialchars($config['slave_ids']) ?>"
                style="width:300px;"
            >
        </div>

        <button type="submit">
            💾 Save Configuration
        </button>

        <button
            type="submit"
            name="run_discovery"
            value="1"
            style="margin-left:10px;"
        >
            🔎 Save + Run Discovery
        </button>

    </form>

</div>

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
                <td>
                    <?php $model = $u['model'];
                        if (is_numeric($model)) {
                            echo number_format($model / 10, 1) . 'kW';
                        } else {
                            echo htmlspecialchars($model);
                        }
                    ?>
                </td>
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