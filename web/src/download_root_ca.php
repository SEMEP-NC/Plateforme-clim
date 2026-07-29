<?php

require 'auth.php';
require_admin();

$file = "/var/www/storage/certificates/root_ca.crt";

if (!is_readable($file)) {
    http_response_code(404);
    die("Certificat CA indisponible");
}

header('Content-Type: application/x-x509-ca-cert');
header('Content-Disposition: attachment; filename="clim_root_ca.crt"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store');

readfile($file);
exit;