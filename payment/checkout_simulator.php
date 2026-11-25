<?php
require_once '../auth/auth_check.php';
checkRole(['user', 'donor', 'creator']);
require_once '../config/db.php';

if (!isset($_GET['order_id'])) {
    die("Order ID is missing.");
}

$order_id = trim($_GET['order_id']);
$donor_id = (int) $_SESSION['user_id'];

// Fetch pending order and campaign details
$stmt = $conn->prepare(
    "SELECT d.*, c.title AS campaign_title, c.target_amount, c.collected_amount
     FROM donations d
     JOIN campaigns c ON d.campaign_id = c.id
     WHERE d.sim_order_id = ? AND d.donor_id = ? AND d.payment_status = 'PENDING'"
);
$stmt->bind_param("si", $order_id, $donor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Invalid order or this transaction has already been processed.");
}

$order = $result->fetch_assoc();
$stmt->close();

$remaining = max(0, round((float) $order['target_amount'] - (float) $order['collected_amount'], 2));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Gateway Simulator - FundRaise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card text-dark shadow-lg border-0">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">SecurePay Simulator</h5>
                        <small>Test Mode Environment</small>
                    </div>
                    <span class="badge bg-warning text-dark">SANDBOX</span>
                </div>

                <div class="card-body">
                    <div class="p-3 bg-light rounded mb-3 border">
                        <small class="text-muted d-block">Donating To:</small>
                        <strong class="d-block mb-2"><?php echo htmlspecialchars($order['campaign_title']); ?></strong>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Order ID:</span>
                            <span class="font-monospace small"><?php echo htmlspecialchars($order['sim_order_id']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Campaign Remaining Goal:</span>
                            <span class="small">₹<?php echo number_format($remaining, 2); ?></span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold">Total Payable:</span>
                            <h4 class="text-success mb-0">₹<?php echo number_format((float) $order['amount'], 2); ?></h4>
                        </div>
                    </div>

                    <!-- Dummy Payment Method Tabs (Demo UI only) -->
                    <ul class="nav nav-pills nav-fill mb-3" id="payTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#upi" type="button">UPI</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#card" type="button">Debit/Credit Card</button>
                        </li>
                    </ul>

                    <div class="tab-content mb-4">
                        <div class="tab-pane fade show active" id="upi">
                            <label class="form-label small text-muted">Demo UPI ID</label>
                            <input type="text" class="form-control" value="donor@okicici" readonly>
                        </div>
                        <div class="tab-pane fade" id="card">
                            <label class="form-label small text-muted">Test Card Number</label>
                            <input type="text" class="form-control mb-2" value="4111 •••• •••• 1111" readonly>
                            <div class="row g-2">
                                <div class="col-6"><input type="text" class="form-control" value="12/29" readonly></div>
                                <div class="col-6"><input type="text" class="form-control" value="123" readonly></div>
                            </div>
                        </div>
                    </div>

                    <!-- Server-Side Verification Form -->
                    <form action="verify_simulation.php" method="POST" class="d-grid gap-2">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order['sim_order_id']); ?>">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($order['verification_token']); ?>">

                        <button type="submit" name="action" value="success" class="btn btn-success btn-lg">
                            Simulate Payment Success (₹<?php echo number_format((float) $order['amount'], 2); ?>)
                        </button>
                        <button type="submit" name="action" value="failed" class="btn btn-outline-danger">
                            Simulate Payment Failed / Cancel
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
