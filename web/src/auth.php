<?php

function require_login() {
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
        exit;
    }
}

function require_admin() {
    require_login();

    if ($_SESSION['user']['role'] !== 'admin') {
        http_response_code(403);
        die("Accès refusé (admin uniquement)");
    }
}