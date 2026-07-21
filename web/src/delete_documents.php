<?php
require 'auth.php';
session_start();
require_login();
require 'config/db.php';
$db = get_db();

$user=$_SESSION['user'];

if($user['role'] !== 'admin'){
    die("Accès refusé");
}
$id=intval($_GET['id'] ?? 0);
$stmt=$db->prepare(
"SELECT filename FROM documents WHERE id=?"
);
$stmt->execute([$id]);
$doc=$stmt->fetch();
if(!$doc){
    die("Document introuvable");
}
$file =
"documents/uploads/".$doc['filename'];

if(file_exists($file)){
    unlink($file);
}

$stmt=$db->prepare(
"DELETE FROM documents WHERE id=?"
);
$stmt->execute([$id]);

header(
"Location: documents.php?deleted=1"
);

exit;