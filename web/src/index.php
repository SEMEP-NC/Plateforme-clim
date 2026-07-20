<?php
require 'auth.php';
session_start();
require_login();

$user = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Supervision Climatisation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Icônes -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        body {
            background: #f5f7fa;
        }
        .logo {
            height: 50px;
            width: auto;
            object-fit: contain;
        }
        .dashboard-card {

            transition: all .25s ease;
            border: none;
            border-radius: 18px;
        }
        .dashboard-card:hover {

            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,.15);
        }
        .icon-box {

            font-size: 45px;
        }
    </style>
</head>

<body class="vh-100 d-flex flex-column">

    <!-- ================= HEADER ================= -->
    <header class="bg-white shadow-sm py-3">

        <div class="container position-relative">

            <!-- LOGO GAUCHE -->
            <img src="images/logo-semep.png"
                class="logo position-absolute top-50 start-0 translate-middle-y"
                style="max-height:35px; width:auto;"
                alt="SEMEP">

 
            <!-- TITRE TOUJOURS CENTRÉ -->
            <div class="text-center">

                <h1 class="fw-bold mb-1">
                    Supervision Climatisation
                </h1>

                <small class="text-muted">
                    Gestion des équipements
                </small>

            </div>


            <!-- LOGO DROIT -->
            <img src="images/Gree-Electric-logo.png"
                class="logo position-absolute top-50 end-0 translate-middle-y"
                alt="GREE">

        </div>

    </header>

    <!-- ================= UTILISATEUR ================= -->
    <div class="container mt-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-person-circle"></i>
                <?= htmlspecialchars($user['username']) ?>
                <span class="badge bg-secondary">
                <?= htmlspecialchars($user['role']) ?>
                </span>
            </div>
            <form method="POST" action="logout.php">
                <button class="btn btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i>
                    Déconnexion
                </button>
            </form>
        </div>
    </div>

    <!-- ================= DASHBOARD ================= -->
    <main class="container flex-grow-1 d-flex align-items-center">
        <div class="row g-4 w-100 justify-content-center">
            <!-- Equipements -->
            <div class="col-md-4 col-lg-3">
                <div class="card dashboard-card shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="icon-box text-primary">
                            <i class="bi bi-snow"></i>
                        </div>
                        <h4 class="mt-3">Équipements</h4>
                        <p class="text-muted">Gestion des unités climatisation</p>
                        <a href="equipments.php" class="btn btn-primary">Ouvrir</a>
                    </div>
                </div>
            </div>
            <!-- Planning -->
            <div class="col-md-4 col-lg-3">
                <div class="card dashboard-card shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="icon-box text-success">
                            <i class="bi bi-calendar-week"></i>
                        </div>
                    <h4 class="mt-3">Planning</h4>
                    <p class="text-muted">Programmation horaire</p>
                    <a href="schedules.php" class="btn btn-success">Ouvrir</a>
                    </div>
                </div>
            </div>
            <!-- Défauts -->
            <div class="col-md-4 col-lg-3">
                <div class="card dashboard-card shadow-sm h-100">
                    <div class="card-body text-center">
                        <div class="icon-box text-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <h4 class="mt-3">Défauts</h4>
                        <p class="text-muted">Historique des alarmes</p>
                        <a href="fault_history.php" class="btn btn-warning">Voir</a>
                    </div>
                </div>
            </div>
            <?php if ($user['role'] === 'admin'): ?>
                <!-- Detection -->
                <div class="col-md-4 col-lg-3">
                    <div class="card dashboard-card shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="icon-box text-info">
                                <i class="bi bi-search"></i>
                            </div>
                            <h4 class="mt-3">Détection</h4>
                            <p class="text-muted">Recherche automatique</p>
                            <a href="discovered_units.php" class="btn btn-info">Ouvrir</a>
                        </div>
                    </div>
                </div>
                <!-- Administration -->
                <div class="col-md-4 col-lg-3">
                    <div class="card dashboard-card shadow-sm h-100">
                        <div class="card-body text-center">
                            <div class="icon-box text-danger">
                                <i class="bi bi-gear"></i>
                            </div>
                            <h4 class="mt-3">Administration</h4>
                            <p class="text-muted">Paramètres système</p>
                            <a href="users.php" class="btn btn-danger">Gérer</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- ================= FOOTER ================= -->
    <footer class="text-center py-3 bg-white shadow-sm">
        <small>Supervision GREE - SEMEP - Version <?= htmlspecialchars($_ENV['APP_VERSION'] ?? '') ?></small>
    </footer>
</body>
</html>