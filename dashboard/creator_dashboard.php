<?php
require_once '../auth/auth_check.php';
checkRole(['user', 'donor', 'creator']);
header("Location: user_dashboard.php#my-campaigns");
exit();
?>
