<?php
require_once '../auth/auth_check.php';
checkRole(['user', 'donor', 'creator', 'admin']);
require_once '../config/db.php';

if (!isset($_GET['order_id'])) {
    header("Location: ../index.php");
    exit();
}

$order_id = trim($_GET['order_id']);
$user_id = (int) $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$reason = isset($_GET['reason']) ? trim($_GET['reason']) : '';

$sql = "SELECT d.*, c.title AS campaign_title
        FROM donations d
        JOIN campaigns c ON d.campaign_id = c.id
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
    die("Donation receipt not found.");
}

$receipt = $result->fetch_assoc();
$stmt->close();

$is_success = ($receipt['payment_status'] === 'SUCCESS');
$is_pending = ($receipt['payment_status'] === 'PENDING');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status - FundRaise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow text-center">
                <div class="card-header <?php echo $is_success ? 'bg-success' : ($is_pending ? 'bg-warning text-dark' : 'bg-danger'); ?> text-white">
                    <h4 class="mb-0">
                        <?php
                        if ($is_success) {
                            echo 'Donation Successful!';
                        } elseif ($is_pending) {
                            echo 'Payment Pending';
                        } else {
                            echo 'Payment Failed!';
                        }
                        ?>
                    </h4>
                </div>
                <div class="card-body">
                    <p class="lead">
                        <?php
                        if ($is_success) {
                            echo 'Thank you! Your contribution has been verified and added to the campaign total.';
                        } elseif ($reason === 'overflow') {
                            echo 'Payment cancelled due to goal overflow: This donation exceeded the remaining target amount for the campaign, so no funds were added.';
                        } elseif ($reason === 'inactive') {
                            echo 'Payment cancelled: This campaign is no longer active or its deadline has expired.';
                        } elseif ($is_pending) {
                            echo 'This transaction is still pending verification.';
                        } else {
                            echo 'The transaction was cancelled or failed. No amount has been added to the campaign.';
                        }
                        ?>
                    </p>

                    <table class="table table-bordered text-start mt-3">
                        <tr>
                            <th>Campaign</th>
                            <td><?php echo htmlspecialchars($receipt['campaign_title']); ?></td>
                        </tr>
                        <tr>
                            <th>Amount</th>
                            <td class="fw-bold">₹<?php echo number_format((float) $receipt['amount'], 2); ?></td>
                        </tr>
                        <tr>
                            <th>Order ID</th>
                            <td class="font-monospace small"><?php echo htmlspecialchars($receipt['sim_order_id']); ?></td>
                        </tr>
                        <tr>
                            <th>Payment ID</th>
                            <td class="font-monospace small"><?php echo $receipt['sim_payment_id'] ? htmlspecialchars($receipt['sim_payment_id']) : 'N/A'; ?></td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <span class="badge <?php echo $is_success ? 'bg-success' : ($is_pending ? 'bg-warning text-dark' : 'bg-danger'); ?>">
                                    <?php echo htmlspecialchars($receipt['payment_status']); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Date &amp; Time</th>
                            <td><?php echo htmlspecialchars((string) $receipt['payment_date']); ?></td>
                        </tr>
                    </table>

                    <div class="mt-4 d-flex justify-content-center gap-2 flex-wrap">
                        <a href="download_receipt.php?order_id=<?php echo urlencode($receipt['sim_order_id']); ?>" class="btn btn-danger">
                            Download PDF Receipt
                        </a>
                        <a href="../index.php" class="btn btn-primary">Back to Home</a>
                        <?php if ($is_admin): ?>
                            <a href="../admin/dashboard.php" class="btn btn-outline-secondary">Admin Panel</a>
                        <?php else: ?>
                            <a href="../dashboard/user_dashboard.php#my-donations" class="btn btn-outline-secondary">My Donations</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
