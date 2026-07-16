<?php

    require 'auth.php';
    session_start();
    require_login();

    require 'config/db.php';

    $db=get_db();


    if($_SESSION['user']['role'] !== 'admin'){
        die("Accès réservé administrateur");
    }

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
        header("Location: mail_settings.php");
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
        header("Location: mail_settings.php");
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
    <title>Configuration Mail</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-5">
    <h1>Configuration des alertes mail</h1>
    <a href="index.php" class="btn btn-secondary mb-3">Retour</a>
    <div class="card mb-4">
        <div class="card-header">
            Compte SMTP
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
            </form>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header">Destinataires</div>
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
    <div class="card">
        <div class="card-header">Paramètres alarmes</div>
        <div class="card-body">
            <div>
                Défaut :<b><?= $config['enable_alarm']?'Activé':'Désactivé' ?></b>
            </div>
            <div>
                Retour normal :<b><?= $config['enable_return']?'Activé':'Désactivé' ?></b>
            </div>
            <div>
                Délai :<b><?=$config['delay_seconds']?> secondes</b>
            </div>
        </div>
    </div>
</body>
</html>