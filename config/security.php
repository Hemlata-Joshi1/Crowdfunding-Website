<?php
/**
 * Central security utilities: error hiding, HTTP security headers,
 * hardened sessions with timeout, CSRF protection, and login rate limiting.
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
}

function sendSecurityHeaders(bool $sensitivePage = false): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "font-src 'self' https://cdn.jsdelivr.net; "
        . "img-src 'self' data:; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'self'; "
        . "form-action 'self'"
    );

    if ($sensitivePage) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

function destroySessionAndCookie(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?: '',
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => $params['samesite'] ?: 'Lax',
            ]
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function initSecureSession(bool $enforceTimeout = true): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/crowdfunding',
            'domain' => '',
            'secure' => isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    if (!$enforceTimeout || !isset($_SESSION['user_id'])) {
        return;
    }

    $idleTimeout = 1800;     // 30 minutes inactivity timeout
    $absoluteTimeout = 28800; // 8 hours maximum session lifetime
    $now = time();

    if (
        (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $idleTimeout)
        || (isset($_SESSION['created_at']) && ($now - (int) $_SESSION['created_at']) > $absoluteTimeout)
    ) {
        destroySessionAndCookie();
        header('Location: /crowdfunding/auth/login.php?expired=1');
        exit();
    }

    $_SESSION['last_activity'] = $now;
    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = $now;
    }
}

function generateCsrfToken(): string
{
    initSecureSession(false);
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    $token = htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function verifyCsrfToken(?string $submittedToken): bool
{
    initSecureSession(false);
    if (
        empty($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || !is_string($submittedToken)
        || $submittedToken === ''
    ) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submittedToken);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getClientRateLimitKey(string $email): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', strtolower(trim($email)) . '|' . $ip);
}

function isLoginRateLimited(mysqli $conn, string $email): ?int
{
    $key = getClientRateLimitKey($email);
    $stmt = $conn->prepare('SELECT attempts, locked_until FROM login_attempts WHERE attempt_key = ?');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && !empty($row['locked_until'])) {
        $lockedUntil = strtotime((string) $row['locked_until']);
        if ($lockedUntil !== false && $lockedUntil > time()) {
            return (int) ceil(($lockedUntil - time()) / 60);
        }
    }
    return null;
}

function recordFailedLoginAttempt(mysqli $conn, string $email): void
{
    $key = getClientRateLimitKey($email);
    $maxAttempts = 5;
    $lockSeconds = 300; // 5 minutes

    $stmt = $conn->prepare('SELECT attempts, UNIX_TIMESTAMP(updated_at) AS last_ts FROM login_attempts WHERE attempt_key = ?');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $attempts = 1;
    if ($row && (time() - (int) $row['last_ts']) <= 900) {
        $attempts = ((int) $row['attempts']) + 1;
    }

    $lockedUntil = ($attempts >= $maxAttempts) ? date('Y-m-d H:i:s', time() + $lockSeconds) : null;

    $up = $conn->prepare(
        'INSERT INTO login_attempts (attempt_key, attempts, locked_until)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until), updated_at = CURRENT_TIMESTAMP()'
    );
    if ($up) {
        $up->bind_param('sis', $key, $attempts, $lockedUntil);
        $up->execute();
        $up->close();
    }
}

function clearLoginAttempts(mysqli $conn, string $email): void
{
    $key = getClientRateLimitKey($email);
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE attempt_key = ?');
    if ($stmt) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
    }
}
