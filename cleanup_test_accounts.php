<?php
session_start();
require_once __DIR__ . '/db.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied.');
}

$testUsernames = ['zztest2026102901', 'zzdeploytest2901'];
$message = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        exit('Your session expired. Reload this page and try again.');
    }

    try {
        $pdo->beginTransaction();
        $delete = $pdo->prepare('DELETE FROM users WHERE username = :username');
        $deleted = 0;

        foreach ($testUsernames as $username) {
            $delete->execute(['username' => $username]);
            $deleted += $delete->rowCount();
        }

        $pdo->commit();
        $message = "Removed {$deleted} test account(s).";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Test account cleanup failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Could not remove the test accounts. Check the server error log.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test Account Cleanup</title>
</head>
<body>
    <h1>Remove test accounts</h1>
    <?php if ($message !== ''): ?>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php else: ?>
        <p>This will permanently remove only the temporary test accounts created for deployment verification.</p>
        <form method="post">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Remove test accounts</button>
        </form>
    <?php endif; ?>
</body>
</html>
