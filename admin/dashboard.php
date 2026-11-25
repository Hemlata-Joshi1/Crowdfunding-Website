<?php
require_once '../auth/auth_check.php';
checkRole(['admin']);
require_once '../config/db.php';

$message = "";

// Campaign Moderation Actions (Approve / Reject / Pause / Resume / Delete)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['campaign_id'], $_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $message = '<div class="alert alert-danger">Invalid security token. Please refresh and try again.</div>';
    } else {
        $camp_id = intval($_POST['campaign_id']);
        $action = $_POST['action'];

        if ($action === 'approve' || $action === 'resume') {
            $stmt = $conn->prepare("UPDATE campaigns SET status = 'active' WHERE id = ?");
            $stmt->bind_param("i", $camp_id);
            $stmt->execute();
            $stmt->close();
            $message = $action === 'resume'
                ? '<div class="alert alert-success">Campaign resumed! It is active and visible on the website again.</div>'
                : '<div class="alert alert-success">Campaign approved! It is now active and visible on the homepage.</div>';
        } elseif ($action === 'pause') {
            $stmt = $conn->prepare("UPDATE campaigns SET status = 'paused' WHERE id = ? AND status = 'active'");
            $stmt->bind_param("i", $camp_id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            $message = $affected > 0
                ? '<div class="alert alert-warning">Campaign has been paused and temporarily hidden from public donations.</div>'
                : '<div class="alert alert-info">Only active campaigns can be paused.</div>';
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("UPDATE campaigns SET status = 'rejected' WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("i", $camp_id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            $message = $affected > 0
                ? '<div class="alert alert-warning">Campaign request has been rejected.</div>'
                : '<div class="alert alert-danger">Active or already approved campaigns cannot be rejected. You can pause them instead.</div>';
        } elseif ($action === 'delete') {
            $uploadsBase = realpath(dirname(__DIR__) . '/uploads');
            $filesToDelete = [];
            $imgStmt = $conn->prepare("SELECT image_path FROM campaigns WHERE id = ?");
            $imgStmt->bind_param("i", $camp_id);
            $imgStmt->execute();
            $imgRow = $imgStmt->get_result()->fetch_assoc();
            $imgStmt->close();
            if ($imgRow && !empty($imgRow['image_path'])) {
                $filesToDelete[] = realpath(dirname(__DIR__) . '/' . ltrim($imgRow['image_path'], '/'));
            }

            $evDelStmt = $conn->prepare("SELECT file_path FROM campaign_evidences WHERE campaign_id = ?");
            $evDelStmt->bind_param("i", $camp_id);
            $evDelStmt->execute();
            $evDelRes = $evDelStmt->get_result();
            while ($evRow = $evDelRes->fetch_assoc()) {
                if (!empty($evRow['file_path'])) {
                    $filesToDelete[] = realpath(dirname(__DIR__) . '/' . ltrim($evRow['file_path'], '/'));
                }
            }
            $evDelStmt->close();

            $stmt = $conn->prepare("DELETE FROM campaigns WHERE id = ?");
            $stmt->bind_param("i", $camp_id);
            if ($stmt->execute()) {
                foreach ($filesToDelete as $filePath) {
                    if ($uploadsBase !== false && $filePath !== false && str_starts_with($filePath, $uploadsBase . DIRECTORY_SEPARATOR) && is_file($filePath)) {
                        @unlink($filePath);
                    }
                }
                $message = '<div class="alert alert-danger">Campaign has been permanently deleted.</div>';
            }
            $stmt->close();
        }
    }
}

// Fetch Summary Metrics
$total_users = $conn->query("SELECT COUNT(*) AS cnt FROM users")->fetch_assoc()['cnt'];
$active_camps = $conn->query("SELECT COUNT(*) AS cnt FROM campaigns WHERE status = 'active'")->fetch_assoc()['cnt'];
$pending_camps = $conn->query("SELECT COUNT(*) AS cnt FROM campaigns WHERE status = 'pending'")->fetch_assoc()['cnt'];
$total_raised = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM donations WHERE payment_status = 'SUCCESS'")->fetch_assoc()['total'];

// Fetch All Campaigns
$campaigns = $conn->query(
    "SELECT c.*, u.name AS creator_name
     FROM campaigns c
     JOIN users u ON c.creator_id = u.id
     ORDER BY FIELD(c.status, 'pending', 'paused', 'active', 'closed', 'rejected'), c.created_at DESC"
);

// Fetch Supporting Evidences grouped by campaign_id
$evidencesByCampaign = [];
$evResult = $conn->query("SELECT * FROM campaign_evidences ORDER BY uploaded_at ASC");
if ($evResult) {
    while ($ev = $evResult->fetch_assoc()) {
        $evidencesByCampaign[(int) $ev['campaign_id']][] = $ev;
    }
}

// Fetch Recent Donations
$donations = $conn->query(
    "SELECT d.*, c.title AS campaign_title, u.name AS donor_name
     FROM donations d
     JOIN campaigns c ON d.campaign_id = c.id
     JOIN users u ON d.donor_id = u.id
     ORDER BY d.payment_date DESC LIMIT 20"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FundRaise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="../index.php">FundRaise Admin Panel</a>
        <div>
            <a href="../index.php" class="btn btn-outline-light btn-sm me-2">View Website</a>
            <form method="POST" action="../auth/logout.php" class="d-inline">
                <?php echo csrfInput(); ?>
                <button type="submit" class="btn btn-danger btn-sm">Logout</button>
            </form>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h3 class="mb-0">Admin Dashboard</h3>
            <small class="text-muted">Review campaign requests, pause or resume active campaigns, and monitor transactions.</small>
        </div>
        <a href="../index.php" target="_blank" rel="noopener" class="btn btn-outline-primary">View Public Website</a>
    </div>

    <?php echo $message; ?>

    <!-- Overview Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary shadow-sm">
                <div class="card-body">
                    <h6>Total Users</h6>
                    <h3><?php echo (int) $total_users; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success shadow-sm">
                <div class="card-body">
                    <h6>Active Campaigns</h6>
                    <h3><?php echo (int) $active_camps; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-dark bg-warning shadow-sm">
                <div class="card-body">
                    <h6>Pending Approval (Inactive)</h6>
                    <h3><?php echo (int) $pending_camps; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info shadow-sm">
                <div class="card-body">
                    <h6>Verified Donations</h6>
                    <h3>₹<?php echo number_format((float) $total_raised, 2); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Campaign Moderation Table -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-bold">Manage &amp; Review Campaign Requests</div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Creator</th>
                        <th>Target / Raised</th>
                        <th>Deadline</th>
                        <th>Supporting Evidences</th>
                        <th>Status</th>
                        <th>Moderation Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($campaigns->num_rows > 0): ?>
                        <?php while ($c = $campaigns->fetch_assoc()):
                            $campEvidences = $evidencesByCampaign[(int) $c['id']] ?? [];
                        ?>
                        <tr>
                            <td><?php echo (int) $c['id']; ?></td>
                            <td>
                                <a href="../campaigns/campaign_details.php?id=<?php echo (int) $c['id']; ?>" class="fw-semibold text-decoration-none">
                                    <?php echo htmlspecialchars($c['title']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($c['creator_name']); ?></td>
                            <td>₹<?php echo number_format((float) $c['target_amount']); ?> / <strong>₹<?php echo number_format((float) $c['collected_amount']); ?></strong></td>
                            <td><?php echo htmlspecialchars($c['deadline']); ?></td>
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
                                    <span class="text-muted small">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if ($c['status'] === 'active') {
                                    echo '<span class="badge bg-success">ACTIVE</span>';
                                } elseif ($c['status'] === 'pending') {
                                    echo '<span class="badge bg-warning text-dark">INACTIVE (PENDING)</span>';
                                } elseif ($c['status'] === 'paused') {
                                    echo '<span class="badge bg-secondary">PAUSED</span>';
                                } elseif ($c['status'] === 'rejected') {
                                    echo '<span class="badge bg-danger">REJECTED</span>';
                                } else {
                                    echo '<span class="badge bg-dark">' . strtoupper(htmlspecialchars($c['status'])) . '</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="campaign_id" value="<?php echo (int) $c['id']; ?>">
                                    <?php if ($c['status'] === 'pending'): ?>
                                        <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve</button>
                                        <button type="submit" name="action" value="reject" class="btn btn-warning btn-sm">Reject</button>
                                    <?php elseif ($c['status'] === 'active'): ?>
                                        <button type="submit" name="action" value="pause" class="btn btn-secondary btn-sm">Pause</button>
                                    <?php elseif ($c['status'] === 'paused'): ?>
                                        <button type="submit" name="action" value="resume" class="btn btn-success btn-sm">Resume</button>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve</button>
                                    <?php endif; ?>
                                    <button type="submit" name="action" value="delete" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to permanently delete this campaign?');">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-3">No campaigns found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Transactions Monitoring -->
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-bold">Recent Simulated Transactions</div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Order ID</th>
                        <th>Payment ID</th>
                        <th>Donor</th>
                        <th>Campaign</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($donations->num_rows > 0): ?>
                        <?php while ($d = $donations->fetch_assoc()): ?>
                        <tr>
                            <td class="font-monospace small"><?php echo htmlspecialchars($d['sim_order_id']); ?></td>
                            <td class="font-monospace small"><?php echo $d['sim_payment_id'] ? htmlspecialchars($d['sim_payment_id']) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($d['donor_name']); ?></td>
                            <td><?php echo htmlspecialchars($d['campaign_title']); ?></td>
                            <td>₹<?php echo number_format((float) $d['amount'], 2); ?></td>
                            <td>
                                <span class="badge <?php echo $d['payment_status'] === 'SUCCESS' ? 'bg-success' : ($d['payment_status'] === 'FAILED' ? 'bg-danger' : 'bg-secondary'); ?>">
                                    <?php echo htmlspecialchars($d['payment_status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars((string) $d['payment_date']); ?></td>
                            <td>
                                <a href="../payment/download_receipt.php?order_id=<?php echo urlencode($d['sim_order_id']); ?>" class="btn btn-sm btn-outline-danger">PDF</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-3">No transactions recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
