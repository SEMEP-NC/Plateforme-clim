<?php
require 'auth.php';
session_start();
require_login();

$user = $_SESSION['user'];
$user_session = $_SESSION['user'];

$page_title = "Supervision Climatisation";
require "includes/header.php";
require "includes/user_menu.php";
?>

<!-- ================= DASHBOARD ================= -->
 <style>
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
                        <a href="admin.php" class="btn btn-danger">Gérer</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="col-md-4 col-lg-3">
            <div class="card dashboard-card shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="icon-box text-secondary">
                        <i class="bi bi-folder2-open"></i>
                    </div>
                    <h4 class="mt-3">Documents</h4>
                    <p class="text-muted">
                        Notices, procédures et documents
                    </p>
                    <a href="documents.php" class="btn btn-secondary">
                        Ouvrir
                    </a>

                </div>
            </div>
        </div>
    </div>
</main>
<?php require "includes/footer.php"; ?>