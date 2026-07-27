<?php
require 'auth.php';

require_admin();
require 'config/db.php';
$db = get_db();
require 'lib/audit.php';
$user=current_user();

if($user['role'] !== 'admin'){
    die("Accès refusé");
}
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    http_response_code(405);
    exit("Méthode non autorisée");
}

verify_csrf();

if(empty($_POST['id']) || !is_numeric($_POST['id'])){
    exit("ID invalide");
}
$id = (int)$_POST['id'];

$stmt=$db->prepare(
"SELECT title, filename FROM documents WHERE id=?"
);
$stmt->execute([$id]);
$doc=$stmt->fetch();
if(!$doc){
    die("Document introuvable");
}
$file = "/var/www/storage/documents/" . $doc['filename'];

if(file_exists($file)){
    unlink($file);
}

$stmt=$db->prepare(
"DELETE FROM documents WHERE id=?"
);
$stmt->execute([$id]);
audit(
        'DELETE_DOCUMENT',
        "Suppression du document " . $doc['title']);
header(
"Location: documents.php?deleted=1"
);

exit;