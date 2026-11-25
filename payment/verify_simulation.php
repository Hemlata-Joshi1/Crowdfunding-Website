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
    die("Security verification failed: Invalid CSRF token.");
}

$order_id = isset($_POST['order_id']) ? trim($_POST['order_id']) : '';
$token = isset($_POST['token']) ? trim($_POST['token']) : '';
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$donor_id = (int) $_SESSION['user_id'];
$today = date('Y-m-d');

$conn->begin_transaction();

try {
    // 1. Lock PENDING donation row to prevent duplicate or concurrent verification
    $stmt = $conn->prepare(
        "SELECT * FROM donations WHERE sim_order_id = ? AND donor_id = ? AND payment_status = 'PENDING' FOR UPDATE"
    );
    $stmt->bind_param("si", $order_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $conn->rollback();
        die("Error: Transaction is invalid or has already been verified.");
    }

    $donation = $result->fetch_assoc();
    $stmt->close();

    // 2. Verify security token
    if (!hash_equals($donation['verification_token'], $token)) {
        $conn->rollback();
        die("Security Verification Failed: Invalid Token!");
    }

    if ($action === 'success') {
        // 3. Lock campaign row and re-check status, deadline, and remaining target (overflow protection)
        $campStmt = $conn->prepare(
            "SELECT id, target_amount, collected_amount, status, deadline FROM campaigns WHERE id = ? FOR UPDATE"
        );
        $campStmt->bind_param("i", $donation['campaign_id']);
        $campStmt->execute();
        $campaign = $campStmt->get_result()->fetch_assoc();
        $campStmt->close();

        if (!$campaign || $campaign['status'] !== 'active' || $campaign['deadline'] < $today) {
            $failDonation = $conn->prepare(
                "UPDATE donations SET payment_status = 'FAILED', payment_date = NOW() WHERE id = ?"
            );
            $failDonation->bind_param("i", $donation['id']);
            $failDonation->execute();
            $failDonation->close();
            $conn->commit();

            header("Location: payment_result.php?order_id=" . urlencode($order_id) . "&reason=inactive");
            exit();
        }

        $remaining = round((float) $campaign['target_amount'] - (float) $campaign['collected_amount'], 2);
        $donationAmount = round((float) $donation['amount'], 2);

        if ($remaining <= 0 || $donationAmount > $remaining) {
            // Prevent funding overflow when concurrent or stale orders are submitted
            $failDonation = $conn->prepare(
                "UPDATE donations SET payment_status = 'FAILED', payment_date = NOW() WHERE id = ?"
            );
            $failDonation->bind_param("i", $donation['id']);
            $failDonation->execute();
            $failDonation->close();
            $conn->commit();

            header("Location: payment_result.php?order_id=" . urlencode($order_id) . "&reason=overflow");
            exit();
        }

        $sim_payment_id = "SIM_PAY_" . strtoupper(bin2hex(random_bytes(5)));

        // 4. Mark donation as SUCCESS
        $updateDonation = $conn->prepare(
            "UPDATE donations SET sim_payment_id = ?, payment_status = 'SUCCESS', payment_date = NOW() WHERE id = ?"
        );
        $updateDonation->bind_param("si", $sim_payment_id, $donation['id']);
        $updateDonation->execute();
        $updateDonation->close();

        // 5. Update campaign collected_amount
        $updateCampaign = $conn->prepare(
            "UPDATE campaigns SET collected_amount = collected_amount + ? WHERE id = ?"
        );
        $updateCampaign->bind_param("di", $donationAmount, $donation['campaign_id']);
        $updateCampaign->execute();
        $updateCampaign->close();

        $conn->commit();
    } else {
        // Failed / cancelled payment
        $failDonation = $conn->prepare(
            "UPDATE donations SET payment_status = 'FAILED', payment_date = NOW() WHERE id = ?"
        );
        $failDonation->bind_param("i", $donation['id']);
        $failDonation->execute();
        $failDonation->close();

        $conn->commit();
    }

    header("Location: payment_result.php?order_id=" . urlencode($order_id));
    exit();
} catch (Throwable $e) {
    $conn->rollback();
    die("Transaction processing failed. Please try again.");
}
?>
