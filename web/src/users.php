<?php
require 'config/db.php';
require 'auth.php';

require_admin();

$pdo = get_db();

/*
|-----------------------------
| LIST USERS
|-----------------------------
*/
$users = $pdo->query("
    SELECT id, username, role, created_at
    FROM users
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestion utilisateurs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="container mt-5">

<h1 class="mb-4 text-center">Gestion des utilisateurs</h1>

<a href="index.php" class="btn btn-secondary mb-3">Retour</a>

<!-- =========================
     CREATE USER
========================= -->
<div class="card p-3 mb-4">

    <h5>Créer un utilisateur</h5>

    <form method="POST" action="create_user.php" class="row g-2">

        <div class="col-md-4">
            <input type="text" name="username" class="form-control" placeholder="Username" required>
        </div>

        <div class="col-md-4">
            <input type="password" name="password" class="form-control" placeholder="Password" required>
        </div>

        <div class="col-md-2">
            <select name="role" class="form-control">
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
        </div>

        <div class="col-md-2">
            <button class="btn btn-success w-100">Créer</button>
        </div>

    </form>

</div>

<!-- =========================
     USERS TABLE
========================= -->
<table class="table table-bordered table-striped">

    <thead class="table-dark">
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Role</th>
            <th>Créé le</th>
            <th>Actions</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= $u['id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td>
                <span class="badge bg-<?= $u['role'] === 'admin' ? 'danger' : 'secondary' ?>">
                    <?= $u['role'] ?>
                </span>
            </td>
            <td><?= $u['created_at'] ?></td>

            <td class="d-flex gap-2">

                <!-- DELETE -->
                <form method="POST" action="delete_user.php"
                      onsubmit="return confirm('Supprimer cet utilisateur ?')">

                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button class="btn btn-sm btn-danger">Supprimer</button>

                </form>

            </td>
        </tr>
    <?php endforeach; ?>

    </tbody>

</table>

</body>
</html>