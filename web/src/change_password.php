<?php
require 'auth.php';
require_login();
require 'config/db.php';
$db = get_db();
$userId = $_SESSION['user']['id'];
$error = null;
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($new !== $confirm) {
        $error = "Les nouveaux mots de passe ne correspondent pas.";
    } else {
        $stmt = $db->prepare("
            SELECT password_hash
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($old, $user['password_hash'])) {
            $error = "Ancien mot de passe incorrect.";
        } else {
            $hash = password_hash(
                $new,
                PASSWORD_DEFAULT
            );
            $stmt = $db->prepare("
                UPDATE users
                SET password_hash = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $hash,
                $userId
            ]);
            $_SESSION['flash_success'] = "Mot de passe modifié avec succès.";
            header("Location: index.php");
            exit;
        }
    }
}
    $page_title = "Changement de mot de passe";
    require "includes/header.php";
    require "includes/user_menu.php";
?>

<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow mx-auto" style="max-width:450px">
            <div class="card-header text-center">
                <h4><i class="bi bi-key"></i>Changer mon mot de passe</h4>
            </div>
            <div class="card-body">
                <?php if($error): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>
                <?php if($success): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>
                 <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
                    <label>Ancien mot de passe</label>
                    <input type="password" name="old_password" class="form-control mb-3" required>
                    <label>Nouveau mot de passe</label>
                    <input type="password" name="new_password" class="form-control mb-3" required>
                    <label>Confirmation</label>
                    <input type="password" name="confirm_password" class="form-control mb-3" required>
                    <button class="btn btn-primary w-100">Modifier</button>
                </form>
            </div>
        </div>
    </div>
<?php require "includes/footer.php"; ?>