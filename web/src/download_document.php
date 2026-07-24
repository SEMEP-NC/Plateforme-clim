<?php

require 'auth.php';
session_start();
require_login();
require 'config/db.php';
$db = get_db();
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Document invalide");
}

$stmt = $db->prepare(
    "
    SELECT 
        filename,
        original_name,
        mime_type
    FROM documents
    WHERE id = ?
    "
);
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    die("Document introuvable");
}

// stockage hors webroot
$file_path = "/var/www/storage/documents/" . $doc['filename'];

if (!file_exists($file_path)) {
    die("Fichier absent");
}
// Sécurisation des headers
header(
    "Content-Type: " . $doc['mime_type']
);
header(
    "Content-Disposition: inline; filename=\"" .
    basename($doc['original_name']) .
    "\""
);
header(
    "Content-Length: " . filesize($file_path)
);
readfile($file_path);
exit;