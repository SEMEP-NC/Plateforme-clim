<?php
if(session_status() === PHP_SESSION_NONE){
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true, // passer à true avec HTTPS
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

$SESSION_TIMEOUT = 3600;

if(isset($_SESSION['LAST_ACTIVITY'])){
    if(time() - $_SESSION['LAST_ACTIVITY'] > $SESSION_TIMEOUT){
        session_unset();
        session_destroy();
        header("Location: login.php?timeout=1");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY']=time();

header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin");

function current_user(){
    return $_SESSION['user'] ?? null;
}

function require_login_view(){
    if (
        !isset($_SESSION['user']) ||
        !isset($_SESSION['user']['role'])
    ) {
        header("Location: login.php");
        exit;
    }
}

function require_login(){
    require_login_view();
    $role = $_SESSION['user']['role'];

    if (!in_array($role, ['user', 'admin'])) {
        http_response_code(403);
        die("Accès refusé");
    }
}

function require_admin(){
    require_login();
    if($_SESSION['user']['role'] !== 'admin'){
        http_response_code(403);
        die("Accès refusé");
    }
}

function csrf_token(){
    if(empty($_SESSION['csrf'])){
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(){
    if(empty($_POST['csrf_token']) || empty($_SESSION['csrf']) ||!hash_equals($_SESSION['csrf'],$_POST['csrf_token'])){
        http_response_code(403);
        die("Token CSRF invalide");
    }
}

