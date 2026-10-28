
<?php
session_start();

require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Authentication and authorization
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
$name = $_SESSION['full_name'] ?? 'Staff Member';

$allowedRoles = ['admin', 'pharmacy'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. You are not authorized to view medicine alerts.');
}

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/
function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$error = '';
$medicines = [];

$totalMedicines = 0;
$lowStock = 0;
$outOfStock = 0;
$expired = 0;
$expiring30 = 0;
$expiring90 = 0;

/*
|--------------------------------------------------------------------------
| Alert filter
|--------------------------------------------------------------------------
*/
$filter = $_GET['filter'] ?? 'all';

$allowedFilters = [
    'all',
    'low_stock',
    'out_of_stock',
    'expired',
    'expiring30',
    'expiring90'
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

try {
    /*
    |--------------------------------------------------------------------------
    | Summary counts
    |--------------------------------------------------------------------------
    */
    $totalMedicines = (int)$pdo->query(
        'SELECT COUNT(*) FROM medicines'
    )->fetchColumn();

    $lowStock = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM medicines
         WHERE quantity_in_stock <= reorder_level'
    )->fetchColumn();

    $outOfStock = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM medicines
         WHERE quantity_in_stock = 0'
    )->fetchColumn();

    $expired = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM medicines
         WHERE expiry_date IS NOT NULL
           AND expiry_date < CURDATE()'
    )->fetchColumn();

    $expiring30 = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM medicines
         WHERE expiry_date BETWEEN CURDATE()
           AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'
    )->fetchColumn();

    $expiring90 = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM medicines
         WHERE expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)'
    )->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Load medicines requiring attention
    |--------------------------------------------------------------------------
    */
    $sql = 'SELECT
                id,
                medicine_name,
                generic_name,
                category,
                quantity_in_stock,
                reorder_level,
                unit,
                expiry_date,
                supplier
            FROM medicines';

    $conditions = [];
    $params = [];

    switch ($filter) {
        case 'low_stock':
            $conditions[] = 'quantity_in_stock <= reorder_level';
            break;

        case 'out_of_stock':
            $conditions[] = 'quantity_in_stock = 0';
            break;

        case 'expired':
            $conditions[] = 'expiry_date IS NOT NULL';
            $conditions[] = 'expiry_date < CURDATE()';
            break;

        case 'expiring30':
            $conditions[] = 'expiry_date BETWEEN CURDATE()
                AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
            break;

        case 'expiring90':
            $conditions[] = 'expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
            $conditions[] = 'expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)';
            break;

        default:
            $conditions[] = '(
                quantity_in_stock <= reorder_level
                OR (
                    expiry_date IS NOT NULL
                    AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                )
            )';
            break;
    }

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY
                CASE
                    WHEN expiry_date IS NOT NULL
                         AND expiry_date < CURDATE() THEN 1
                    WHEN quantity_in_stock = 0 THEN 2
                    WHEN quantity_in_stock <= reorder_level THEN 3
                    WHEN expiry_date BETWEEN CURDATE()
                         AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 4
                    WHEN expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                         AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 5
                    ELSE 6
                END,
                medicine_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $medicines = $stmt->fetchAll();

} catch (PDOException $ex) {
    error_log('Medicine alerts error: ' . $ex->getMessage());
    $error = 'Unable to load medicine alerts. Please check the database configuration.';
}

/*
|--------------------------------------------------------------------------
| Filter labels
|--------------------------------------------------------------------------
*/
$filterLabels = [
    'all' => 'All Alerts',
    'low_stock' => 'Low Stock',
    'out_of_stock' => 'Out of Stock',
    'expired' => 'Expired Medicines',
    'expiring30' => 'Expiring Within 30 Days',
    'expiring90' => 'Expiring in 31–90 Days'
];

