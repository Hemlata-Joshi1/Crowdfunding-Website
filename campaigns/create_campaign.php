<?php
require_once '../auth/auth_check.php';
checkRole(['user', 'donor', 'creator']);
require_once '../config/db.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $message = '<div class="alert alert-danger">Invalid security token. Please refresh the page and try again.</div>';
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $target_amount = round(floatval($_POST['target_amount'] ?? 0), 2);
        $deadline = trim((string) ($_POST['deadline'] ?? ''));
        $creator_id = (int) $_SESSION['user_id'];
        $today = date('Y-m-d');

        $allowed_categories = ['Education', 'Medical', 'Startup', 'Social Cause'];
        $max_file_size = 5 * 1024 * 1024; // 5 MB

        if (
            $title === ''
            || mb_strlen($title) > 200
            || !in_array($category, $allowed_categories, true)
            || $description === ''
            || mb_strlen($description) > 5000
            || $target_amount < 100
            || $target_amount > 99999999.99
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)
            || $deadline <= $today
        ) {
            $message = '<div class="alert alert-danger">Please fill in all required fields properly. Title must be under 200 characters, target between ₹100 and ₹99,999,999.99, and deadline must be a future date.</div>';
        } elseif (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $message = '<div class="alert alert-danger">Please upload a valid cover image for your campaign.</div>';
        } else {
            $allowed_img_ext = ['jpg', 'jpeg', 'png', 'webp'];
            $allowed_img_mime = ['image/jpeg', 'image/png', 'image/webp'];
            $img_name = $_FILES['image']['name'];
            $img_ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));
            $img_size = (int) $_FILES['image']['size'];

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $img_mime = $finfo->file($_FILES['image']['tmp_name']);
            $img_info = @getimagesize($_FILES['image']['tmp_name']);

            if (!in_array($img_ext, $allowed_img_ext, true) || !in_array($img_mime, $allowed_img_mime, true) || $img_info === false) {
                $message = '<div class="alert alert-danger">Cover image must be a valid JPG, JPEG, PNG, or WEBP file.</div>';
            } elseif ($img_size <= 0 || $img_size > $max_file_size) {
                $message = '<div class="alert alert-danger">Cover image size must not exceed 5 MB.</div>';
            } else {
                $evidence_files = [];
                $evidence_error = "";
                $allowed_ev_ext = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
                $allowed_ev_mime = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

                if (isset($_FILES['evidences']) && is_array($_FILES['evidences']['name'])) {
                    $total_ev = count($_FILES['evidences']['name']);
                    $selected_count = 0;

                    for ($i = 0; $i < $total_ev; $i++) {
                        $err = $_FILES['evidences']['error'][$i];
                        if ($err === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }
                        $selected_count++;
                        if ($selected_count > 5) {
                            $evidence_error = "You can upload up to 5 supporting evidence files.";
                            break;
                        }
                        if ($err !== UPLOAD_ERR_OK) {
                            $evidence_error = "One of the supporting evidence files failed to upload.";
                            break;
                        }
                        $raw_name = basename((string) $_FILES['evidences']['name'][$i]);
                        $orig_name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $raw_name), 0, 200);
                        if ($orig_name === '') {
                            $orig_name = 'evidence_' . $selected_count;
                        }
                        $ev_ext = strtolower(pathinfo($raw_name, PATHINFO_EXTENSION));
                        $ev_size = (int) $_FILES['evidences']['size'][$i];
                        $ev_tmp = $_FILES['evidences']['tmp_name'][$i];
                        $ev_mime = $finfo->file($ev_tmp);

                        if (!in_array($ev_ext, $allowed_ev_ext, true) || !in_array($ev_mime, $allowed_ev_mime, true)) {
                            $evidence_error = "Supporting evidence files must be PDF, JPG, JPEG, PNG, or WEBP.";
                            break;
                        }
                        if (in_array($ev_ext, $allowed_img_ext, true) && @getimagesize($ev_tmp) === false) {
                            $evidence_error = "One of the uploaded image evidences is not a valid image.";
                            break;
                        }
                        if ($ev_size <= 0 || $ev_size > $max_file_size) {
                            $evidence_error = "Each supporting evidence file must not exceed 5 MB.";
                            break;
                        }

                        $evidence_files[] = [
                            'orig_name' => $orig_name,
                            'ext' => $ev_ext,
                            'tmp_name' => $ev_tmp,
                        ];
                    }
                }

                if ($evidence_error !== "") {
                    $message = '<div class="alert alert-danger">' . e($evidence_error) . '</div>';
                } else {
                    $uploads_dir = "../uploads";
                    $evidences_dir = "../uploads/evidences";
                    if (!is_dir($evidences_dir)) {
                        mkdir($evidences_dir, 0755, true);
                    }

                    $new_img_name = "camp_" . time() . "_" . random_int(100, 999) . "." . $img_ext;
                    $upload_path = $uploads_dir . "/" . $new_img_name;
                    $db_image_path = "uploads/" . $new_img_name;

                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                        $moved_evidences = [];
                        $conn->begin_transaction();

                        try {
                            $stmt = $conn->prepare(
                                "INSERT INTO campaigns (creator_id, title, description, category, target_amount, deadline, image_path, status)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
                            );
                            $stmt->bind_param("isssdss", $creator_id, $title, $description, $category, $target_amount, $deadline, $db_image_path);
                            $stmt->execute();
                            $new_campaign_id = (int) $conn->insert_id;
                            $stmt->close();

                            if (!empty($evidence_files)) {
                                $evStmt = $conn->prepare(
                                    "INSERT INTO campaign_evidences (campaign_id, file_name, file_path) VALUES (?, ?, ?)"
                                );
                                foreach ($evidence_files as $idx => $ev) {
                                    $ev_new_name = "ev_" . $new_campaign_id . "_" . time() . "_" . $idx . "_" . random_int(100, 999) . "." . $ev['ext'];
                                    $ev_upload_path = $evidences_dir . "/" . $ev_new_name;
                                    $ev_db_path = "uploads/evidences/" . $ev_new_name;

                                    if (!move_uploaded_file($ev['tmp_name'], $ev_upload_path)) {
                                        throw new RuntimeException("Failed to save supporting evidence file.");
                                    }
                                    $moved_evidences[] = $ev_upload_path;
                                    $evStmt->bind_param("iss", $new_campaign_id, $ev['orig_name'], $ev_db_path);
                                    $evStmt->execute();
                                }
                                $evStmt->close();
                            }

                            $conn->commit();
                            $message = '<div class="alert alert-success">Campaign request submitted! It is currently marked <strong>INACTIVE (Pending Admin Approval)</strong> and will go live on the homepage once approved by an Admin.</div>';
                        } catch (Throwable $e) {
                            error_log('Create campaign error: ' . $e->getMessage());
                            $conn->rollback();
                            if (file_exists($upload_path)) {
                                @unlink($upload_path);
                            }
                            foreach ($moved_evidences as $path) {
                                if (file_exists($path)) {
                                    @unlink($path);
                                }
                            }
                            $message = '<div class="alert alert-danger">Database error: Failed to submit campaign request. Please try again.</div>';
                        }
                    } else {
                        $message = '<div class="alert alert-danger">Failed to upload campaign cover image.</div>';
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Campaign - FundRaise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0">Create New Fundraising Campaign</h5>
                    <div class="d-flex gap-2">
                        <a href="../dashboard/user_dashboard.php#my-campaigns" class="btn btn-outline-light btn-sm">My Dashboard</a>
                        <a href="../index.php" class="btn btn-light btn-sm">Back to Home</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php echo $message; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <?php echo csrfInput(); ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Campaign Title</label>
                            <input type="text" name="title" class="form-control" maxlength="200" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Category</label>
                                <select name="category" class="form-select" required>
                                    <option value="Education">Education</option>
                                    <option value="Medical">Medical</option>
                                    <option value="Startup">Startup &amp; Tech</option>
                                    <option value="Social Cause">Social Cause</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Target Amount (₹)</label>
                                <input type="number" name="target_amount" min="100" step="1" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Deadline Date</label>
                            <input type="date" name="deadline" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Campaign Cover Image</label>
                            <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/*" required>
                            <div class="form-text">Accepted formats: JPG, JPEG, PNG, WEBP (max 5 MB).</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Supporting Evidences (Documents / Proof)</label>
                            <input type="file" name="evidences[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple>
                            <div class="form-text">
                                Optional but recommended for faster Admin approval (e.g., medical bills, fee receipts, ID/registration documents, photos). Up to 5 files; PDF, JPG, JPEG, PNG, or WEBP (max 5 MB each).
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Detailed Description</label>
                            <textarea name="description" rows="4" class="form-control" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Submit Campaign Request</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
