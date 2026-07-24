<?php
require 'auth.php';
require_admin();
require 'config/db.php';
require 'lib/audit.php';
$db = get_db();
$id = intval($_POST['id'] ?? 0);
verify_csrf();
if(!$id){
    die("Utilisateur invalide");
}

/*
    Vérification utilisateur
*/
$stmt=$db->prepare("
    SELECT username
    FROM users
    WHERE id=?
");
$stmt->execute([$id]);
$user=$stmt->fetch();
if(!$user){
    die("Utilisateur introuvable");
}

/*
    Génération mot de passe temporaire
*/
function generate_password($length = 8)
{
    $chars =
        'ABCDEFGHJKLMNPQRSTUVWXYZ'
        .'abcdefghijkmnopqrstuvwxyz'
        .'23456789';
    return substr(
        str_shuffle($chars),
        0,
        $length
    );
}

$new_password = generate_password();
$hash=password_hash(
    $new_password,
    PASSWORD_DEFAULT
);

/*
    Mise à jour
*/
$stmt=$db->prepare("
    UPDATE users
    SET password_hash=?
    WHERE id=?
");

$stmt->execute([
    $hash,
    $id
]);
audit(
        'RESET_PASSWORD',
        "reinitialisation du mot de passe de " . $user['username']);
$page_title = "Reset mot de passe";
require "includes/header.php";
require "includes/user_menu.php";

?>

<div class="container mt-5">
    <div class="alert alert-success">
        <h4>Mot de passe réinitialisé</h4>
        Utilisateur :
        <b><?= htmlspecialchars($user['username']) ?></b>
        <br>
        <br>
        Nouveau mot de passe :
        <div class="alert alert-warning">
            <h3><?= htmlspecialchars($new_password) ?></h3>
        </div>
        <p>Notez ce mot de passe avant de fermer cette page.</p>
        <a href="admin.php" class="btn btn-primary">Retour administration</a>
    </div>
</div>
<?php require "includes/footer.php"; ?>