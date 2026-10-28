
<?php

/**
 * Record an action in the AZU PIMS audit log.
 *
 * Never pass passwords, patient clinical details, or other
 * unnecessary sensitive information in the $details parameter.
 */
function recordAudit(
    PDO $pdo,
    string $eventType,
    string $action,
    string $outcome = 'success',
    ?string $details = null
): bool {
    try {
        $userId = isset($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;

        $username = (string) (
            $_SESSION['username'] ?? 'Unknown user'
        );

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        if (
            $ipAddress !== null &&
            filter_var($ipAddress, FILTER_VALIDATE_IP) === false
        ) {
            $ipAddress = null;
        }

        // Keep values within the database column limits.
        $eventType = substr($eventType, 0, 50);
        $action = substr($action, 0, 150);
        $outcome = substr($outcome, 0, 30);

        if ($details !== null) {
            $details = substr($details, 0, 500);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
                (
                    user_id,
                    username,
                    event_type,
                    action,
                    outcome,
                    details,
                    ip_address
                )
             VALUES
                (
                    :user_id,
                    :username,
                    :event_type,
                    :action,
                    :outcome,
                    :details,
                    :ip_address
                )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'username' => substr($username, 0, 100),
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome,
            'details' => $details,
            'ip_address' => $ipAddress
        ]);

        return true;

    } catch (Throwable $e) {
        // Audit failure should not expose technical details to users.
        error_log('AZU PIMS audit logging error: ' . $e->getMessage());

        return false;
    }
}
