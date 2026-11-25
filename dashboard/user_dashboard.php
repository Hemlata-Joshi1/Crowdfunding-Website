<?php
require_once '../auth/auth_check.php';
checkRole(['user', 'donor', 'creator']);
require_once '../config/db.php';

$user_id = (int) $_SESSION['user_id'];

// 1. Fetch user's donation history ("My Donations")
$donStmt = $conn->prepare(
    "SELECT d.*, c.title AS campaign_title, c.category
     FROM donations d
     JOIN campaigns c ON d.campaign_id = c.id
     WHERE d.donor_id = ?
     ORDER BY d.payment_date DESC"
);
$donStmt->bind_param("i", $user_id);
$donStmt->execute();
$my_donations = $donStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$donStmt->close();

// 2. Fetch user's created fundraising campaigns ("My Campaigns")
$campStmt = $conn->prepare(
    "SELECT * FROM campaigns WHERE creator_id = ? ORDER BY created_at DESC"
);
$campStmt->bind_param("i", $user_id);
$campStmt->execute();
$my_campaigns = $campStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$campStmt->close();

// 3. Fetch supporting evidences for user's campaigns
$evidencesByCampaign = [];
if (!empty($my_campaigns)) {
    $evStmt = $conn->prepare(
        "SELECT ce.*
         FROM campaign_evidences ce
         JOIN campaigns c ON ce.campaign_id = c.id
         WHERE c.creator_id = ?
         ORDER BY ce.uploaded_at ASC"
    );
    $evStmt->bind_param("i", $user_id);
    $evStmt->execute();
    $evRows = $evStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $evStmt->close();

    foreach ($evRows as $ev) {
        $evidencesByCampaign[(int) $ev['campaign_id']][] = $ev;
    }
}

// 4. Fetch verified supporters on user's campaigns
$supStmt = $conn->prepare(
    "SELECT d.*, c.title AS campaign_title, u.name AS donor_name
     FROM donations d
     JOIN campaigns c ON d.campaign_id = c.id
     JOIN users u ON d.donor_id = u.id
     WHERE c.creator_id = ? AND d.payment_status = 'SUCCESS'
     ORDER BY d.payment_date DESC"
);
$supStmt->bind_param("i", $user_id);
$supStmt->execute();
$supporters = $supStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$supStmt->close();

// Summary metrics
$total_donated = 0.0;
$successful_donations = 0;
foreach ($my_donations as $d) {
    if ($d['payment_status'] === 'SUCCESS') {
        $total_donated += (float) $d['amount'];
        $successful_donations++;
    }
}

