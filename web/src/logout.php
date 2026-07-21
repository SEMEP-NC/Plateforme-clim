<?php
session_start();
        audit(
            'LOGOUT',
            'Connexion fermée'
        );
session_destroy();

header("Location: login.php");
exit;