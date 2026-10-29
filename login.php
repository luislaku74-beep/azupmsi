<?php

session_start();
require_once __DIR__ . '/toast.php';
require_once __DIR__ . '/db.php';

/**
 * Record an audit event without exposing audit-table errors to users.
 * Never pass passwords or password hashes to this function.
 */
function recordLoginAudit(
    PDO $pdo,
    ?int $userId,
    string $username,
    string $eventType,
    string $action,
    string $outcome,
    ?string $details = null
): void {
    try {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        // Validate the address before saving it.
        if (
            $ipAddress !== null &&
            filter_var($ipAddress, FILTER_VALIDATE_IP) === false
        ) {
            $ipAddress = null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
                (user_id, username, event_type, action, outcome, details, ip_address)
             VALUES
                (:user_id, :username, :event_type, :action, :outcome, :details, :ip_address)'
        );

        $stmt->execute([
            'user_id' => $userId,
            'username' => mb_substr($username, 0, 100),
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome,
            'details' => $details,
            'ip_address' => $ipAddress
        ]);
    } catch (Throwable $e) {
        // Do not interrupt authentication if audit recording fails.
        error_log('AZU PIMS audit logging failed: ' . $e->getMessage());
    }
}

// If already signed in, continue to the dashboard.
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Create a CSRF token for the login form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Your session expired. Refresh and try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        try {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, username, password_hash, role
                 FROM users
                 WHERE username = :username AND is_active = 1
                 LIMIT 1'
            );

            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $user &&
                password_verify($password, $user['password_hash'])
            ) {
                // Record successful authentication.
                recordLoginAudit(
                    $pdo,
                    (int) $user['id'],
                    (string) $user['username'],
                    'login',
                    'Staff login',
                    'success',
                    'Role: ' . (string) $user['role']
                );

                // Regenerate the session ID after authentication.
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                // Rotate the CSRF token after successful login.
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: dashboard.php');
                exit;
            }

            // Use the same message for unknown, inactive, or incorrect accounts.
            recordLoginAudit(
                $pdo,
                null,
                $username !== '' ? $username : '(blank username)',
                'login',
                'Staff login',
                'failed',
                'Invalid credentials or inactive account'
            );

            $error = 'Invalid username or password.';

        } catch (PDOException $e) {
            // Keep database details out of the browser.
            error_log('AZU PIMS login error: ' . $e->getMessage());
            $error = 'Unable to sign in right now. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | AZU Clinic PIMS</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="toast.css">
</head>
<body>
<?php
renderToastMessages(
    $error !== '' ? [['message' => $error, 'type' => 'error']] : []
);
?>

<div class="login-container">
    <div class="login-card">
        <div class="logo-wrap">
            <img src="azu-logo.png" alt="Africa Zeal University logo">
        </div>

        <h1>AZU Clinic</h1>
        <p class="subtitle">
            Patient Information Management System
        </p>

        <h2>Staff Login</h2>
        <p class="description">
            Sign in to access the clinic system.
        </p>

        <form method="POST" action="login.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <label for="username">Username</label>
            <input
                type="text"
                id="username"
                name="username"
                autocomplete="username"
                required
                autofocus
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Sign In</button>
        </form>

        <p class="footer">
            Africa Zeal University Clinic<br>
            Authorized staff only
        </p>
    </div>
</div>

<script src="toast.js" defer></script>
</body>
</html>
