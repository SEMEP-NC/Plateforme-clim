<?php
session_start();
require 'lib/audit.php';
require 'config/db.php';
audit(
    'LOGOUT',
    'Connexion fermée'
);
session_destroy();

header("Location: login.php");
exit;