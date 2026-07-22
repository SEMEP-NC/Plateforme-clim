<?php

require 'auth.php';
session_start();
require_admin();
require 'config/db.php';

$db = get_db();

$sql = "
SELECT

    e.id,
    e.UI,
    e.name,
    e.localisation,

    a.id AS alarm_id,
    a.enabled,
    a.high_threshold,
    a.low_threshold,
    a.delay_seconds,
    a.fault_name

FROM equipments e

LEFT JOIN equipment_temperature_alarms a
ON a.equipment_id=e.id
WHERE e.enabled=1
ORDER BY e.UI
";

$stmt=$db->query($sql);
$equipments=$stmt->fetchAll();

$page_title = "Configuration alarme temperature";
require "includes/header.php";
require "includes/user_menu.php";
?>

<main class="container flex-grow-1 mt-4">
    <div class="card mb-4">
        <div class="card-header">
            <strong>Alarmes temperature</strong>
        </div>
        <div class="card-body">
            <div class="container mt-4">
                <table class="table table-bordered mt-4">
                    <tr>
                        <th>UI</th>
                        <th>Nom</th>
                        <th>Localisation</th>
                        <th>Activation</th>
                        <th>Seuil haut</th>
                        <th>Seuil bas</th>
                        <th>Temporisation</th>
                        <th>Action</th>
                    </tr>

                    <?php foreach($equipments as $e): ?>
                        <tr>
                            <form method="post" action="save_temperature_alarm.php">
                                <input type="hidden" name="equipment_id" value="<?= $e['id'] ?>">
                                <td>UI<?= $e['UI'] ?></td>
                                <td><?= htmlspecialchars($e['name']) ?></td>
                                <td><?= htmlspecialchars($e['localisation']) ?></td>
                                <td><input type="checkbox" class="form-check-input" name="enabled" value="1"<?= $e['enabled'] ? 'checked':'' ?>></td>
                                <td><input type="number" step="0.1" class="form-control" name="high_threshold" value="<?= $e['high_threshold'] ?>" placeholder="°C"></td>
                                <td><input type="number" step="0.1" class="form-control" name="low_threshold" value="<?= $e['low_threshold'] ?>" placeholder="°C"></td>
                                <td><input type="number" class="form-control" name="delay_minutes" value="<?= intval($e['delay_seconds']/60)?>"></td>
                                <td><button class="btn btn-primary">Sauver</button></td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
</main>           
<?php require "includes/footer.php"; ?>