<?php

session_start();
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Access control: administrators only
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Only administrators can view audit logs.');
}

/*
|--------------------------------------------------------------------------
| Search and filters
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['search'] ?? ''));
$eventType = trim((string) ($_GET['event_type'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$allowedEvents = [
    'login',
    'logout',
    'patient',
    'appointment',
    'medical_record',
    'medicine',
    'dispensing',
    'user',
    'system'
];

if (!in_array($eventType, $allowedEvents, true)) {
    $eventType = '';
}

$isValidDate = static function (string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
};

if ($dateFrom !== '' && !$isValidDate($dateFrom)) {
    $dateFrom = '';
}

if ($dateTo !== '' && !$isValidDate($dateTo)) {
    $dateTo = '';
}

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

/*
|--------------------------------------------------------------------------
| Build the database query safely
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($search !== '') {
    $where[] = 'LOWER(username) LIKE LOWER(:search)';
    $params['search'] = '%' . $search . '%';
}

if ($eventType !== '') {
    $where[] = 'event_type = :event_type';
    $params['event_type'] = $eventType;
}

if ($dateFrom !== '') {
    $where[] = 'created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[] = 'created_at < :date_to';
    $params['date_to'] = (new DateTimeImmutable($dateTo))
        ->modify('+1 day')
        ->format('Y-m-d 00:00:00');
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs $whereSql"
    );
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = "
        SELECT
            id,
            username,
            event_type,
            action,
            outcome,
            details,
            ip_address,
            created_at
        FROM audit_logs
        $whereSql
        ORDER BY created_at DESC, id DESC
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('AZU PIMS audit page error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load audit logs. Please check the system error log.');
}

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$queryParams = [
    'search' => $search,
    'event_type' => $eventType,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
];

function pageUrl(int $page, array $params): string
{
    $params['page'] = $page;
    return 'audit_logs.php?' . http_build_query($params);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit & User Activity Log | AZU PIMS</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .audit-page {
            max-width: 1500px;
            margin: 0 auto;
            padding: 24px;
        }

        .audit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .audit-header h1 {
            color: #202b83;
            margin: 0 0 6px;
        }

        .audit-header p {
            color: #738096;
            margin: 0;
        }

        .audit-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .audit-btn {
            display: inline-block;
            border: 0;
            border-radius: 8px;
            padding: 11px 16px;
            background: #202b83;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .audit-btn.secondary {
            background: #ed791c;
        }

        .audit-panel {
            background: #fff;
            border: 1px solid #e5e9f2;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 22px rgba(32, 43, 131, 0.07);
        }

        .audit-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px;
            align-items: end;
        }

        .audit-filters label {
            display: block;
            color: #263247;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .audit-filters input,
        .audit-filters select {
            box-sizing: border-box;
            width: 100%;
            padding: 10px;
            border: 1px solid #d8deea;
            border-radius: 7px;
            background: #fff;
        }

        .audit-summary {
            color: #738096;
            margin-bottom: 14px;
        }

        .audit-table-wrap {
            overflow-x: auto;
        }

        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .audit-table th,
        .audit-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e5e9f2;
            text-align: left;
            vertical-align: top;
        }

        .audit-table th {
            background: #f4f6fb;
            color: #202b83;
            white-space: nowrap;
        }

        .audit-table td {
            overflow-wrap: anywhere;
        }

        .audit-status {
            display: inline-block;
            border-radius: 20px;
            padding: 4px 9px;
            font-size: 12px;
            font-weight: 700;
        }

        .audit-status.success {
            background: #e6f6ec;
            color: #176b37;
        }

        .audit-status.failed {
            background: #fde9e7;
            color: #a52820;
        }

        .audit-status.other {
            background: #eef0f6;
            color: #4a5570;
        }

        .audit-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .audit-pagination a {
            color: #202b83;
            text-decoration: none;
            font-weight: 600;
            margin-right: 12px;
        }

        .audit-empty {
            text-align: center;
            color: #738096;
            padding: 30px !important;
        }

        @media print {
            .no-print,
            .audit-filters,
            .audit-pagination {
                display: none !important;
            }

            .audit-page {
                padding: 0;
                max-width: none;
            }

            .audit-panel {
                border: 0;
                box-shadow: none;
                padding: 0;
            }

            .audit-table {
                font-size: 10px;
            }

            .audit-table th,
            .audit-table td {
                padding: 6px;
            }
        }
    </style>
</head>
<body>

<div class="audit-page">

    <div class="audit-header">
        <div>
            <h1>Audit &amp; User Activity Log</h1>
            <p>Review staff sign-ins, sign-outs and recorded system activities.</p>
        </div>

        <div class="audit-actions no-print">
            <a class="audit-btn" href="dashboard.php">Back to Dashboard</a>
            <button class="audit-btn secondary" type="button"
                    onclick="window.print()">Print Report</button>
        </div>
    </div>

    <div class="audit-panel no-print">
        <form method="GET" action="audit_logs.php" class="audit-filters">

            <div>
                <label for="search">Username</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search username"
                >
            </div>

            <div>
                <label for="event_type">Event type</label>
                <select id="event_type" name="event_type">
                    <option value="">All event types</option>
                    <?php foreach ($allowedEvents as $event): ?>
                        <option value="<?= e($event) ?>"
                            <?= $eventType === $event ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $event))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="date_from">From date</label>
                <input
                    type="date"
                    id="date_from"
                    name="date_from"
                    value="<?= e($dateFrom) ?>"
                >
            </div>

            <div>
                <label for="date_to">To date</label>
                <input
                    type="date"
                    id="date_to"
                    name="date_to"
                    value="<?= e($dateTo) ?>"
                >
            </div>

            <div>
                <button class="audit-btn" type="submit">Apply Filters</button>
                <a class="audit-btn secondary" href="audit_logs.php">Reset</a>
            </div>

        </form>
    </div>

    <div class="audit-panel">
        <p class="audit-summary">
            Matching records: <strong><?= number_format($totalRows) ?></strong>
            &nbsp;|&nbsp; Page <?= $page ?> of <?= $totalPages ?>
        </p>

        <div class="audit-table-wrap">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date &amp; Time</th>
                        <th>Username</th>
                        <th>Event</th>
                        <th>Action</th>
                        <th>Outcome</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="8" class="audit-empty">
                            No audit records match your filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $outcome = strtolower((string) $log['outcome']);
                        $statusClass = $outcome === 'success'
                            ? 'success'
                            : ($outcome === 'failed' ? 'failed' : 'other');
                        ?>
                        <tr>
                            <td><?= (int) $log['id'] ?></td>
                            <td><?= e($log['created_at']) ?></td>
                            <td><?= e($log['username']) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', $log['event_type']))) ?></td>
                            <td><?= e($log['action']) ?></td>
                            <td>
                                <span class="audit-status <?= e($statusClass) ?>">
                                    <?= e(ucfirst($outcome)) ?>
                                </span>
                            </td>
                            <td><?= e($log['details']) ?></td>
                            <td><?= e($log['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="audit-pagination no-print">
            <div>
                <?php if ($page > 1): ?>
                    <a href="<?= e(pageUrl($page - 1, $queryParams)) ?>">
                        &larr; Previous
                    </a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(pageUrl($page + 1, $queryParams)) ?>">
                        Next &rarr;
                    </a>
                <?php endif; ?>
            </div>

            <div>
                Showing <?= $totalRows === 0 ? 0 : $offset + 1 ?>
                to <?= min($offset + $perPage, $totalRows) ?>
                of <?= number_format($totalRows) ?> records
            </div>
        </div>
    </div>

</div>

</body>
</html>
