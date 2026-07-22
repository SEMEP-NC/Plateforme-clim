<?php
    require_once __DIR__ . '/../auth.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_login();
    $user_session = $_SESSION['user'];


    if (isset($_SESSION['user']['role']) && 
        $_SESSION['user']['role']=='viewer' && 
        $page_title != "Vue des equipements" && 
        $page_title = "Changement de mot de passe"
        ) {
            header("Location: login.php");
            exit;
        }
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<link rel="icon" href="images/favicon-gree.ico?v=2" type="image/x-icon">
<title><?= $page_title ?? 'Supervision Climatisation' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
body {
    background:#f5f7fa;
}
.logo {
    height:50px;
    width:auto;
    object-fit:contain;
}
.card {
    border:none;
    border-radius:15px;
    box-shadow:0 4px 15px rgba(0,0,0,.08);
}
</style>

</head>
<body class="vh-100 d-flex flex-column">
<header class="bg-white shadow-sm py-3">
    <div class="container position-relative">
        <img src="images/logo_semep.png"
            class="logo position-absolute top-50 start-0 translate-middle-y"
            alt="SEMEP">
        <div class="text-center">
            <h1 class="fw-bold mb-1">
                <?= $page_title ?? 'Supervision Climatisation' ?>
            </h1>
            <small class="text-muted">Gestion des équipements</small>
        </div>
        <img src="images/Gree-Electric-logo.png"
        class="logo position-absolute top-50 end-0 translate-middle-y"
        alt="GREE">
    </div>
</header>