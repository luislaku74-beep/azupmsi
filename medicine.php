
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

$viewRoles = ['admin', 'pharmacy'];
$manageRoles = ['admin', 'pharmacy'];

if (!in_array($role, $viewRoles, true)) {
    http_response_code(403);
    exit('Access denied. You are not authorized to view the medicine inventory.');
}

$canManage = in_array($role, $manageRoles, true);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['medicine_csrf'])) {
    $_SESSION['medicine_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$editMedicine = null;

/*
|--------------------------------------------------------------------------
| Handle form submissions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (
        $submittedToken === '' ||
        !hash_equals($_SESSION['medicine_csrf'], $submittedToken)
    ) {
        $error = 'Your form session is invalid or expired. Please refresh and try again.';
    } elseif (!$canManage) {
        http_response_code(403);
        exit('Access denied. Only administrators and pharmacy staff can change inventory.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            $id = ($id === false || $id === null) ? 0 : (int)$id;

            $nameInput = trim($_POST['medicine_name'] ?? '');
            $generic = trim($_POST['generic_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $unit = trim($_POST['unit'] ?? '');

            $quantity = filter_var(
                $_POST['quantity_in_stock'] ?? null,
                FILTER_VALIDATE_INT
            );

            $reorder = filter_var(
                $_POST['reorder_level'] ?? null,
                FILTER_VALIDATE_INT
            );

            $priceInput = trim((string)($_POST['unit_price'] ?? ''));
            $supplier = trim($_POST['supplier'] ?? '');
            $expiry = trim($_POST['expiry_date'] ?? '');

            if ($id < 0) {
                $error = 'Invalid medicine ID.';
            } elseif (
                $nameInput === '' ||
                strlen($nameInput) > 150 ||
                strlen($generic) > 150 ||
                strlen($category) > 100 ||
                $unit === '' ||
                strlen($unit) > 50 ||
                strlen($supplier) > 150
            ) {
                $error = 'Please provide a valid medicine name and unit. Check the maximum lengths of all fields.';
            } elseif (
                $quantity === false ||
                $quantity === null ||
                $reorder === false ||
                $reorder === null ||
                $quantity < 0 ||
                $reorder < 0
            ) {
                $error = 'Stock quantity and reorder level must be non-negative whole numbers.';
            } elseif (
                $priceInput === '' ||
                !is_numeric($priceInput) ||
                !is_finite((float)$priceInput) ||
                (float)$priceInput < 0 ||
                (float)$priceInput > 999999999999
            ) {
                $error = 'Please enter a valid, non-negative unit price.';
            } elseif (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $priceInput)) {
                $error = 'Unit price must have no more than two decimal places.';
            } elseif (
                $expiry !== '' &&
                (
                    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) ||
                    !checkdate(
                        (int)substr($expiry, 5, 2),
                        (int)substr($expiry, 8, 2),
                        (int)substr($expiry, 0, 4)
                    )
                )
            ) {
                $error = 'Please enter a valid expiry date.';
            } else {
                try {
                    if ($id > 0) {
                        $check = $pdo->prepare(
                            'SELECT id FROM medicines WHERE id = :id LIMIT 1'
                        );
                        $check->execute(['id' => $id]);

                        if (!$check->fetch()) {
                            $error = 'The medicine you are trying to edit was not found.';
                        }
                    }

                    if ($error === '') {
                        $values = [
                            'name' => $nameInput,
                            'generic' => $generic !== '' ? $generic : null,
                            'category' => $category !== '' ? $category : null,
                            'unit' => $unit,
                            'quantity' => $quantity,
                            'reorder' => $reorder,
                            'price' => $priceInput,
                            'expiry' => $expiry !== '' ? $expiry : null,
                            'supplier' => $supplier !== '' ? $supplier : null
                        ];

                        if ($id > 0) {
                            $stmt = $pdo->prepare(
                                "UPDATE medicines SET
                                    medicine_name = :name,
                                    generic_name = :generic,
                                    category = :category,
                                    unit = :unit,
                                    quantity_in_stock = :quantity,
                                    reorder_level = :reorder,
                                    unit_price = :price,
                                    expiry_date = :expiry,
                                    supplier = :supplier
                                 WHERE id = :id"
                            );

                            $values['id'] = $id;
                            $stmt->execute($values);
                            $message = 'Medicine updated successfully.';
                        } else {
                            $stmt = $pdo->prepare(
                                "INSERT INTO medicines (
                                    medicine_name,
                                    generic_name,
                                    category,
                                    unit,
                                    quantity_in_stock,
                                    reorder_level,
                                    unit_price,
                                    expiry_date,
                                    supplier
                                ) VALUES (
                                    :name,
                                    :generic,
                                    :category,
                                    :unit,
                                    :quantity,
                                    :reorder,
                                    :price,
                                    :expiry,
                                    :supplier
                                )"
                            );

                            $stmt->execute($values);
                            $message = 'Medicine added successfully.';
                        }

                        $_SESSION['medicine_flash'] = $message;
                        $_SESSION['medicine_csrf'] = bin2hex(random_bytes(32));

                        header('Location: medicine.php');
                        exit;
                    }
                } catch (PDOException $ex) {
                    error_log('Medicine save error: ' . $ex->getMessage());
                    $error = 'Unable to save medicine. Please check the information and database configuration.';
                }
            }
        } else {
            $error = 'Invalid form action.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Flash message
|--------------------------------------------------------------------------
*/
if (isset($_SESSION['medicine_flash'])) {
    $message = $_SESSION['medicine_flash'];
    unset($_SESSION['medicine_flash']);
}

/*
|--------------------------------------------------------------------------
| Load medicine for editing
|--------------------------------------------------------------------------
*/
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId === false || $editId === null || $editId < 1) {
        $error = 'Invalid medicine ID.';
    } elseif (!$canManage) {
        http_response_code(403);
        exit('Access denied. You cannot edit medicine inventory.');
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM medicines WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $editId]);
        $editMedicine = $stmt->fetch();

        if (!$editMedicine) {
            $error = 'Medicine not found.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Search inventory
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');

$sql = 'SELECT * FROM medicines';
$params = [];

if ($search !== '') {
    $sql .= " WHERE LOWER(medicine_name) LIKE LOWER(:name)
              OR LOWER(generic_name) LIKE LOWER(:generic)
              OR LOWER(category) LIKE LOWER(:category)";

    $term = '%' . $search . '%';

    $params = [
        'name' => $term,
        'generic' => $term,
        'category' => $term
    ];
}

$sql .= ' ORDER BY medicine_name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$medicines = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Inventory totals
|--------------------------------------------------------------------------
*/
$totalMedicines = (int)$pdo->query(
    'SELECT COUNT(*) FROM medicines'
)->fetchColumn();

$lowStock = (int)$pdo->query(
    'SELECT COUNT(*) FROM medicines
     WHERE quantity_in_stock <= reorder_level'
)->fetchColumn();

$today = date('Y-m-d');
$in90Days = date('Y-m-d', strtotime('+90 days'));

$expiredStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM medicines
     WHERE expiry_date IS NOT NULL
       AND expiry_date < :today'
);
$expiredStmt->execute(['today' => $today]);
$expired = (int)$expiredStmt->fetchColumn();

$expiringSoonStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM medicines
     WHERE expiry_date BETWEEN :today AND :in_90_days'
);
$expiringSoonStmt->execute([
    'today' => $today,
    'in_90_days' => $in90Days
]);
$expiringSoon = (int)$expiringSoonStmt->fetchColumn();

$form = $editMedicine ?: [
    'id' => '',
    'medicine_name' => '',
    'generic_name' => '',
    'category' => '',
    'unit' => 'tablet',
    'quantity_in_stock' => 0,
    'reorder_level' => 10,
    'unit_price' => '0.00',
    'expiry_date' => '',
    'supplier' => ''
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Medicine Inventory | AZU Clinic PIMS</title>

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
        input {
            font: inherit;
        }

        /* Dashboard-style header */
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
            transition: 0.2s;
        }

        .logout:hover {
            background: #fff0ef;
        }

        /* Main content */
        .main {
            max-width: 1250px;
            margin: 0 auto;
            padding: 32px 24px 50px;
        }

        .page-heading {
            margin-bottom: 22px;
        }

        .page-heading .eyebrow {
            color: var(--orange);
            text-transform: uppercase;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .page-heading h1 {
            margin: 8px 0;
            color: var(--navy);
            font-size: clamp(25px, 4vw, 32px);
        }

        .page-heading p {
            margin: 0;
            max-width: 750px;
            color: var(--muted);
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

        .access-note {
            padding: 13px 16px;
            margin-bottom: 22px;
            background: var(--orange-light);
            border-left: 4px solid var(--orange);
            border-radius: 7px;
            color: #82400f;
            font-size: 13px;
        }

        /* Dashboard-style statistic cards */
        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
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

        /* Panels and forms */
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-size: 13px;
            font-weight: bold;
        }

        input {
            width: 100%;
            min-width: 0;
            padding: 11px 12px;
            color: var(--text);
            background: var(--white);
            border: 1px solid #d5dbea;
            border-radius: 7px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input:focus {
            outline: none;
            border-color: var(--navy);
            box-shadow: 0 0 0 3px rgba(32, 43, 131, 0.10);
        }

        /* Buttons use the dashboard's orange accent */
        .button {
            display: inline-block;
            padding: 11px 16px;
            border: 1px solid var(--orange);
            border-radius: 8px;
            background: var(--orange);
            color: var(--white);
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            transition: background 0.2s, border-color 0.2s;
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
            color: var(--navy-dark);
            background: #dfe5ff;
            border-color: #c8cdef;
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 22px;
        }

        .search-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }

        .search-row input {
            flex: 1 1 280px;
            max-width: 500px;
        }

        /* Inventory table */
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

        .status-ok {
            background: #e7f7ef;
            color: #176b45;
        }

        .status-low {
            background: var(--orange-light);
            color: #a84e0c;
        }

        .status-expired {
            background: #fff0ef;
            color: #a52820;
        }

        .actions {
            white-space: nowrap;
        }

        .actions .button {
            padding: 7px 11px;
            font-size: 12px;
        }

        /* Feedback messages */
        .notice {
            padding: 13px 16px;
            margin-bottom: 18px;
            border: 1px solid transparent;
            border-radius: 8px;
            font-size: 14px;
        }

        .success {
            background: #e7f7ef;
            color: #176b45;
            border-color: #b9e8ce;
        }

        .error {
            background: #fff0ef;
            color: #a52820;
            border-color: #f0c5c1;
        }

        /* Dashboard-style footer */
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

        @media (max-width: 1050px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .form-grid {
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

            .stats,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 18px;
            }

            .panel {
                padding: 18px;
            }

            .search-row input {
                flex-basis: 100%;
                max-width: none;
            }

            .form-actions .button {
                flex: 1 1 auto;
            }
        }

        @media print {
            .top-actions,
            .form-panel,
            .search-row,
            .actions,
            .access-note {
                display: none !important;
            }

            body {
                background: white;
            }

            .main {
                max-width: none;
                padding: 15px;
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
        <h1>Medicine Inventory</h1>
        <p>
            Manage medicine stock, monitor expiry dates, check low-stock
            alerts, and review the clinic's available medicines.
        </p>
        <div class="welcome-accent"></div>
    </section>

    <div class="access-note">
        Authorized staff only. Check medicine expiry dates and stock levels
        before dispensing medicines.
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice success" role="status">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error" role="alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="stats" aria-label="Inventory summary">
        <div class="stat-card">
            <div class="stat-icon">💊</div>
            <div>
                <span>Medicine Types</span>
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
            <div class="stat-icon">⚠️</div>
            <div>
                <span>Expired Medicines</span>
                <strong><?= $expired ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📅</div>
            <div>
                <span>Expiring Within 90 Days</span>
                <strong><?= $expiringSoon ?></strong>
            </div>
        </div>
    </section>

    <?php if ($canManage): ?>
        <section class="panel form-panel">
            <div class="panel-heading">
                <div>
                    <h2>
                        <?= $editMedicine ? 'Edit Medicine' : 'Add New Medicine' ?>
                    </h2>
                    <p class="section-description">
                        Fields marked with * are required.
                    </p>
                </div>
            </div>

            <form
                method="post"
                action="medicine.php<?= $editMedicine ? '?edit=' . (int)$editMedicine['id'] : '' ?>"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['medicine_csrf']) ?>"
                >

                <input type="hidden" name="action" value="save">

                <input
                    type="hidden"
                    name="id"
                    value="<?= e($form['id']) ?>"
                >

                <div class="form-grid">
                    <div>
                        <label for="medicine_name">Medicine Name *</label>
                        <input
                            id="medicine_name"
                            name="medicine_name"
                            required
                            maxlength="150"
                            value="<?= e($form['medicine_name']) ?>"
                        >
                    </div>

                    <div>
                        <label for="generic_name">Generic Name</label>
                        <input
                            id="generic_name"
                            name="generic_name"
                            maxlength="150"
                            value="<?= e($form['generic_name']) ?>"
                        >
                    </div>

                    <div>
                        <label for="category">Category</label>
                        <input
                            id="category"
                            name="category"
                            maxlength="100"
                            placeholder="e.g. Antibiotic"
                            value="<?= e($form['category']) ?>"
                        >
                    </div>

                    <div>
                        <label for="unit">Unit *</label>
                        <input
                            id="unit"
                            name="unit"
                            required
                            maxlength="50"
                            placeholder="tablet, bottle, pack"
                            value="<?= e($form['unit']) ?>"
                        >
                    </div>

                    <div>
                        <label for="quantity_in_stock">Quantity in Stock *</label>
                        <input
                            id="quantity_in_stock"
                            name="quantity_in_stock"
                            type="number"
                            min="0"
                            step="1"
                            required
                            value="<?= e($form['quantity_in_stock']) ?>"
                        >
                    </div>

                    <div>
                        <label for="reorder_level">Low-Stock Alert Level *</label>
                        <input
                            id="reorder_level"
                            name="reorder_level"
                            type="number"
                            min="0"
                            step="1"
                            required
                            value="<?= e($form['reorder_level']) ?>"
                        >
                    </div>

                    <div>
                        <label for="unit_price">Unit Price *</label>
                        <input
                            id="unit_price"
                            name="unit_price"
                            type="number"
                            min="0"
                            max="999999999999"
                            step="0.01"
                            required
                            value="<?= e($form['unit_price']) ?>"
                        >
                    </div>

                    <div>
                        <label for="expiry_date">Expiry Date</label>
                        <input
                            id="expiry_date"
                            name="expiry_date"
                            type="date"
                            value="<?= e($form['expiry_date']) ?>"
                        >
                    </div>

                    <div>
                        <label for="supplier">Supplier</label>
                        <input
                            id="supplier"
                            name="supplier"
                            maxlength="150"
                            value="<?= e($form['supplier']) ?>"
                        >
                    </div>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit">
                        <?= $editMedicine ? 'Update Medicine' : 'Save Medicine' ?>
                    </button>

                    <?php if ($editMedicine): ?>
                        <a class="button button-secondary" href="medicine.php">
                            Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Medicine Inventory List</h2>
                <p class="section-description">
                    Search and review recorded medicines and their stock status.
                </p>
            </div>
        </div>

        <form method="get" action="medicine.php" class="search-row">
            <input
                type="search"
                name="search"
                aria-label="Search medicines"
                placeholder="Search medicine, generic name or category"
                value="<?= e($search) ?>"
            >

            <button class="button button-primary" type="submit">
                Search
            </button>

            <a class="button button-secondary" href="medicine.php">
                Clear
            </a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Generic Name</th>
                        <th>Category</th>
                        <th>Stock</th>
                        <th>Unit</th>
                        <th>Unit Price</th>
                        <th>Expiry Date</th>
                        <th>Supplier</th>
                        <th>Status</th>

                        <?php if ($canManage): ?>
                            <th>Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$medicines): ?>
                        <tr>
                            <td colspan="<?= $canManage ? 10 : 9 ?>">
                                No medicines found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($medicines as $medicine): ?>
                            <?php
                            $isExpired = !empty($medicine['expiry_date'])
                                && $medicine['expiry_date'] < date('Y-m-d');

                            $isLow = (int)$medicine['quantity_in_stock']
                                <= (int)$medicine['reorder_level'];
                            ?>

                            <tr>
                                <td><?= e($medicine['medicine_name']) ?></td>
                                <td><?= e($medicine['generic_name']) ?></td>
                                <td><?= e($medicine['category']) ?></td>
                                <td><?= (int)$medicine['quantity_in_stock'] ?></td>
                                <td><?= e($medicine['unit']) ?></td>
                                <td><?= number_format((float)$medicine['unit_price'], 2) ?></td>
                                <td><?= e($medicine['expiry_date'] ?: '—') ?></td>
                                <td><?= e($medicine['supplier']) ?></td>

                                <td>
                                    <?php if ($isExpired): ?>
                                        <span class="status status-expired">
                                            Expired
                                        </span>
                                    <?php elseif ($isLow): ?>
                                        <span class="status status-low">
                                            Low Stock
                                        </span>
                                    <?php else: ?>
                                        <span class="status status-ok">
                                            In Stock
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <?php if ($canManage): ?>
                                    <td class="actions">
                                        <a
                                            class="button button-primary"
                                            href="medicine.php?edit=<?= (int)$medicine['id'] ?>"
                                        >
                                            Edit
                                        </a>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="footer">
        <strong>Africa Zeal University Clinic</strong><br>
        Patient Information Management System<br>
        Authorized staff access only
    </footer>

</main>

</body>
</html>
