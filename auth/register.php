<?php
require_once '../config/db.php';
initSecureSession(false);
sendSecurityHeaders(true);

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $message = '<div class="alert alert-danger">Invalid security token. Please refresh the page and try again.</div>';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = 'user';

        if ($name === '' || $email === '' || $password === '') {
            $message = '<div class="alert alert-danger">All fields are required!</div>';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $message = '<div class="alert alert-danger">Full name must be between 2 and 100 characters.</div>';
        } elseif (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = '<div class="alert alert-danger">Please enter a valid email address.</div>';
        } elseif (strlen($password) < 8 || strlen($password) > 128) {
            $message = '<div class="alert alert-danger">Password must be between 8 and 128 characters long.</div>';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $message = '<div class="alert alert-danger">Password must include at least one letter and one number.</div>';
        } else {
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $checkStmt->store_result();

            if ($checkStmt->num_rows > 0) {
                $message = '<div class="alert alert-warning">This email address is already registered!</div>';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $name, $email, $hashed_password, $role);

                if ($stmt->execute()) {
                    $message = '<div class="alert alert-success">Registration successful! You can now <a href="login.php" class="alert-link">Login</a> to donate or start a campaign.</div>';
                } else {
                    $message = '<div class="alert alert-danger">Error: Registration failed. Please try again.</div>';
                }
                $stmt->close();
            }
            $checkStmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - FundRaise Crowdfunding Platform</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-header bg-primary text-white text-center">
                    <h4 class="mb-0">Create an Account</h4>
                </div>
                <div class="card-body">
                    <p class="text-muted small text-center mb-3">
                        One unified account lets you both support campaigns and launch your own fundraisers.
                    </p>
                    <?php echo $message; ?>
                    <form method="POST" action="">
                        <?php echo csrfInput(); ?>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" minlength="2" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" minlength="8" maxlength="128" class="form-control" required>
                            <div class="form-text">Minimum 8 characters, including at least one letter and one number.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Register</button>
                    </form>
                    <p class="mt-3 mb-0 text-center">Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
