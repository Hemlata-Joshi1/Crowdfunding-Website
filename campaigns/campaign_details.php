<?php
require_once '../config/db.php';
require_once '../auth/auth_check.php';
sendSecurityHeaders(false);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Campaign ID.");
}

$campaign_id = intval($_GET['id']);
$stmt = $conn->prepare(
    "SELECT c.*, u.name AS creator_name
     FROM campaigns c
     JOIN users u ON c.creator_id = u.id
     WHERE c.id = ?"
);
$stmt->bind_param("i", $campaign_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Campaign not found.");
}

$campaign = $result->fetch_assoc();
$stmt->close();

$is_owner = (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $campaign['creator_id']);
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

if ($campaign['status'] !== 'active' && !$is_owner && !$is_admin) {
    die("This campaign is currently inactive or has not been approved yet.");
}

// Fetch supporting evidences
$evStmt = $conn->prepare("SELECT * FROM campaign_evidences WHERE campaign_id = ? ORDER BY uploaded_at ASC");
$evStmt->bind_param("i", $campaign_id);
$evStmt->execute();
$evidences = $evStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$evStmt->close();

$target_amount = (float) $campaign['target_amount'];
$collected_amount = (float) $campaign['collected_amount'];
$remaining_amount = max(0, round($target_amount - $collected_amount, 2));
$percentage = ($target_amount > 0) ? min(100, round(($collected_amount / $target_amount) * 100)) : 0;
$is_expired = ($campaign['deadline'] < date('Y-m-d'));
$goal_reached = ($remaining_amount <= 0);
$min_donation = ($remaining_amount > 0 && $remaining_amount < 10) ? $remaining_amount : 10;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($campaign['title']); ?> - FundRaise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="../index.php" class="btn btn-secondary btn-sm">&larr; Back to Campaigns</a>
        <?php if (isset($_SESSION['user_id']) && $_SESSION['role'] !== 'admin'): ?>
            <a href="../dashboard/user_dashboard.php" class="btn btn-outline-primary btn-sm">My Dashboard</a>
        <?php endif; ?>
    </div>

    <?php if ($campaign['status'] === 'pending'): ?>
        <div class="alert alert-warning">
            <strong>Status: INACTIVE (Pending Admin Approval)</strong> — Only you and Admins can view this preview until it is approved.
        </div>
    <?php elseif ($campaign['status'] === 'paused'): ?>
        <div class="alert alert-secondary">
            <strong>Status: PAUSED</strong> — This campaign has been temporarily paused by an Admin and is not accepting donations.
        </div>
    <?php elseif ($campaign['status'] === 'rejected'): ?>
        <div class="alert alert-danger">
            <strong>Status: REJECTED</strong> — This campaign request was rejected by an Admin and is not visible publicly.
        </div>
    <?php elseif ($campaign['status'] === 'closed'): ?>
        <div class="alert alert-secondary">
            <strong>Status: CLOSED</strong> — This campaign is no longer active.
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <img src="../<?php echo htmlspecialchars($campaign['image_path']); ?>" class="card-img-top" style="max-height: 400px; object-fit: cover;" alt="Campaign Image">
                <div class="card-body">
                    <span class="badge bg-info text-dark mb-2"><?php echo htmlspecialchars($campaign['category']); ?></span>
                    <h2><?php echo htmlspecialchars($campaign['title']); ?></h2>
                    <p class="text-muted">Created by <strong><?php echo htmlspecialchars($campaign['creator_name']); ?></strong> | Deadline: <strong><?php echo htmlspecialchars($campaign['deadline']); ?></strong></p>
                    <hr>
                    <p style="white-space: pre-line;"><?php echo htmlspecialchars($campaign['description']); ?></p>
                </div>
            </div>

            <?php if (!empty($evidences)): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-bold">Supporting Evidences &amp; Documents</div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($evidences as $ev): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span><?php echo htmlspecialchars($ev['file_name']); ?></span>
                            <a href="view_evidence.php?id=<?php echo (int) $ev['id']; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">View File</a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h4>₹<?php echo number_format($collected_amount, 2); ?></h4>
                    <p class="text-muted mb-2">raised of ₹<?php echo number_format($target_amount, 2); ?> goal</p>

                    <div class="progress mb-2" style="height: 15px;">
                        <div class="progress-bar bg-success" style="width: <?php echo $percentage; ?>%;"><?php echo $percentage; ?>%</div>
                    </div>
                    <p class="small text-muted mb-3">
                        Remaining needed: <strong>₹<?php echo number_format($remaining_amount, 2); ?></strong>
                    </p>

                    <?php if ($campaign['status'] !== 'active'): ?>
                        <div class="alert alert-secondary mb-0">This campaign is inactive and cannot accept donations right now.</div>
                    <?php elseif ($goal_reached): ?>
                        <div class="alert alert-success mb-0">
                            <strong>Funding Goal Reached!</strong> Thank you to all supporters. No further donations are needed.
                        </div>
                    <?php elseif ($is_expired): ?>
                        <div class="alert alert-warning mb-0">This campaign's deadline has expired.</div>
                    <?php elseif (!isset($_SESSION['user_id'])): ?>
                        <div class="alert alert-info mb-0">Please <a href="../auth/login.php" class="alert-link">Login</a> to make a donation.</div>
                    <?php elseif (!isStandardUser()): ?>
                        <div class="alert alert-secondary mb-0">Admin accounts cannot place donations.</div>
                    <?php else: ?>
                        <form action="../payment/initiate_donation.php" method="POST">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign['id']; ?>">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Donation Amount (₹)</label>
                                <input
                                    type="number"
                                    name="amount"
                                    min="<?php echo htmlspecialchars((string) $min_donation); ?>"
                                    max="<?php echo htmlspecialchars((string) $remaining_amount); ?>"
                                    step="0.01"
                                    class="form-control"
                                    placeholder="Enter amount (max ₹<?php echo number_format($remaining_amount, 2); ?>)"
                                    required
                                >
                                <div class="form-text">
                                    Maximum allowed donation: ₹<?php echo number_format($remaining_amount, 2); ?>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success w-100 btn-lg">Proceed to Pay</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
