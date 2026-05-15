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

    if (isset($_POST['save_equipments'])) {

        $selected = $_POST['selected'] ?? [];
        $names = $_POST['name'] ?? [];

        foreach ($selected as $device_id) {

            $name = $names[$device_id] ?? $device_id;

            // récupérer IP + slave depuis discovered_units
            $stmt = $db->prepare("SELECT * FROM discovered_units WHERE device_id=?");
            $stmt->execute([$device_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) continue;

            $ip = $row['ip'];
            $slave_id = $row['slave_id'];
            $port = $row['port'];
            $UI = $row['UI'];
            $power = $row['power'];

            // insert / update equipments
            $stmt = $db->prepare("
                INSERT INTO equipments (name, ip, slave_id, port, UI, power, enabled)
                VALUES (?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    name=VALUES(name),
                    enabled=1
            ");

            $stmt->execute([
                $name,
                $ip,
                $slave_id,
                $port,
                $UI,
                $power
            ]);
        }
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

        <button type="submit" name="save_equipments" value="1" class="btn btn-primary">
            💾 Sauvegarder
        </button>

        <button
            type="submit"
            name="run_discovery"
            value="1"
            class="btn btn-primary"
        >
            🔎 Recherche
        </button>

    </form>

</div>
<form method="POST">
    <input type="checkbox" onclick="document.querySelectorAll('input[name=\'selected[]\']').forEach(c => c.checked = this.checked)">
Select all
    <table class="table table-bordered">
        <thead>
            <tr>
                <th></th>
                <th>Equipement</th>
                <th>Nom</th>
                <th>Modèle</th>
                <th>Dernière vue</th>
                <th>Online</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach($units as $u): ?>
                <tr>
                    <!-- checkbox -->
                    <td>
                        <input type="checkbox" name="selected[]" value="<?= htmlspecialchars($u['device_id']) ?>">
                    </td>

                    <td><?= htmlspecialchars($u['device_id']) ?></td>


                    <!-- NAME EDITABLE -->
                    <td>
                        <input type="text"
                            name="name[<?= htmlspecialchars($u['device_id']) ?>]"
                            value="<?= htmlspecialchars($u['name']) ?>"
                            class="form-control form-control-sm">
                    </td>

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
</form>

</body>
</html>
<script>
setInterval(() => location.reload(), 30000);
</script>