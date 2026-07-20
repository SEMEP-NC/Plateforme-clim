<?php
session_start();
require_once "config/db.php";
$db = get_db();

// Liste équipements pour filtre
$stmt = $db->query("
    SELECT id, name, UI
    FROM equipments
    ORDER BY UI
");

$equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// filtre
$equipment_id = $_GET['equipment_id'] ?? null;
$sql = "
SELECT
    h.created_at,
    h.fault_code,
    h.fault_name,
    h.active,
    e.name,
    e.UI
FROM equipment_fault_history h
JOIN equipments e
    ON e.id = h.equipment_id
WHERE 1=1
";

$params=[];

if ($equipment_id) {
    $sql .= " AND e.id = ?";
    $params[]=$equipment_id;
}

$sql .= "
ORDER BY h.created_at DESC
LIMIT 1000
";

$stmt=$db->prepare($sql);
$stmt->execute($params);
$faults=$stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title>Historique défauts</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>

    <body>
        <div class="container mt-4">
            <h3>Historique des défauts</h3>
            <a href="index.php" class="btn btn-secondary mb-3">Retour</a>
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <select name="equipment_id" class="form-select">
                                <option value="">Tous les équipements</option>
                                <?php foreach($equipments as $e): ?>
                                    <option value="<?= $e['id'] ?>"<?= ($equipment_id==$e['id'])?'selected':'' ?>>UI<?= $e['UI'] ?> -<?= htmlspecialchars($e['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary">Filtrer</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>UI</th>
                            <th>Nom</th>
                            <th>Défaut</th>
                            <th>Code</th>
                            <th>Etat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($faults as $f): ?>
                            <tr>
                                <td>
                                    <?php
                                        $date = new DateTime($f['created_at'], new DateTimeZone('UTC'));
                                        $date->setTimezone(new DateTimeZone('Pacific/Noumea'));
                                        echo $date->format('d/m/Y H:i:s');
                                    ?>
                                </td>
                                <td>UI<?= htmlspecialchars($f['UI']) ?></td>
                                <td><?= htmlspecialchars($f['name']) ?></td>
                                <td><?= htmlspecialchars($f['fault_name']) ?></td>
                                <td><?= htmlspecialchars($f['fault_code']) ?></td>
                                <td><?php if($f['active']): ?><span class="badge bg-danger">ACTIF</span><?php else: ?><span class="badge bg-success">CLEARED</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <footer class="position-fixed bottom-0 start-0 w-100 text-center py-3">
            <small>
                Supervision GREE - SEMEP - Version <?= htmlspecialchars($_ENV['APP_VERSION'] ?? '') ?>
            </small>
        </footer>
    </body>
</html>