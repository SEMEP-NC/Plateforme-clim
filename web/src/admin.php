<?php
require 'config/db.php';
require 'auth.php';
session_start();
require_admin();

$pdo = get_db();
$db = get_db();

if($_SESSION['user']['role'] !== 'admin'){
        die("Accès réservé administrateur");
    }
$smtp=$db->query("SELECT * FROM mail_accounts WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$recipients=$db->query("SELECT * FROM mail_recipients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$config=$db->query("SELECT * FROM mail_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);

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

$db=get_db();

    /*SAVE SMTP*/   

    if(isset($_POST['save_smtp'])){
        $password=$_POST['smtp_password'];

        if($password==""){
            $password=$smtp['smtp_password'];
        }
        $stmt=$db->prepare("
        UPDATE mail_accounts SET
            smtp_host=?,
            smtp_port=?,
            smtp_user=?,
            smtp_password=?,
            smtp_secure=?,
            sender_name=?,
            sender_email=?,
            enabled=?
        WHERE id=1
        ");

        $stmt->execute([
            $_POST['smtp_host'],
            $_POST['smtp_port'],
            $_POST['smtp_user'],
            $_POST['smtp_password'],
            $_POST['smtp_secure'],
            $_POST['sender_name'],
            $_POST['sender_email'],
            isset($_POST['enabled'])?1:0
        ]);
        header("Location: admin.php");
        exit;
    }

    /*ADD DESTINATION*/

    if(isset($_POST['add_recipient'])){
        $db->prepare("
            INSERT INTO mail_recipients
            (name,email)
            VALUES (?,?)
        ")->execute([
            $_POST['name'],
            $_POST['email']
        ]);
        header("Location: admin.php");
        exit;
    }
    /* MAIL TEST */
    if(isset($_POST['send_test_mail'])){

        $db->prepare("
            INSERT INTO mail_queue(type)
            VALUES('TEST')
        ")->execute();

        header("Location: admin.php");
        exit;
    }


    $page_title = "Administration";
    require "includes/header.php";
    require "includes/user_menu.php";
?>
<style>
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
<main class="container flex-grow-1 mt-4">
    <div class="card mb-4">
        <div class="card-header">
            <strong>Utilisateurs</strong>
        </div>
        <div class="card-body">
            <!-- =========================
                CREATE USER
            ========================= -->
                                
            <form method="POST" action="create_user.php" class="row g-2">
                <div class="col">
                    <input type="text" name="username" class="form-control" placeholder="Username" required>
                </div>
                <div class="col">
                    <input type="password" name="password" class="form-control" placeholder="Password" required>
                </div>
                <div class="col">
                    <select name="role" class="form-control">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                        <option value="viewer">Visualisation</option>
                    </select>
                </div>
                <div class="col">
                    <button class="btn btn-success w-100">Créer</button>
                </div>
            </form>
            

            <!-- =========================
                USERS TABLE
            ========================= -->
            <table class="table">

                <thead>
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
    </div>
    <div class="card mb-4">
        <div class="card-header">
            <strong>Compte SMTP</strong>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row">
                    <div class="col-md-8">
                        <label>Serveur SMTP</label>
                        <input class="form-control" name="smtp_host" value="<?=htmlspecialchars($smtp['smtp_host']??'')?>">
                    </div>
                    <div class="col-md-4">
                        <label>Port</label>
                        <input class="form-control" name="smtp_port" value="<?=htmlspecialchars($smtp['smtp_port']??587)?>">
                    </div>
                </div>
                <label class="mt-3">Utilisateur SMTP</label>
                <input class="form-control" name="smtp_user" value="<?=htmlspecialchars($smtp['smtp_user']??'')?>">

                <label class="mt-3">Mot de passe</label>
                <input type="password" class="form-control" name="smtp_password" placeholder="laisser vide pour conserver">

                <label class="mt-3">Sécurité</label>
                <select class="form-select" name="smtp_secure">
                    <option value="tls">TLS</option>
                    <option value="ssl">SSL</option>
                    <option value="none">Aucune</option>
                </select>

                <label class="mt-3">Nom expéditeur</label>
                <input class="form-control" name="sender_name" value="<?=htmlspecialchars($smtp['sender_name']??'')?>">

                <label class="mt-3">Email expéditeur</label>
                <input class="form-control" name="sender_email" value="<?=htmlspecialchars($smtp['sender_email']??'')?>">

                <div class="form-check mt-3">
                    <input 
                    class="form-check-input"
                    type="checkbox"
                    name="enabled"
                    <?=($smtp['enabled']??0)?'checked':''?>
                    >
                    <label>Activer les envois</label>
                </div>
                <br>
                <button class="btn btn-success" name="save_smtp">💾 Sauvegarder</button>
            </form>
            <form method="POST" class="mt-3">
                <button
                    type="submit"
                    name="send_test_mail"
                    class="btn btn-outline-primary">
                    Envoyer un mail de test
                </button>
            </form>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header">
            <strong>Destinataires mail</strong>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-2">
                <div class="col">
                    <input class="form-control" name="name" placeholder="Nom">
                </div>
                <div class="col">
                    <input class="form-control" name="email" placeholder="Email">
                </div>
                <div class="col">
                    <button class="btn btn-primary"name="add_recipient">Ajouter</button>
                </div>
            </form>
            <hr>
            <table class="table">
                <tr>
                    <th>Nom</th>
                    <th>Email</th>
                    <th>Actif</th>
                </tr>
                <?php foreach($recipients as $r): ?>
                    <tr>
                        <td><?=htmlspecialchars($r['name'])?></td>
                        <td><?=htmlspecialchars($r['email'])?></td>
                        <td><?= $r['enabled']?'Oui':'Non' ?></td>
                    </tr>
                <?php endforeach; ?>    
            </table>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header">
            <strong>Fonction avancées</strong>
        </div>
        <div class="card-body">
            <a href="journal.php"class="btn btn-outline-secondary">
            </i>Journal d'audit</a>
        </div>
        <div class="card-body">
            <a href="temperature_alarms.php"class="btn btn-outline-secondary">
            </i>Alarmes température</a>
        </div>
    </div>
</main>
<?php require "includes/footer.php"; ?>