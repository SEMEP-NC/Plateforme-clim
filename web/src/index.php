<?php
require 'auth.php';
session_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestion Climatisation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        .menu-btn {
            width: 280px;
            padding: 14px 0;
            font-size: 1.2rem;
        }
    </style>
</head>

<body class="vh-100 d-flex flex-column">

<!-- TITRE -->
<div class="text-center mt-4">
    <h1 class="fw-bold">Gestion des Climatisations</h1>
</div>

<!-- CONTENU CENTRÉ -->
<div class="flex-grow-1 d-flex justify-content-center align-items-center">

    <div class="d-flex flex-column gap-3">

        <a href="equipments.php" class="btn btn-primary menu-btn">
            Équipements
        </a>

        <a href="schedules.php" class="btn btn-success menu-btn">
            Planning
        </a>

        <?php if ($_SESSION['user']['role'] === 'admin'): ?>
            <a href="discovered_units.php" class="btn btn-warning">
                Détection automatique
            </a>
        <?php endif; ?>
        <a></a>
        <?php if ($_SESSION['user']['role'] === 'admin'): ?>
            <a href="users.php" class="btn btn-danger">
                Gestion utilisateurs
            </a>
        <?php endif; ?>

    </div>

</div>
<div class="mt-auto text-center pb-4">

    <form method="POST" action="logout.php">
        <button class="btn btn-outline-danger px-5">
            Déconnexion
        </button>
    </form>

</div>

</body>
</html>