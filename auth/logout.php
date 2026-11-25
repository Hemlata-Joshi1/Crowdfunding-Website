<?php
require_once '../config/security.php';
initSecureSession(false);
sendSecurityHeaders(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    header('Location: ../index.php');
    exit();
}

destroySessionAndCookie();
header('Location: login.php');
exit();
?>
