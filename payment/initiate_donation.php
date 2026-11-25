<?php
require_once '../auth/auth_check.php';
checkRole(['user', 'donor', 'creator']);
require_once '../config/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../index.php");
    exit();
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die("Invalid security token. Please go back, refresh the page, and try again.");
}

$campaign_id = isset($_POST['campaign_id']) ? intval($_POST['campaign_id']) : 0;
$amount = isset($_POST['amount']) ? round(floatval($_POST['amount']), 2) : 0.0;
$donor_id = (int) $_SESSION['user_id'];
$today = date('Y-m-d');

function renderPaymentError(string $message, int $campaignId = 0): void
{
    $backUrl = $campaignId > 0
        ? "../campaigns/campaign_details.php?id=" . $campaignId
        : "../index.php";
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Donation Error</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        . '<body class="bg-light"><div class="container mt-5"><div class="row justify-content-center"><div class="col-md-6">'
        . '<div class="card shadow border-danger"><div class="card-header bg-danger text-white"><h5 class="mb-0">Unable to Process Donation</h5></div>'
        . '<div class="card-body"><p class="mb-3">' . htmlspecialchars($message) . '</p>'
        . '<a href="' . htmlspecialchars($backUrl) . '" class="btn btn-primary">Go Back</a></div></div>'
        . '</div></div></div></body></html>';
    exit();
}

// 1. Validate campaign ID and positive amount
if ($campaign_id <= 0 || $amount < 1) {
    renderPaymentError("Invalid campaign or donation amount. Minimum donation is ₹1.00.", $campaign_id);
}

// 2. Verify campaign is active, not expired, and has sufficient remaining target
$stmt = $conn->prepare("SELECT id, title, target_amount, collected_amount, deadline, status FROM campaigns WHERE id = ? AND status = 'active'");
$stmt->bind_param("i", $campaign_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    renderPaymentError("This campaign is inactive or does not exist.");
}

$campaign = $result->fetch_assoc();
$stmt->close();

if ($campaign['deadline'] < $today) {
    renderPaymentError("This campaign's deadline has expired.", $campaign_id);
}

// 3. Payment overflow check
$remaining = round((float) $campaign['target_amount'] - (float) $campaign['collected_amount'], 2);
if ($remaining <= 0) {
    renderPaymentError("This campaign has already reached its funding goal and cannot accept additional donations.", $campaign_id);
}

if ($amount > $remaining) {
    renderPaymentError(
        "Payment overflow prevented: Your donation of ₹" . number_format($amount, 2)
        . " exceeds the remaining funding needed (₹" . number_format($remaining, 2) . ").",
        $campaign_id
    );
}

// 4. Generate unique simulated Order ID and verification token
$sim_order_id = "SIM_ORD_" . time() . "_" . random_int(1000, 9999);
$verification_token = bin2hex(random_bytes(16));

// 5. Store PENDING order in database
$insertStmt = $conn->prepare(
    "INSERT INTO donations (campaign_id, donor_id, amount, sim_order_id, verification_token, payment_status)
     VALUES (?, ?, ?, ?, ?, 'PENDING')"
);
$insertStmt->bind_param("iidss", $campaign_id, $donor_id, $amount, $sim_order_id, $verification_token);

if ($insertStmt->execute()) {
    header("Location: checkout_simulator.php?order_id=" . urlencode($sim_order_id));
    exit();
} else {
    renderPaymentError("An error occurred while creating your donation order. Please try again.", $campaign_id);
}
?>