$total_raised = 0.0;
foreach ($my_campaigns as $c) {
    $total_raised += (float) $c['collected_amount'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - FundRaise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="../index.php">FundRaise - My Dashboard</a>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="../campaigns/create_campaign.php" class="btn btn-warning btn-sm">+ Create Campaign</a>
            <a href="../index.php" class="btn btn-outline-light btn-sm">Explore Campaigns</a>
            <form method="POST" action="../auth/logout.php" class="d-inline">
                <?php echo csrfInput(); ?>
                <button type="submit" class="btn btn-danger btn-sm">Logout</button>
            </form>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h3 class="mb-0">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></h3>
            <small class="text-muted">Manage your donations, receipts, and fundraising campaigns from one place.</small>
        </div>
        <div class="d-flex gap-2">
            <a href="#my-donations" class="btn btn-outline-primary btn-sm">My Donations</a>
            <a href="#my-campaigns" class="btn btn-outline-dark btn-sm">My Campaigns</a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-title">Total Donated</h6>
                    <h3 class="mb-0">₹<?php echo number_format($total_donated, 2); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-title">Successful Donations</h6>
                    <h3 class="mb-0"><?php echo $successful_donations; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-dark bg-warning shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-title">My Campaigns</h6>
                    <h3 class="mb-0"><?php echo count($my_campaigns); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-title">Total Funds Raised</h6>
                    <h3 class="mb-0">₹<?php echo number_format($total_raised, 2); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- My Donations Window -->
    <div id="my-donations" class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-bold fs-5">My Donations</span>
            <a href="export_donations.php" class="btn btn-success btn-sm">Export Donations as CSV</a>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Campaign</th>
                        <th>Amount</th>
                        <th>Order ID</th>
                        <th>Payment ID</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Receipts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($my_donations)): ?>
                        <?php foreach ($my_donations as $row): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['campaign_title']); ?></strong></td>
                            <td>₹<?php echo number_format((float) $row['amount'], 2); ?></td>
                            <td class="font-monospace small"><?php echo htmlspecialchars($row['sim_order_id']); ?></td>
                            <td class="font-monospace small"><?php echo $row['sim_payment_id'] ? htmlspecialchars($row['sim_payment_id']) : 'N/A'; ?></td>
                            <td>
                                <?php
                                $badgeClass = 'bg-warning text-dark';
                                if ($row['payment_status'] === 'SUCCESS') {
                                    $badgeClass = 'bg-success';
                                } elseif ($row['payment_status'] === 'FAILED') {
                                    $badgeClass = 'bg-danger';
                                }
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo htmlspecialchars($row['payment_status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars((string) $row['payment_date']); ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="../payment/payment_result.php?order_id=<?php echo urlencode($row['sim_order_id']); ?>" class="btn btn-sm btn-outline-primary">View</a>
                                    <a href="../payment/download_receipt.php?order_id=<?php echo urlencode($row['sim_order_id']); ?>" class="btn btn-sm btn-outline-danger">PDF</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-3">
                                You have not made any donations yet. <a href="../index.php">Explore campaigns</a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- My Fundraising Campaigns -->
    <div id="my-campaigns" class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-bold fs-5">My Fundraising Campaigns</span>
            <a href="../campaigns/create_campaign.php" class="btn btn-warning btn-sm">+ Create New Campaign</a>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Campaign Title</th>
                        <th>Category</th>
                        <th>Raised / Target</th>
                        <th>Progress</th>
                        <th>Deadline</th>
                        <th>Supporting Evidences</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($my_campaigns)): ?>
                        <?php foreach ($my_campaigns as $row):
                            $pct = ($row['target_amount'] > 0) ? min(100, round(($row['collected_amount'] / $row['target_amount']) * 100)) : 0;
                            $goalReached = ((float) $row['collected_amount'] >= (float) $row['target_amount']);
                            $campEvidences = $evidencesByCampaign[(int) $row['id']] ?? [];
                        ?>
                        <tr>
                            <td>
                                <a href="../campaigns/campaign_details.php?id=<?php echo (int) $row['id']; ?>" class="fw-bold text-decoration-none">
                                    <?php echo htmlspecialchars($row['title']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($row['category']); ?></td>
                            <td>₹<?php echo number_format((float) $row['collected_amount'], 2); ?> / ₹<?php echo number_format((float) $row['target_amount'], 2); ?></td>
                            <td style="width: 170px;">
                                <div class="progress" style="height: 14px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $pct; ?>%;"><?php echo $pct; ?>%</div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['deadline']); ?></td>
                            <td>
                                <?php if (!empty($campEvidences)): ?>
                                    <ul class="list-unstyled mb-0 small">
                                        <?php foreach ($campEvidences as $ev): ?>
                                            <li>
                                                <a href="../campaigns/view_evidence.php?id=<?php echo (int) $ev['id']; ?>" target="_blank" rel="noopener">
                                                    <?php echo htmlspecialchars($ev['file_name']); ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="text-muted small">None uploaded</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'pending'): ?>
                                    <span class="badge bg-secondary">INACTIVE</span>
                                    <small class="text-muted d-block">Pending Admin Approval</small>
                                <?php elseif ($row['status'] === 'active'): ?>
                                    <span class="badge bg-success">ACTIVE</span>
                                    <?php if ($goalReached): ?>
                                        <span class="badge bg-info text-dark d-block mt-1">GOAL REACHED</span>
                                    <?php endif; ?>
                                <?php elseif ($row['status'] === 'paused'): ?>
                                    <span class="badge bg-secondary">PAUSED</span>
                                    <small class="text-muted d-block">Paused by Admin</small>
                                <?php elseif ($row['status'] === 'rejected'): ?>
                                    <span class="badge bg-danger">REJECTED</span>
                                <?php else: ?>
                                    <span class="badge bg-dark"><?php echo strtoupper(htmlspecialchars($row['status'])); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-3">
                                You have not created any fundraising campaigns yet. <a href="../campaigns/create_campaign.php">Start a campaign</a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Verified Supporters on My Campaigns -->
    <div class="card shadow-sm">
        <div class="card-header bg-success text-white fw-bold">Verified Supporters on My Campaigns</div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Donor Name</th>
                        <th>Campaign</th>
                        <th>Amount Donated</th>
                        <th>Payment ID</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($supporters)): ?>
                        <?php foreach ($supporters as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['donor_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['campaign_title']); ?></td>
                            <td class="text-success fw-bold">₹<?php echo number_format((float) $s['amount'], 2); ?></td>
                            <td class="font-monospace small"><?php echo htmlspecialchars((string) $s['sim_payment_id']); ?></td>
                            <td><?php echo htmlspecialchars((string) $s['payment_date']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-3">No verified donations received on your campaigns yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
