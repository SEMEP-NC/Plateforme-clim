<?php
require 'config/db.php';

$db = get_db();

$groups = $db->query("
    SELECT *
    FROM groups_hvac
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['create_group'])
) {

    $name = trim($_POST['group_name']);

    if ($name !== '') {

        $stmt = $db->prepare("
            INSERT INTO groups_hvac(name)
            VALUES (?)
        ");

        $stmt->execute([$name]);
    }

    header("Location: equipments.php");
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['delete_group'])
) {

    $id = (int)$_POST['group_id'];

    $db->prepare("
        DELETE FROM equipment_groups
        WHERE group_id=?
    ")->execute([$id]);

    $db->prepare("
        DELETE FROM groups_hvac
        WHERE id=?
    ")->execute([$id]);

    header("Location: equipments.php");
    exit;
}
/*
|--------------------------------------------------------------------------
| UPDATE NAME
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['save_all'])
) {

    foreach ($_POST['name'] as $id => $name) {

        $stmt = $db->prepare("
            UPDATE equipments
            SET name = ?
            WHERE id = ?
        ");

        $stmt->execute([
            trim($name),
            (int)$id
        ]);
    }

    foreach ($_POST['groups'] as $equipmentId => $groupIds) {

        $equipmentId = (int)$equipmentId;

        $db->prepare("
            DELETE FROM equipment_groups
            WHERE equipment_id = ?
        ")->execute([$equipmentId]);

        foreach ($groupIds as $groupId) {

            $db->prepare("
                INSERT INTO equipment_groups
                (equipment_id, group_id)
                VALUES (?, ?)
            ")->execute([
                $equipmentId,
                (int)$groupId
            ]);
        }
    }

    header("Location: equipments.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| DELETE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['delete_equipment'])
) {
    $id = (int)$_POST['id'];

    $stmt = $db->prepare("
        DELETE FROM equipments
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    header("Location: equipments.php");
    exit;
}

$equipments = $db->query("
    SELECT *
    FROM equipments
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">

    <title>Équipements</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="container mt-5">

<h1>Équipements</h1>

<a href="index.php" class="btn btn-secondary mb-3">
    Retour
</a>

<form method="POST">
    <button
        type="submit"
        name="save_all"
        class="btn btn-success"
    >
        💾 Sauvegarder toutes les modifications
    </button>
<div class="card mb-4">

    <div class="card-header">
        <strong>Groupes</strong>
    </div>

    <div class="card-body">

        <form method="POST" class="row g-2 mb-3">

            <div class="col-md-8">
                <input
                    type="text"
                    name="group_name"
                    class="form-control"
                    placeholder="Nouveau groupe"
                    required
                >
            </div>

            <div class="col-md-4">
                <button
                    class="btn btn-primary w-100"
                    name="create_group"
                >
                    Ajouter
                </button>
            </div>

        </form>

        <table class="table table-bordered">

            <thead>
                <tr>
                    <th>Nom</th>
                    <th width="120">Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($groups as $group): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars($group['name']) ?>
                    </td>

                    <td>

                        <form method="POST">

                            <input
                                type="hidden"
                                name="group_id"
                                value="<?= $group['id'] ?>"
                            >

                            <button
                                class="btn btn-danger btn-sm"
                                name="delete_group"
                                onclick="return confirm('Supprimer ce groupe ?')"
                            >
                                ❌
                            </button>

                        </form>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>
    <table class="table table-bordered table-striped align-middle">

        <thead>
            <tr>
                <th>Nom</th>
                <th>UI</th>
                <th>Puissance</th>
                <th>IP Passerelle</th>
                <th>Slave Modbus ID</th>
                <th>Groupes</th>
                <th width="180">Actions</th>
            </tr>
        </thead>

        <tbody>

        <?php foreach ($equipments as $equipment): ?>

            <tr>

            

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$equipment['id'] ?>"
                >

                <td>
                    <input
                        type="text"
                        name="name"
                        value="<?= htmlspecialchars($equipment['name']) ?>"
                        class="form-control"
                    >
                </td>

                <td>
                    <?= htmlspecialchars($equipment['UI']) ?>
                </td>

                <td>
                    <?php
                    if (is_numeric($equipment['power'])) {
                        echo number_format(
                            $equipment['power'] / 10,
                            1
                        ) . ' kW';
                    } else {
                        echo htmlspecialchars($equipment['power']);
                    }
                    ?>
                </td>

                <td>
                    <?= htmlspecialchars($equipment['ip']) ?>
                </td>

                <td>
                    <?= htmlspecialchars($equipment['slave_id']) ?>
                </td>
                <td>
                    <select
                        name="groups[<?= (int)$equipment['id'] ?>][]"
                        class="form-select"
                        multiple
                    >
                        <?php foreach ($groups as $group): ?>

                            <option>
                                <?= htmlspecialchars($group['name']) ?>
                            </option>

                        <?php endforeach; ?>
                    </select>
                </td>
                <td class="text-center">


                    <button
                        type="submit"
                        name="delete_equipment"
                        value="1"
                        class="btn btn-danger btn-sm"
                        onclick="return confirm(
                            'Supprimer cet équipement ?'
                        );"
                    >
                        ❌
                    </button>

                </td>

            

            </tr>

        <?php endforeach; ?>

        </tbody>

    </table>
</form>
</body>
</html>