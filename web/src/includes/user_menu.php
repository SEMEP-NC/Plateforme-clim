<div class="container mt-3">
    <div class="d-flex justify-content-between align-items-center">
        <div class="dropdown">
            <button 
                class="btn btn-light dropdown-toggle d-flex align-items-center gap-2"
                type="button"
                data-bs-toggle="dropdown">
                <i class="bi bi-person-circle fs-4"></i>
                <?= htmlspecialchars($user_session['username']) ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li>
                    <h6 class="dropdown-header">
                    <?= htmlspecialchars($user_session['username']) ?>
                    <br>
                    <small class="text-muted">
                    <?= htmlspecialchars($user_session['role']) ?>
                    </small>
                    </h6>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="change_password.php">
                    <i class="bi bi-key me-2"></i>Changer mon mot de passe</a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="logout.php">
                        <button class="dropdown-item text-danger">
                        <i class="bi bi-box-arrow-right me-2"></i>Déconnexion</button>
                    </form>
                </li>
            </ul>
        </div>
        <?php if($user['role'] !== 'viewer'):?>
            <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>Retour tableau de bord</a>
        <?php endif; ?>
    </div>
</div>