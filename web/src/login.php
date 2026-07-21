<?php
session_start();
require 'config/db.php';

$pdo = get_db();

/* =========================
   CHECK ADMIN EXISTENCE
========================= */
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
$adminExists = (int)$stmt->fetchColumn() > 0;

/* =========================
   LOGIN PROCESS
========================= */
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminExists) {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {

        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role']
        ];
        if($user['role']=='viewer'){
            header("Location: index.php");
            exit;
        }
        else{
            header("Location: index.php");
            exit;
        }
    }

    $error = "Identifiants invalides";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Login</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container d-flex flex-column justify-content-center align-items-center" style="height:80vh;">
        <div>
            <img src="images/Gree-Electric-logo.png"
                alt="Logo"
                class="img-fluid mx-auto mb-3"
                style="max-height:80px; width:auto;">
        </div>
        <div style="width: 150px;">
            <br><br><br>
        </div>
        <div class="card p-4 shadow" style="width: 380px;">
            <h3 class="text-center mb-3">Connexion</h3>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <!-- LOGIN FORM -->
            <form method="POST">
                <div class="mb-3">
                    <label>Utilisateur</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label>Mot de passe</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button class="btn btn-primary w-100">
                    Se connecter
                </button>
            </form>
        </div>
    </div>

<?php if (!$adminExists): ?>
    <!-- =========================
        MODAL CREATE ADMIN
    ========================= -->
    <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.6);">

        <div class="modal-dialog">

            <form method="POST" action="create_admin.php" class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Initialisation système</h5>
                </div>

                <div class="modal-body">

                    <div class="alert alert-warning">
                        Aucun administrateur détecté. Création obligatoire.
                    </div>

                    <!-- USERNAME FORCÉ -->
                    <label>Nom utilisateur</label>
                    <input type="text"
                        class="form-control mb-3"
                        value="admin"
                        disabled>

                    <!-- hidden pour POST -->
                    <input type="hidden" name="username" value="admin">

                    <!-- PASSWORD -->
                    <label>Mot de passe</label>
                    <input type="password" name="password" id="pwd1"
                        class="form-control mb-3" required>

                    <!-- CONFIRM PASSWORD -->
                    <label>Confirmer mot de passe</label>
                    <input type="password" name="password_confirm" id="pwd2"
                        class="form-control mb-3" required>

                    <div id="pwdError" class="text-danger small d-none">
                        Les mots de passe ne correspondent pas
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-success w-100">
                        Créer administrateur
                    </button>
                </div>

            </form>

        </div>
    </div>
<?php endif; ?>
<footer class="text-center py-3 mt-auto">
    <small>
        Supervision GREE - SEMEP - Version <?= htmlspecialchars($_ENV['APP_VERSION'] ?? '') ?>
    </small>
</footer>
</body>
<script>
document.querySelector("form").addEventListener("submit", function(e) {

    const p1 = document.getElementById("pwd1").value;
    const p2 = document.getElementById("pwd2").value;

    if (p1 !== p2) {
        e.preventDefault();
        document.getElementById("pwdError").classList.remove("d-none");
    }
});
</script>
</html>