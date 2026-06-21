<?php
require 'config/db.php';

$db = get_db();

/*
|--------------------------------------------------------------------------
| UPDATE NAME
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_name'])
) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);

    $stmt = $db->prepare("
        UPDATE equipments
        SET name = ?
        WHERE id = ?
    ");

    $stmt->execute([$name, $id]);

    header("Location: equipements.php");
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

    header("Location: equipements.php");
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

<table class="table table-bordered table-striped align-middle">

    <thead>
        <tr>
            <th>Nom</th>
            <th>UI</th>
            <th>Puissance</th>
            <th>IP Passerelle</th>
            <th>Slave Modbus ID</th>
            <th width="180">Actions</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($equipments as $equipment): ?>

        <tr>

            <form method="POST">

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

                <td class="text-center">

                    <button
                        type="submit"
                        name="update_name"
                        value="1"
                        class="btn btn-success btn-sm"
                    >
                        💾
                    </button>

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

            </form>

        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

</body>
</html>