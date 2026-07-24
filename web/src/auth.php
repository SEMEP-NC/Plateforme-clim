<?php
if(session_status() === PHP_SESSION_NONE){
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => false, // passer à true avec HTTPS
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

function require_login(){
    if(empty($_SESSION['user'])){
        header("Location: login.php");
        exit;
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
    if(empty($_POST['csrf']) || empty($_SESSION['csrf']) ||!hash_equals($_SESSION['csrf'],$_POST['csrf'])){
        http_response_code(403);
        die("Token CSRF invalide");
    }
}

