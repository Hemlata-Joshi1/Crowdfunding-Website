<?php
require_once '../config/db.php';
initSecureSession(true);
sendSecurityHeaders(true);

$evidenceId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($evidenceId <= 0) {
    http_response_code(400);
    die('Invalid evidence ID.');
}

$stmt = $conn->prepare(
    "SELECT ce.file_name, ce.file_path, c.creator_id, c.status
     FROM campaign_evidences ce
     JOIN campaigns c ON ce.campaign_id = c.id
     WHERE ce.id = ?"
);
$stmt->bind_param("i", $evidenceId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    die('Supporting evidence not found.');
}

$isOwner = isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $row['creator_id'];
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if ($row['status'] !== 'active' && !$isOwner && !$isAdmin) {
    http_response_code(403);
    die('Access Denied: You do not have permission to view this supporting document.');
}

$baseDir = realpath(dirname(__DIR__) . '/uploads/evidences');
$targetPath = realpath(dirname(__DIR__) . '/' . ltrim((string) $row['file_path'], '/'));

if ($baseDir === false || $targetPath === false || !str_starts_with($targetPath, $baseDir . DIRECTORY_SEPARATOR) || !is_file($targetPath)) {
    http_response_code(404);
    die('File not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($targetPath);
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(403);
    die('Unsupported file type.');
}

$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $row['file_name']));
if ($safeName === '' || $safeName === null) {
    $safeName = 'evidence';
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($targetPath));
header('X-Content-Type-Options: nosniff');
readfile($targetPath);
exit();
