<?php
require_once '../auth/auth_check.php';
checkRole(['user', 'donor', 'creator', 'admin']);
require_once '../config/db.php';
require_once '../utils/pdf_generator.php';

if (!isset($_GET['order_id']) || trim($_GET['order_id']) === '') {
    http_response_code(400);
    die("Missing order ID.");
}

$order_id = trim($_GET['order_id']);
$user_id = (int) $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

$sql = "SELECT d.*, c.title AS campaign_title, c.category,
               donor.name AS donor_name, donor.email AS donor_email,
               creator.name AS creator_name
        FROM donations d
        JOIN campaigns c ON d.campaign_id = c.id
        JOIN users donor ON d.donor_id = donor.id
        JOIN users creator ON c.creator_id = creator.id
        WHERE d.sim_order_id = ?";

if ($is_admin) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $order_id);
} else {
    $sql .= " AND d.donor_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $order_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    die("Donation receipt not found.");
}

$receipt = $result->fetch_assoc();
$stmt->close();

$pdfBinary = buildDonationReceiptPdf($receipt);
$safeFilename = 'receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $receipt['sim_order_id']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Content-Length: ' . strlen($pdfBinary));
header('Cache-Control: private, max-age=0, must-revalidate');

echo $pdfBinary;
exit();
