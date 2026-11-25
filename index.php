<?php
require_once 'config/db.php';
require_once 'auth/auth_check.php';
sendSecurityHeaders(false);

// Handle search and category filters
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';

$sql = "SELECT c.*, u.name AS creator_name FROM campaigns c 
        JOIN users u ON c.creator_id = u.id 
        WHERE c.status = 'active'";
$params = [];
$types = "";

if ($search !== '') {
    $sql .= " AND (c.title LIKE ? OR c.description LIKE ?)";
    $searchParam = "%" . $search . "%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

if ($category !== '') {
    $sql .= " AND c.category = ?";
    $params[] = $category;
    $types .= "s";
}

$sql .= " ORDER BY c.created_at DESC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$campaigns = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FundRaise - Crowdfunding Platform</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">FundRaise</a>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (isset($_SESSION['user_id'])): ?>
                <span class="text-white me-2">Hi, <?php echo e($_SESSION['user_name']); ?></span>

                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="admin/dashboard.php" class="btn btn-outline-light btn-sm">Admin Panel</a>
                <?php else: ?>
                    <a href="campaigns/create_campaign.php" class="btn btn-warning btn-sm">+ Create Campaign</a>
                    <a href="dashboard/user_dashboard.php#my-donations" class="btn btn-outline-light btn-sm">My Donations</a>
                    <a href="dashboard/user_dashboard.php" class="btn btn-outline-info btn-sm">My Dashboard</a>
                <?php endif; ?>

                <form method="POST" action="auth/logout.php" class="d-inline">
                    <?php echo csrfInput(); ?>
                    <button type="submit" class="btn btn-danger btn-sm">Logout</button>
                </form>
            <?php else: ?>
                <a href="auth/login.php" class="btn btn-outline-light btn-sm">Login</a>
                <a href="auth/register.php" class="btn btn-primary btn-sm">Register</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- Hero & Search Filter -->
<div class="container mt-4">
    <div class="p-4 bg-white rounded shadow-sm mb-4">
        <h2 class="mb-3">Explore Fundraising Campaigns</h2>
        <form method="GET" action="index.php" class="row g-2">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control" maxlength="100" placeholder="Search campaigns by title or keyword..." value="<?php echo e($search); ?>">
            </div>
            <div class="col-md-4">
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <option value="Education" <?php if ($category === 'Education') echo 'selected'; ?>>Education</option>
                    <option value="Medical" <?php if ($category === 'Medical') echo 'selected'; ?>>Medical</option>
                    <option value="Startup" <?php if ($category === 'Startup') echo 'selected'; ?>>Startup &amp; Tech</option>
                    <option value="Social Cause" <?php if ($category === 'Social Cause') echo 'selected'; ?>>Social Cause</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>

    <!-- Campaigns Grid -->
    <div class="row">
        <?php if ($campaigns->num_rows > 0): ?>
            <?php while ($row = $campaigns->fetch_assoc()):
                $percentage = ($row['target_amount'] > 0) ? min(100, round(($row['collected_amount'] / $row['target_amount']) * 100)) : 0;
                $goal_reached = ((float) $row['collected_amount'] >= (float) $row['target_amount']);
            ?>
            <div class="col-md-4 mb-4">
                <div class="card h-100 shadow-sm">
                    <img src="<?php echo e($row['image_path']); ?>" class="card-img-top" style="height: 200px; object-fit: cover;" alt="Campaign Image">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge bg-info text-dark"><?php echo e($row['category']); ?></span>
                            <?php if ($goal_reached): ?>
                                <span class="badge bg-success">Goal Reached</span>
                            <?php endif; ?>
                        </div>
                        <h5 class="card-title"><?php echo e($row['title']); ?></h5>
                        <p class="card-text text-muted small">By <?php echo e($row['creator_name']); ?> | Deadline: <?php echo e($row['deadline']); ?></p>

                        <div class="mt-auto">
                            <div class="progress mb-2" style="height: 10px;">
                                <div class="progress-bar bg-success" style="width: <?php echo (int) $percentage; ?>%;"></div>
                            </div>
                            <div class="d-flex justify-content-between small fw-bold mb-3">
                                <span>Raised: ₹<?php echo number_format((float) $row['collected_amount'], 2); ?></span>
                                <span>Goal: ₹<?php echo number_format((float) $row['target_amount'], 2); ?> (<?php echo (int) $percentage; ?>%)</span>
                            </div>
                            <a href="campaigns/campaign_details.php?id=<?php echo (int) $row['id']; ?>" class="btn <?php echo $goal_reached ? 'btn-outline-success' : 'btn-primary'; ?> w-100">
                                <?php echo $goal_reached ? 'View Campaign' : 'View & Donate'; ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="alert alert-info text-center">No active campaigns are available right now. Create a new campaign and wait for Admin approval to see it listed here!</div>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
