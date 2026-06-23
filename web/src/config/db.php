<?php

function get_db() {
    $host = getenv('DB_HOST') ?: 'db';
    $dbname = getenv('DB_NAME') ?: 'clim_manager';
    $user = getenv('DB_USER') ?: 'climuser';
    $password = getenv('DB_PASSWORD') ?: 'climpassword';

    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return $pdo;
    } catch (PDOException $e) {
        error_log("Erreur DB : " . $e->getMessage());
        http_response_code(500);
        die("Erreur de connexion à la base de données.");
    }
}