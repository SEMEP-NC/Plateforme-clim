<?php
require 'auth.php';

session_start();
require_admin();

$file = "/var/www/storage/certificates/root_ca.crt";

if (!file_exists($file)) {
    http_response_code(404);
    die("Certificat introuvable");
}

header('Content-Type: application/x-x509-ca-cert');
header('Content-Disposition: attachment; filename="clim_root_ca.crt"');
header('Content-Length: ' . filesize($file));

readfile($file);
exit;