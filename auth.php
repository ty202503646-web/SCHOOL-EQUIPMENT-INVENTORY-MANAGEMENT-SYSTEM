
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin(): void
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

function requireRole(array $allowedRoles): void
{
    requireLogin();

    if (!in_array($_SESSION['role_name'] ?? '', $allowedRoles, true)) {
        http_response_code(403);
        exit("Access Denied: You are not authorized to access this page.");
    }
}