$pageTitle = $filterLabels[$filter];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Medicine Alerts | AZU Clinic PIMS</title>

    <style>
        :root {
            --navy: #202b83;
            --navy-dark: #17205f;
            --orange: #ed791c;
            --orange-light: #fff1e5;
            --background: #f4f6fb;
            --white: #ffffff;
            --text: #263247;
            --muted: #738096;
            --border: #e5e9f2;
            --shadow: 0 5px 22px rgba(32, 43, 131, 0.07);
            --red-bg: #fff0ef;
            --red-text: #a52820;
            --green-bg: #e7f7ef;
            --green-text: #176b45;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background: var(--background);
            line-height: 1.5;
        }

        a {
            text-decoration: none;
        }

        button,
        input,
        select {
            font: inherit;
        }

        .topbar {
            background: var(--white);
            border-bottom: 4px solid var(--orange);
            padding: 14px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
        }

        .brand img {
            width: 245px;
            max-width: 100%;
            height: auto;
            object-fit: contain;
        }

        .brand-text {
            border-left: 1px solid var(--border);
            padding-left: 15px;
        }

        .brand-text strong {
            display: block;
            color: var(--navy);
            font-size: 16px;
        }

        .brand-text span {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .user-info {
            text-align: right;
        }

        .user-info strong {
            display: block;
            color: var(--navy);
            font-size: 14px;
        }

        .role-label {
            display: inline-block;
            margin-top: 5px;
            padding: 5px 10px;
            background: var(--orange-light);
            color: #a84e0c;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .logout {
            display: inline-block;
            padding: 10px 15px;
            border: 1px solid #f0c5c1;
            border-radius: 8px;
            color: #a52820;
            font-size: 13px;
            font-weight: bold;
        }

        .logout:hover {
            background: var(--red-bg);
        }

        .main {
            max-width: 1250px;
            margin: 0 auto;
            padding: 32px 24px 50px;
        }

        .page-heading {
            margin-bottom: 22px;
            padding: 30px;
            color: var(--white);
            background: linear-gradient(
                120deg,
                var(--navy-dark),
                var(--navy)
            );
            border-radius: 16px;
            box-shadow: var(--shadow);
        }

        .eyebrow {
            color: #ffbd83;
            text-transform: uppercase;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .page-heading h1 {
            margin: 8px 0;
            font-size: clamp(25px, 4vw, 32px);
        }

        .page-heading p {
            max-width: 760px;
            margin: 0;
            color: #e6eaff;
            font-size: 14px;
            line-height: 1.7;
        }

        .welcome-accent {
            width: 65px;
            height: 5px;
            margin-top: 18px;
            background: var(--orange);
            border-radius: 5px;
        }

        .quick-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }

        .button {
            display: inline-block;
            padding: 10px 15px;
            border: 1px solid var(--orange);
            border-radius: 8px;
            background: var(--orange);
            color: var(--white);
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            transition: background 0.2s;
        }

        .button:hover {
            background: #d96812;
            border-color: #d96812;
        }

        .button-primary {
            background: var(--navy);
            border-color: var(--navy);
        }

        .button-primary:hover {
            background: var(--navy-dark);
            border-color: var(--navy-dark);
        }

        .button-secondary {
            color: var(--navy);
            background: #edf0ff;
            border-color: #dce1fa;
        }

        .button-secondary:hover {
            background: #dfe5ff;
            border-color: #c8cdef;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin: 24px 0;
        }

        .stat-card {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
            padding: 21px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        .stat-icon {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            border-radius: 12px;
            background: #edf0ff;
            font-size: 23px;
        }

        .stat-card:nth-child(even) .stat-icon {
            background: var(--orange-light);
        }

        .stat-card span {
            display: block;
            color: var(--muted);
            font-size: 12px;
        }

        .stat-card strong {
            display: block;
            margin-top: 6px;
            color: var(--navy);
            font-size: 27px;
            overflow-wrap: anywhere;
        }

        .panel {
            margin-bottom: 24px;
            padding: 24px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: var(--shadow);
        }

        .panel-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }

        .panel h2 {
            margin: 0;
            color: var(--navy);
            font-size: 20px;
        }

        .section-description {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-bottom: 20px;
        }

        .filter-link {
            display: inline-block;
            padding: 8px 12px;
            color: var(--navy);
            background: #edf0ff;
            border: 1px solid #dce1fa;
            border-radius: 8px;
            font-size: 12px;
            font-weight: bold;
        }

        .filter-link:hover,
        .filter-link.active {
            color: var(--white);
            background: var(--navy);
            border-color: var(--navy);
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 9px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--white);
            font-size: 13px;
        }

        th,
        td {
            padding: 13px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #edf0ff;
            color: var(--navy);
            font-size: 12px;
            font-weight: bold;
            white-space: nowrap;
        }

        tbody tr:hover {
            background: #f8f9ff;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }

        .status-expired,
        .status-out {
            color: var(--red-text);
            background: var(--red-bg);
        }

        .status-low,
        .status-soon {
            color: #a84e0c;
            background: var(--orange-light);
        }

        .status-ok {
            color: var(--green-text);
            background: var(--green-bg);
        }

        .notice {
            padding: 13px 16px;
            margin-bottom: 18px;
            border: 1px solid transparent;
            border-radius: 8px;
            font-size: 14px;
        }

        .error {
            color: var(--red-text);
            background: var(--red-bg);
            border-color: #f0c5c1;
        }

        .empty-state {
            padding: 28px 15px;
            text-align: center;
            color: var(--muted);
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            color: var(--muted);
            text-align: center;
            font-size: 12px;
            line-height: 1.8;
        }

        .footer strong {
            color: var(--navy);
        }

        @media (max-width: 900px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .topbar {
                padding: 15px 18px;
            }

            .brand {
                align-items: flex-start;
                flex-direction: column;
                gap: 8px;
            }

            .brand img {
                width: 230px;
            }

            .brand-text {
                border-left: 0;
                padding-left: 0;
            }

            .top-actions {
                width: 100%;
                justify-content: space-between;
            }

            .user-info {
                text-align: left;
            }

            .main {
                padding: 22px 15px 35px;
            }

            .page-heading {
                padding: 22px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .stat-card,
            .panel {
                padding: 18px;
            }

            .quick-links .button {
                flex: 1 1 auto;
            }
        }

        @media print {
            .top-actions,
            .quick-links,
            .filters {
                display: none !important;
            }

            body {
                background: white;
            }

            .main {
                max-width: none;
                padding: 15px;
            }

            .page-heading {
                color: #000;
                background: white;
                border: 1px solid #ccc;
                box-shadow: none;
            }

            .page-heading p,
            .eyebrow {
                color: #333;
            }

            .panel,
            .stat-card {
                box-shadow: none;
                break-inside: avoid;
            }

            .table-wrap {
                overflow: visible;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <div class="brand">
        <img
            src="azu-logo.png"
            alt="Africa Zeal University logo"
            onerror="this.style.display='none'"
        >

        <div class="brand-text">
            <strong>AZU Clinic PIMS</strong>
            <span>Patient Information Management System</span>
        </div>
    </div>

    <div class="top-actions">
        <div class="user-info">
            <strong><?= e($name) ?></strong>
            <span class="role-label"><?= e($role) ?></span>
        </div>

        <a class="logout" href="logout.php">Sign Out ↗</a>
    </div>
</header>

<main class="main">

    <section class="page-heading">
        <div class="eyebrow">Africa Zeal University Clinic</div>
        <h1>Medicine Expiry &amp; Stock Alerts</h1>
        <p>
            Monitor low-stock medicines, unavailable stock, expired medicines,
            and medicines approaching their expiry dates. Review alerts before
            replenishing or dispensing medicines.
        </p>
        <div class="welcome-accent"></div>
    </section>

    <div class="quick-links">
        <a class="button button-primary" href="dashboard.php">
            ← Dashboard
        </a>

        <a class="button button-secondary" href="medicine.php">
            Medicine Inventory
        </a>

        <button
            class="button button-secondary"
            type="button"
            onclick="window.print()"
        >
            Print Alerts
        </button>
    </div>

    <?php if ($error !== ''): ?>
        <div class="notice error" role="alert">
            <?= e($error) ?>
        </div>
    <?php else: ?>

        <section class="stats" aria-label="Medicine alert summary">

            <div class="stat-card">
                <div class="stat-icon">💊</div>
                <div>
                    <span>Total Medicine Types</span>
                    <strong><?= $totalMedicines ?></strong>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📦</div>
                <div>
                    <span>Low Stock</span>
                    <strong><?= $lowStock ?></strong>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🚫</div>
                <div>
                    <span>Out of Stock</span>
                    <strong><?= $outOfStock ?></strong>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">⛔</div>
                <div>
                    <span>Expired Medicines</span>
                    <strong><?= $expired ?></strong>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">⚠️</div>
                <div>
                    <span>Expiring Within 30 Days</span>
                    <strong><?= $expiring30 ?></strong>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div>
                    <span>Expiring in 31–90 Days</span>
                    <strong><?= $expiring90 ?></strong>
                </div>
            </div>

        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2><?= e($pageTitle) ?></h2>
                    <p class="section-description">
                        Select an alert category to review the affected medicines.
                    </p>
                </div>

                <span class="status status-low">
                    <?= count($medicines) ?> medicine(s)
                </span>
            </div>

            <nav class="filters" aria-label="Filter medicine alerts">
                <?php foreach ($filterLabels as $key => $label): ?>
                    <a
                        class="filter-link <?= $filter === $key ? 'active' : '' ?>"
                        href="medicine_alerts.php?filter=<?= e($key) ?>"
                        <?= $filter === $key ? 'aria-current="page"' : '' ?>
                    >
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Generic Name</th>
                            <th>Stock</th>
                            <th>Reorder Level</th>
                            <th>Expiry Date</th>
                            <th>Supplier</th>
                            <th>Alert Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$medicines): ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                No medicines match this alert category.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($medicines as $medicine): ?>
                            <?php
                            $today = date('Y-m-d');
                            $expiryDate = $medicine['expiry_date'] ?? '';

                            $isExpired = $expiryDate !== ''
                                && $expiryDate < $today;

                            $quantity = (int)$medicine['quantity_in_stock'];
                            $reorder = (int)$medicine['reorder_level'];

                            $daysToExpiry = null;

                            if ($expiryDate !== '' && !$isExpired) {
                                $todayDate = new DateTimeImmutable($today);
                                $expiry = new DateTimeImmutable($expiryDate);
                                $daysToExpiry = (int)$todayDate->diff($expiry)->days;
                            }

                            if ($isExpired) {
                                $statusText = 'EXPIRED';
                                $statusClass = 'status-expired';
                            } elseif ($quantity === 0) {
                                $statusText = 'OUT OF STOCK';
                                $statusClass = 'status-out';
                            } elseif ($quantity <= $reorder) {
                                $statusText = 'LOW STOCK';
                                $statusClass = 'status-low';
                            } elseif ($daysToExpiry !== null && $daysToExpiry <= 30) {
                                $statusText = 'EXPIRING SOON';
                                $statusClass = 'status-soon';
                            } elseif ($daysToExpiry !== null && $daysToExpiry <= 90) {
                                $statusText = 'EXPIRING';
                                $statusClass = 'status-soon';
                            } else {
                                $statusText = 'REVIEW';
                                $statusClass = 'status-ok';
                            }
                            ?>

                            <tr>
                                <td>
                                    <strong><?= e($medicine['medicine_name']) ?></strong>
                                    <?php if (!empty($medicine['category'])): ?>
                                        <br>
                                        <small><?= e($medicine['category']) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td><?= e($medicine['generic_name'] ?: '—') ?></td>

                                <td>
                                    <?= $quantity ?>
                                    <?= e($medicine['unit']) ?>
                                </td>

                                <td><?= $reorder ?></td>

                                <td>
                                    <?php if ($expiryDate !== ''): ?>
                                        <?= e($expiryDate) ?>

                                        <?php if ($isExpired): ?>
                                            <br>
                                            <small>Expired</small>
                                        <?php elseif ($daysToExpiry !== null && $daysToExpiry <= 90): ?>
                                            <br>
                                            <small>
                                                <?= $daysToExpiry ?> day(s) remaining
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Not recorded
                                    <?php endif; ?>
                                </td>

                                <td><?= e($medicine['supplier'] ?: '—') ?></td>

                                <td>
                                    <span class="status <?= e($statusClass) ?>">
                                        <?= e($statusText) ?>
                                    </span>
                                </td>

                                <td>
                                    <a
                                        class="button button-primary"
                                        href="medicine.php?edit=<?= (int)$medicine['id'] ?>"
                                    >
                                        Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="section-description">
                Alert counts represent medicine records, not individual units.
                A medicine can qualify for more than one alert category.
            </p>
        </section>

    <?php endif; ?>

    <footer class="footer">
        <strong>Africa Zeal University Clinic</strong><br>
        Patient Information Management System<br>
        Authorized staff access only
    </footer>

</main>

</body>
</html>
