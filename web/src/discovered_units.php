<?php

session_start();
require 'config/db.php';

$db = get_db();
$discovery_result = null;
$error_message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_ip = trim($_POST['start_ip'] ?? '');
    $end_ip = trim($_POST['end_ip'] ?? '');
    $ports = trim($_POST['ports'] ?? '');
    $slave_ids = trim($_POST['slave_ids'] ?? '');

    if ($start_ip === '' || $end_ip === '' || $ports === '' || $slave_ids === '') {
        $error_message = 'Configuration discovery incomplete.';
    } else {
        $stmt = $db->prepare("
            UPDATE discovery_config
            SET start_ip = ?, end_ip = ?, ports = ?, slave_ids = ?
            WHERE id = 1
        ");

        $stmt->execute([$start_ip, $end_ip, $ports, $slave_ids]);
    }

    if ($error_message === null && isset($_POST['run_discovery'])) {
        $apiBaseUrl = getenv('API_BASE_URL') ?: 'http://clim_api:5001';
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, rtrim($apiBaseUrl, '/') . '/run-discovery');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error_message = 'Discovery error: ' . curl_error($ch);
        } else {
            $discovery_result = json_decode($response, true);
            if (!is_array($discovery_result)) {
                $error_message = 'Reponse discovery invalide.';
            }
        }

        curl_close($ch);
    }

    if ($error_message === null && isset($_POST['save_equipments'])) {
        $selected = $_POST['selected'] ?? [];
        $names = $_POST['name'] ?? [];

        foreach ($selected as $device_id) {
            $name = trim($names[$device_id] ?? $device_id);

            $stmt = $db->prepare("SELECT * FROM discovered_units WHERE device_id = ?");
            $stmt->execute([$device_id]);
            $row = $stmt->fetch();

            if (!$row) {
                continue;
            }

            if (!preg_match('/UI-(\d+)/', $row['device_id'], $m)) {
                continue;
            }

            $ui = (int)$m[1];

            $stmt = $db->prepare("
                INSERT INTO equipments (name, ip, slave_id, port, UI, power, enabled)
                VALUES (?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    slave_id = VALUES(slave_id),
                    port = VALUES(port),
                    power = VALUES(power),
                    enabled = 1
            ");

            $stmt->execute([
                $name !== '' ? $name : $row['device_id'],
                $row['ip'],
                $row['slave_id'],
                $row['port'],
                $ui,
                $row['model'],
            ]);
        }
    }
}

$config = $db->query("SELECT * FROM discovery_config LIMIT 1")->fetch();

$units = $db->query("
    SELECT
        d.*,
        e.name AS equipment_name,
        e.id AS equipment_id
    FROM discovered_units d
       LEFT JOIN equipments e
        ON e.ip = d.ip
       AND e.port = d.port
       AND e.slave_id = d.slave_id
       AND e.UI = CAST(
            SUBSTRING_INDEX(
                SUBSTRING_INDEX(d.device_id, '@', 1),
                'UI-',
                -1
            ) AS UNSIGNED
       )
    ORDER BY d.last_seen DESC
")->fetchAll();

    $page_title = "Decouverte des equipements";
    require "includes/header.php";
    require "includes/user_menu.php";
?>
    <style>
        .page-title {
            font-size:2rem;
        }

        .card {
            border:none;
            border-radius:15px;
            box-shadow:0 4px 15px rgba(0,0,0,.08);
        }

        .sortable {
            cursor:pointer;
            user-select:none;
        }

        .sortable:hover {
            background:#eef5ff;
        }
    </style>
    <main class="container flex-grow-1 mt-4">

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <?php if ($discovery_result): ?>
            <div class="alert alert-info">
                Discovery: <?= htmlspecialchars($discovery_result['status'] ?? 'unknown') ?>,
                <?= (int)($discovery_result['devices_found'] ?? 0) ?> equipement(s) detecte(s).
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Configurations</strong>
                </div>
                <div class="card-body">
                    <div class="border rounded p-3 mb-4">
                        
                        <label class="form-label">START IP</label>
                        <input type="text" name="start_ip" value="<?= htmlspecialchars($config['start_ip'] ?? '') ?>" class="form-control mb-2">

                        <label class="form-label">END IP</label>
                        <input type="text" name="end_ip" value="<?= htmlspecialchars($config['end_ip'] ?? '') ?>" class="form-control mb-2">

                        <label class="form-label">PORTS</label>
                        <input type="text" name="ports" value="<?= htmlspecialchars($config['ports'] ?? '') ?>" class="form-control mb-2">

                        <label class="form-label">SLAVE IDS</label>
                        <input type="text" name="slave_ids" value="<?= htmlspecialchars($config['slave_ids'] ?? '') ?>" class="form-control mb-3">

                        <button type="submit" name="run_discovery" value="1" class="btn btn-warning">Recherche</button>
                    </div>
                </div> 
            </div>  
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Unités trouvés</strong>
                </div>
                <div class="card-body">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="select_all" onclick="document.querySelectorAll('input[name=\'selected[]\']').forEach(c => c.checked = this.checked)">
                        <label class="form-check-label" for="select_all">Tout selectionner</label>
                    </div>

                    <table class="table table-bordered align-middle">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Equipement</th>
                                <th>Nom</th>
                                <th>Modele</th>
                                <th>IP</th>
                                <th>Port</th>
                                <th>Slave</th>
                                <th>Derniere vue</th>
                                <th>Online</th>
                                <th>Etat</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($units as $u): ?>
                            <tr>
                                <td>
                                    <?php if ($u['equipment_id']): ?>
                                        <span class="badge bg-success">OK</span>
                                    <?php else: ?>
                                        <input type="checkbox" name="selected[]" value="<?= htmlspecialchars($u['device_id']) ?>">
                                    <?php endif; ?>
                                </td>

                                <td><?= htmlspecialchars($u['device_id']) ?></td>

                                <td>
                                    <?php if ($u['equipment_id']): ?>
                                        <span class="badge bg-success"><?= htmlspecialchars($u['equipment_name']) ?></span>
                                    <?php else: ?>
                                        <input type="text" name="name[<?= htmlspecialchars($u['device_id']) ?>]" value="<?= htmlspecialchars($u['name']) ?>" class="form-control form-control-sm">
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php
                                    $model = $u['model'];
                                    echo is_numeric($model) ? htmlspecialchars(number_format($model / 10, 1) . ' kW') : htmlspecialchars((string)$model);
                                    ?>
                                </td>

                                <td><?= htmlspecialchars($u['ip']) ?></td>
                                <td><?= htmlspecialchars($u['port']) ?></td>
                                <td><?= htmlspecialchars($u['slave_id']) ?></td>
                                <td><?= htmlspecialchars($u['last_seen']) ?></td>
                                <td><?= $u['online'] ? 'Online' : 'Offline' ?></td>
                                <td><?= $u['equipment_id'] ? 'Ajoute' : 'Nouveau' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <button type="submit" name="save_equipments" value="1" class="btn btn-success">Ajouter les equipements selectionnes</button>
                </div>
            </div>
        </form>
    </main>

    <script>
    setInterval(() => location.reload(), 30000);
    </script>
<?php require "includes/footer.php"; ?>