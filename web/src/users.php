<?php
require 'config/db.php';
require 'auth.php';
session_start();
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<style>
        body {
            background:#f5f7fa;
        }

        .logo {
            max-height:50px;
            width:auto;
        }

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
<body class="vh-100 d-flex flex-column">
    <header class="bg-white shadow-sm py-3">
        <div class="container position-relative">
            <!-- LOGO GAUCHE -->
            <img src="images/logo-semep.png"
                class="logo position-absolute top-50 start-0 translate-middle-y"
                style="max-height:35px; width:auto;"
                alt="SEMEP">

            <!-- TITRE CENTRÉ -->
            <div class="text-center">
                <h1 class="fw-bold page-title mb-1">
                    Administration
                </h1>
                <small class="text-muted">
                    Supervision des unités climatisation
                </small>
            </div>
            <!-- LOGO DROIT -->
            <img src="images/Gree-Electric-logo.png"
                class="logo position-absolute top-50 end-0 translate-middle-y"
                alt="GREE">
        </div>
    </header>
    <div class="container mt-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-person-circle"></i>
                <?= htmlspecialchars($_SESSION['user']['username']) ?>
                <span class="badge bg-secondary">
                    <?= htmlspecialchars($_SESSION['user']['role']) ?>
                </span>
            </div>
            <a href="index.php"class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>Retour tableau de bord</a>
        </div>
    </div>
    <main class="container flex-grow-1 mt-4">
        <div class="card mb-4">
            <div class="card-header">
                <strong>Utilisateurs</strong>
            </div>
            <div class="card-body">
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
            </div>
    </main>
    <footer class="text-center py-3 bg-white shadow-sm mt-auto">
        <small>Supervision GREE - SEMEP - Version <?= htmlspecialchars($_ENV['APP_VERSION'] ?? '') ?></small>
    </footer>
</body>
</html>