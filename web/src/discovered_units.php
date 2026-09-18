<?php
require 'auth.php';
require_login();
require 'config/db.php';

$db = get_db();
$discovery_result = null;
$error_message = null;

const EQUIPMENT_TYPES = [
    'GMV' => 'GMV (système centralisé)',
    'UMATCH' => 'U-Match (split / multi-split)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    /*
    |----------------------------------------------------------------
    | AJOUT / MODIFICATION D'UNE PASSERELLE DE DETECTION
    |----------------------------------------------------------------
    */
    if (isset($_POST['save_config'])) {
        $config_id = (int)($_POST['config_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $start_ip = trim($_POST['start_ip'] ?? '');
        $end_ip = trim($_POST['end_ip'] ?? '');
        $ports = trim($_POST['ports'] ?? '');
        $slave_ids = trim($_POST['slave_ids'] ?? '');
        $type = $_POST['type'] ?? 'GMV';

        if (!array_key_exists($type, EQUIPMENT_TYPES)) {
            $type = 'GMV';
        }

        if ($name === '' || $start_ip === '' || $end_ip === '' || $ports === '' || $slave_ids === '') {
            $error_message = 'Configuration de passerelle incomplète.';
        } elseif ($config_id > 0) {
            $stmt = $db->prepare("
                UPDATE discovery_config
                SET name = ?, start_ip = ?, end_ip = ?, ports = ?, slave_ids = ?, type = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $start_ip, $end_ip, $ports, $slave_ids, $type, $config_id]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO discovery_config (name, start_ip, end_ip, ports, slave_ids, type)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $start_ip, $end_ip, $ports, $slave_ids, $type]);
        }

        if ($error_message === null) {
            header("Location: discovered_units.php");
            exit;
        }
    }

    /*
    |----------------------------------------------------------------
    | SUPPRESSION D'UNE PASSERELLE
    |----------------------------------------------------------------
    */
    if (isset($_POST['delete_config'])) {
        $config_id = (int)($_POST['config_id'] ?? 0);
        $db->prepare("DELETE FROM discovery_config WHERE id = ?")->execute([$config_id]);
        header("Location: discovered_units.php");
        exit;
    }

    /*
    |----------------------------------------------------------------
    | LANCEMENT DE LA DETECTION (toutes passerelles configurées)
    |----------------------------------------------------------------
    */
    if (isset($_POST['run_discovery'])) {
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

    /*
    |----------------------------------------------------------------
    | AJOUT DES EQUIPEMENTS SELECTIONNES
    |----------------------------------------------------------------
    */
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
            $type = array_key_exists($row['type'] ?? '', EQUIPMENT_TYPES) ? $row['type'] : 'GMV';

            $stmt = $db->prepare("
                INSERT INTO equipments (name, ip, slave_id, port, UI, type, power, enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    slave_id = VALUES(slave_id),
                    port = VALUES(port),
                    type = VALUES(type),
                    power = VALUES(power),
                    enabled = 1
            ");

            $stmt->execute([
                $name !== '' ? $name : $row['device_id'],
                $row['ip'],
                $row['slave_id'],
                $row['port'],
                $ui,
                $type,
                $row['model'],
            ]);
        }
    }
}

$configs = $db->query("SELECT * FROM discovery_config ORDER BY name")->fetchAll();

$edit_config = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM discovery_config WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $edit_config = $stmt->fetch();
}

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

        <!-- ========================= PASSERELLES DE DETECTION ========================= -->
        <div class="card mb-4">
            <div class="card-header">
                <strong>Passerelles de détection</strong>
            </div>
            <div class="card-body">

                <div class="table-responsive mb-4">
                    <table class="table table-bordered align-middle">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Type</th>
                                <th>Plage IP</th>
                                <th>Ports</th>
                                <th>Esclaves</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$configs): ?>
                                <tr>
                                    <td colspan="6" class="text-muted text-center">
                                        Aucune passerelle configurée.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($configs as $config): ?>
                                <tr>
                                    <td><?= htmlspecialchars($config['name']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $config['type'] === 'UMATCH' ? 'info' : 'primary' ?>">
                                            <?= htmlspecialchars(EQUIPMENT_TYPES[$config['type']] ?? $config['type']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($config['start_ip']) ?> → <?= htmlspecialchars($config['end_ip']) ?></td>
                                    <td><?= htmlspecialchars($config['ports']) ?></td>
                                    <td><?= htmlspecialchars($config['slave_ids']) ?></td>
                                    <td class="text-end">
                                        <a href="discovered_units.php?edit=<?= $config['id'] ?>" class="btn btn-sm btn-secondary">
                                            Modifier
                                        </a>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
                                            <input type="hidden" name="config_id" value="<?= $config['id'] ?>">
                                            <button
                                                type="submit"
                                                name="delete_config"
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('Supprimer cette passerelle ?')">
                                                ❌
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="POST" class="border rounded p-3 mb-3">
                    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
                    <input type="hidden" name="config_id" value="<?= $edit_config['id'] ?? 0 ?>">

                    <h6 class="mb-3">
                        <?= $edit_config ? 'Modifier la passerelle' : 'Ajouter une passerelle' ?>
                    </h6>

                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Nom</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($edit_config['name'] ?? '') ?>" class="form-control" placeholder="Ex: Bâtiment A - GMV">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <?php foreach (EQUIPMENT_TYPES as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= (($edit_config['type'] ?? 'GMV') === $key) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">START IP</label>
                            <input type="text" name="start_ip" value="<?= htmlspecialchars($edit_config['start_ip'] ?? '') ?>" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">END IP</label>
                            <input type="text" name="end_ip" value="<?= htmlspecialchars($edit_config['end_ip'] ?? '') ?>" class="form-control">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Ports</label>
                            <input type="text" name="ports" value="<?= htmlspecialchars($edit_config['ports'] ?? '502') ?>" class="form-control">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Esclaves</label>
                            <input type="text" name="slave_ids" value="<?= htmlspecialchars($edit_config['slave_ids'] ?? '') ?>" class="form-control" placeholder="1,2,3">
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" name="save_config" class="btn btn-primary">
                            <?= $edit_config ? 'Enregistrer' : 'Ajouter' ?>
                        </button>
                        <?php if ($edit_config): ?>
                            <a href="discovered_units.php" class="btn btn-outline-secondary">Annuler</a>
                        <?php endif; ?>
                    </div>

                    <div class="form-text mt-2">
                        Pour un U-Match, une passerelle IP correspond généralement à une seule adresse
                        (start = end) avec la liste des esclaves derrière cette passerelle
                        (ex: 1,2,3,4). Pour un GMV, la plage IP peut couvrir plusieurs passerelles
                        centralisées.
                    </div>
                </form>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
                    <button type="submit" name="run_discovery" value="1" class="btn btn-warning">
                        🔍 Lancer la détection sur toutes les passerelles
                    </button>
                </form>
            </div>
        </div>

        <!-- ========================= UNITES TROUVEES ========================= -->
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
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
                                <th>Type</th>
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
                                    <span class="badge bg-<?= ($u['type'] ?? 'GMV') === 'UMATCH' ? 'info' : 'primary' ?>">
                                        <?= htmlspecialchars($u['type'] ?? 'GMV') ?>
                                    </span>
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
