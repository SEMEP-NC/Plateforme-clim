<?php

function audit($action, $description = '', $targetType = null, $targetId = null)
{
    $pdo = get_db();

    $user = $_SESSION['user'] ?? [];

    $stmt = $pdo->prepare("
        INSERT INTO audit_logs
        (
            user_id,
            username,
            action,
            target_type,
            target_id,
            description,
            ip_address,
            user_agent
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $user['id'] ?? null,
        $user['username'] ?? 'anonymous',
        $action,
        $targetType,
        $targetId,
        $description,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
}