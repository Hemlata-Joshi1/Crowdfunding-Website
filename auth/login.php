<?php
require_once '../config/db.php';
initSecureSession(false);
sendSecurityHeaders(true);

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../index.php");
    }
    exit();
}

$message = "";
if (isset($_GET['expired']) && $_GET['expired'] === '1') {
    $message = '<div class="alert alert-warning">Your session expired due to inactivity. Please log in again.</div>';
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $message = '<div class="alert alert-danger">Invalid security token. Please refresh the page and try again.</div>';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = '<div class="alert alert-danger">Please enter a valid email address and password.</div>';
        } else {
            $lockedMinutes = isLoginRateLimited($conn, $email);
            if ($lockedMinutes !== null) {
                http_response_code(429);
                $message = '<div class="alert alert-danger">Too many failed login attempts. Please try again in ' . (int) $lockedMinutes . ' minute(s).</div>';
            } else {
                $stmt = $conn->prepare("SELECT id, name, password, role FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                $authenticated = false;
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    if (password_verify($password, $user['password'])) {
                        $authenticated = true;
                        clearLoginAttempts($conn, $email);
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = (int) $user['id'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['created_at'] = time();
                        $_SESSION['last_activity'] = time();
                        unset($_SESSION['csrf_token']);
                        generateCsrfToken();

                        if ($user['role'] === 'admin') {
                            header("Location: ../admin/dashboard.php");
                        } else {
                            header("Location: ../index.php");
                        }
                        exit();
                    }
                }
                $stmt->close();

                if (!$authenticated) {
                    recordFailedLoginAttempt($conn, $email);
                    $message = '<div class="alert alert-danger">Invalid email or password.</div>';
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
    <title>Login - FundRaise Crowdfunding Platform</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-header bg-success text-white text-center">
                    <h4 class="mb-0">Login</h4>
                </div>
                <div class="card-body">
                    <?php echo $message; ?>
                    <form method="POST" action="">
                        <?php echo csrfInput(); ?>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" maxlength="128" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Login</button>
                    </form>
                    <p class="mt-3 mb-0 text-center">Need a new account? <a href="register.php">Register here</a></p>
                    <p class="mt-2 mb-0 text-center"><a href="../index.php" class="text-muted small">&larr; Back to Home</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
