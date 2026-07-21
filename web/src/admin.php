<?php
require 'config/db.php';
require 'auth.php';
session_start();
require_admin();

$pdo = get_db();

if($_SESSION['user']['role'] !== 'admin'){
        die("Accès réservé administrateur");
    }

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

/*
|-----------------------------
| Journal
|-----------------------------
*/
$journal = $pdo->query("
    SELECT *
    FROM audit_logs
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$db=get_db();

    /*SAVE SMTP*/   

    if(isset($_POST['save_smtp'])){

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

    $smtp=$db->query("SELECT * FROM mail_accounts WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $recipients=$db->query("SELECT * FROM mail_recipients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $config=$db->query("SELECT * FROM mail_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Administration</title>
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
                    <input type="password" class="form-control" name="smtp_password" value="<?=htmlspecialchars($smtp['smtp_password']??'')?>">

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
                    <!--<button
                        type="submit"
                        name="send_test_mail"
                        class="btn btn-outline-primary">
                        Envoyer un mail de test
                    </button> -->
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
        <!-- Journal -->
        <div class="container mt-4">
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Journal</strong>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <select name="equipment_id" class="form-select">
                                <option value="">Tous les utilisateurs</option>
                                <?php foreach($users as $u): ?>
                                    <option value="<?= htmlspecialchars($users['username']) ?>"></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary">Filtrer</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Utilisateur</th>
                                    <th>Action</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($journal as $j): ?>
                                    <tr>
                                        <td>
                                            <?php
                                                $date = new DateTime($j['created_at'], new DateTimeZone('UTC'));
                                                $date->setTimezone(new DateTimeZone('Pacific/Noumea'));
                                                echo $date->format('d/m/Y H:i:s');
                                            ?>
                                        </td>
                                        <td><?= htmlspecialchars($j['user_name']) ?></td>
                                        <td><?= htmlspecialchars($f['action']) ?></td>
                                        <td><?= htmlspecialchars($f['description']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="text-center py-3 bg-white shadow-sm mt-auto">
        <small>Supervision GREE - SEMEP - Version <?= htmlspecialchars($_ENV['APP_VERSION'] ?? '') ?></small>
    </footer>
</body>
</html>