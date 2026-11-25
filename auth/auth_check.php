<?php
require_once dirname(__DIR__) . '/config/security.php';

initSecureSession(true);
sendSecurityHeaders(true);

function isStandardUser(?string $role = null): bool
{
    $r = $role ?? ($_SESSION['role'] ?? '');
    return in_array($r, ['user', 'donor', 'creator'], true);
}

function checkRole(array $allowed_roles = []): void
{
    // 1. Verify the user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: /crowdfunding/auth/login.php");
        exit();
    }

    // 2. Verify role authorization when specific roles are required
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles, true)) {
        http_response_code(403);
        die("<h3 style='color:red; text-align:center; margin-top:50px;'>Access Denied: You do not have permission to view this page.</h3>");
    }
}
?>
