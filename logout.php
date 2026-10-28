<?php

session_start();
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Record the logout in the audit log
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['user_id'])) {
    try {
        $userId = (int) $_SESSION['user_id'];
        $username = (string) ($_SESSION['username'] ?? 'Unknown user');

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        if (
            $ipAddress !== null &&
            filter_var($ipAddress, FILTER_VALIDATE_IP) === false
        ) {
            $ipAddress = null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
                (user_id, username, event_type, action, outcome,
                 details, ip_address)
             VALUES
                (:user_id, :username, :event_type, :action, :outcome,
                 :details, :ip_address)'
        );

        $stmt->execute([
            'user_id'    => $userId,
            'username'   => $username,
            'event_type' => 'logout',
            'action'     => 'Staff logout',
            'outcome'    => 'success',
            'details'    => 'Staff signed out',
            'ip_address' => $ipAddress
        ]);

    } catch (Throwable $e) {
        // A logging failure must not prevent the user from signing out.
        error_log('AZU PIMS logout audit error: ' . $e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| Destroy the session
|--------------------------------------------------------------------------
*/

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;
