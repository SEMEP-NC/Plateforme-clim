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

        header("Location: index.php");
        exit;
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

<div class="container d-flex justify-content-center align-items-center" style="height:100vh;">

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

                <label>Nom admin</label>
                <input type="text" name="username" class="form-control mb-3" required>

                <label>Mot de passe</label>
                <input type="password" name="password" class="form-control mb-3" required>

            </div>

            <div class="modal-footer">
                <button class="btn btn-success w-100">
                    Créer administrateur
                </button>
            </div>

        </form>

    </div>
</div>
<?php endif; ?>

</body>
</html>