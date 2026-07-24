<?php
require 'auth.php';

require 'lib/audit.php';
require 'config/db.php';
audit(
    'LOGOUT',
    'Connexion fermée'
);
session_unset();
session_destroy();

header("Location: login.php");
exit;