<?php
require 'auth.php';
session_start();
require_login();
require 'config/db.php';
$db = get_db();

$id = intval($_GET['id'] ?? 0);

$stmt=$db->prepare(
"SELECT * FROM documents WHERE id=?"
);

$stmt->execute([$id]);

$doc=$stmt->fetch();

if(!$doc){
    die("Document introuvable");
}
$file =
"documents/uploads/".$doc['filename'];

if(!file_exists($file)){
    die("Fichier absent");
}

header(
"Content-Type: ".$doc['mime_type']
);

header(
"Content-Length: ".$doc['file_size']
);

header(
'Content-Disposition: attachment; filename="'.
$doc['original_name'].'"'
);

readfile($file);

exit;