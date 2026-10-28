
<?php
session_start();
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Authentication and permissions
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
$name = $_SESSION['full_name'] ?? 'Staff Member';

$viewRoles = ['admin', 'pharmacy'];
$dispenseRoles = ['admin', 'pharmacy'];

if (!in_array($role, $viewRoles, true)) {
    http_response_code(403);
    exit('Access denied. Only authorized pharmacy staff can access dispensing records.');
}

$canDispense = in_array($role, $dispenseRoles, true);

/*
|--------------------------------------------------------------------------
| Helpers and CSRF protection
|--------------------------------------------------------------------------
*/
function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['dispense_csrf'])) {
    $_SESSION['dispense_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Handle medicine dispensing
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        $token === '' ||
        !hash_equals($_SESSION['dispense_csrf'], $token)
    ) {
        $error = 'Your form session is invalid or expired. Refresh the page and try again.';
    } elseif (!$canDispense) {
        http_response_code(403);
        exit('Access denied. You are not authorized to dispense medicine.');
    } else {
        $patientId = filter_var(
            $_POST['patient_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $medicineId = filter_var(
            $_POST['medicine_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $quantity = filter_var(
            $_POST['quantity_dispensed'] ?? null,
            FILTER_VALIDATE_INT
        );

        $dosage = trim($_POST['dosage_instructions'] ?? '');

        // Use the authenticated account, not a user-supplied staff name.
        $dispensedBy = trim(
            $_SESSION['full_name'] ??
            $_SESSION['username'] ??
            'Authorized Staff'
        );

        if (
            $patientId === false || $patientId === null || $patientId < 1 ||
            $medicineId === false || $medicineId === null || $medicineId < 1 ||
            $quantity === false || $quantity === null || $quantity < 1 ||
            strlen($dosage) > 255
        ) {
            $error = 'Select a valid patient and medicine, enter a positive quantity, and keep dosage instructions within 255 characters.';
        } else {
            try {
                $pdo->beginTransaction();

                // Confirm that the patient exists.
                $patientStmt = $pdo->prepare(
                    'SELECT id FROM patients WHERE id = :id LIMIT 1'
                );
                $patientStmt->execute(['id' => $patientId]);

                if (!$patientStmt->fetch()) {
                    throw new RuntimeException(
                        'The selected patient was not found.'
                    );
                }

                // Lock the medicine row to prevent concurrent over-dispensing.
                $medicineStmt = $pdo->prepare(
                    'SELECT id, medicine_name, quantity_in_stock, expiry_date
                     FROM medicines
                     WHERE id = :id
                     FOR UPDATE'
                );
                $medicineStmt->execute(['id' => $medicineId]);
                $medicine = $medicineStmt->fetch();

                if (!$medicine) {
                    throw new RuntimeException(
                        'The selected medicine was not found.'
                    );
                }

                if (
                    !empty($medicine['expiry_date']) &&
                    $medicine['expiry_date'] < date('Y-m-d')
                ) {
                    throw new RuntimeException(
                        'This medicine has expired and cannot be dispensed.'
                    );
                }

                $stock = (int)$medicine['quantity_in_stock'];

                if ($quantity > $stock) {
                    throw new RuntimeException(
                        'Insufficient stock. Available quantity: ' . $stock
                    );
                }

                // Record the dispensing event.
                $insert = $pdo->prepare(
                    'INSERT INTO medicine_dispensing
                        (
                            medicine_id,
                            patient_id,
                            quantity_dispensed,
                            dosage_instructions,
                            dispensed_by
                        )
                     VALUES
                        (
                            :medicine_id,
                            :patient_id,
                            :quantity,
                            :dosage,
                            :dispensed_by
                        )'
                );

                $insert->execute([
                    'medicine_id' => $medicineId,
                    'patient_id' => $patientId,
                    'quantity' => $quantity,
                    'dosage' => $dosage !== '' ? $dosage : null,
                    'dispensed_by' => $dispensedBy
                ]);

                // Deduct stock only if enough stock remains.
                $update = $pdo->prepare(
                    'UPDATE medicines
                     SET quantity_in_stock = quantity_in_stock - :quantity
                     WHERE id = :id
                       AND quantity_in_stock >= :minimum'
                );

                $update->execute([
                    'quantity' => $quantity,
                    'id' => $medicineId,
                    'minimum' => $quantity
                ]);

                if ($update->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Stock could not be updated. No dispensing was saved.'
                    );
                }

                $pdo->commit();

                $_SESSION['dispense_flash'] =
                    'Successfully dispensed ' . $quantity .
                    ' unit(s) of ' . $medicine['medicine_name'] . '.';

                // Rotate the token after successful dispensing.
                $_SESSION['dispense_csrf'] = bin2hex(random_bytes(32));

                header('Location: dispense.php');
                exit;

            } catch (RuntimeException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $ex->getMessage();

            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Medicine dispensing error: ' . $ex->getMessage());

                $error = 'The dispensing operation failed. No changes were saved. Check the database tables and try again.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Flash message
|--------------------------------------------------------------------------
*/
if (isset($_SESSION['dispense_flash'])) {
    $message = $_SESSION['dispense_flash'];
    unset($_SESSION['dispense_flash']);
}

/*
|--------------------------------------------------------------------------
| Load patients, medicines, and recent dispensing history
|--------------------------------------------------------------------------
*/
$patients = $pdo->query(
    'SELECT id, patient_number, first_name, last_name
     FROM patients
     ORDER BY first_name, last_name'
)->fetchAll();

$medicines = $pdo->query(
    'SELECT id, medicine_name, generic_name, unit,
            quantity_in_stock, expiry_date
     FROM medicines
     WHERE quantity_in_stock > 0
     ORDER BY medicine_name'
)->fetchAll();

$history = $pdo->query(
    'SELECT d.id, d.quantity_dispensed, d.dosage_instructions,
            d.dispensed_by, d.dispensed_at,
            m.medicine_name, m.unit,
            p.patient_number, p.first_name, p.last_name
     FROM medicine_dispensing d
     INNER JOIN medicines m ON m.id = d.medicine_id
     INNER JOIN patients p ON p.id = d.patient_id
     ORDER BY d.dispensed_at DESC, d.id DESC
     LIMIT 100'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Medicine Dispensing | AZU Clinic PIMS</title>

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
        input,
        select,
        textarea {
            font: inherit;
        }

        /* Shared dashboard header */
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

        .eyebrow {
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

        /* Cards and panels */
        .panel {
            margin-bottom: 24px;
            padding: 24px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: var(--shadow);
        }

        .panel-heading {
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

        /* Dispensing form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-size: 13px;
            font-weight: bold;
        }

        input,
        select,
        textarea {
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

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--navy);
            box-shadow: 0 0 0 3px rgba(32, 43, 131, 0.10);
        }

        input[readonly] {
            background: #f4f6fb;
            color: var(--muted);
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .help {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        /* Dashboard colors for buttons */
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

        /* History table */
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
            vertical-align: top;
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

        .empty-state {
            padding: 20px;
            color: var(--muted);
            text-align: center;
        }

        /* Shared dashboard footer */
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

        @media (max-width: 800px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
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

            .panel {
                padding: 18px;
            }

            .form-actions .button {
                flex: 1 1 auto;
            }
        }

        @media print {
            .top-actions,
            .dispense-form-panel,
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

            .panel {
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

        <h1>Medicine Dispensing</h1>

        <p>
            Record medicines issued to registered patients, maintain accurate
            stock levels, and review recent dispensing activity.
        </p>

        <div class="welcome-accent"></div>
    </section>

    <div class="access-note">
        Authorized pharmacy staff only. Verify the patient, medicine,
        quantity, prescription instructions, available stock, and expiry
        date before dispensing.
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

    <section class="panel dispense-form-panel">
        <div class="panel-heading">
            <h2>Dispense Medicine to Patient</h2>
            <p class="section-description">
                Select a registered patient and medicine, then enter the
                quantity to dispense.
            </p>
        </div>

        <?php if (!$patients): ?>
            <div class="notice error">
                No registered patients were found. Register a patient first.
            </div>

            <a class="button button-primary" href="patients.php">
                Register Patient
            </a>

        <?php elseif (!$medicines): ?>
            <div class="notice error">
                No medicines with available stock were found. Add medicine
                or update the inventory first.
            </div>

            <a class="button button-primary" href="medicine.php">
                Open Medicine Inventory
            </a>

        <?php else: ?>
            <form method="post" action="dispense.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['dispense_csrf']) ?>"
                >

                <div class="form-grid">
                    <div>
                        <label for="patient_id">Select Patient *</label>

                        <select name="patient_id" id="patient_id" required>
                            <option value="">-- Select patient --</option>

                            <?php foreach ($patients as $patient): ?>
                                <option value="<?= (int)$patient['id'] ?>">
                                    <?= e(
                                        $patient['patient_number'] . ' — ' .
                                        $patient['first_name'] . ' ' .
                                        $patient['last_name']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="medicine_id">Select Medicine *</label>

                        <select name="medicine_id" id="medicine_id" required>
                            <option value="">-- Select medicine --</option>

                            <?php foreach ($medicines as $medicine): ?>
                                <option value="<?= (int)$medicine['id'] ?>">
                                    <?= e(
                                        $medicine['medicine_name'] .
                                        (!empty($medicine['generic_name'])
                                            ? ' (' . $medicine['generic_name'] . ')'
                                            : '') .
                                        ' — Stock: ' .
                                        $medicine['quantity_in_stock'] . ' ' .
                                        $medicine['unit'] .
                                        (!empty($medicine['expiry_date'])
                                            ? ' — Exp: ' . $medicine['expiry_date']
                                            : '')
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p class="help">
                            Expired medicines are rejected by the server.
                        </p>
                    </div>

                    <div>
                        <label for="quantity_dispensed">
                            Quantity to Dispense *
                        </label>

                        <input
                            type="number"
                            id="quantity_dispensed"
                            name="quantity_dispensed"
                            min="1"
                            step="1"
                            required
                        >
                    </div>

                    <div>
                        <label for="dispensing_staff">Dispensing Staff</label>

                        <input
                            type="text"
                            id="dispensing_staff"
                            value="<?= e(
                                $_SESSION['full_name'] ??
                                $_SESSION['username'] ??
                                'Authorized Staff'
                            ) ?>"
                            readonly
                        >

                        <p class="help">
                            This name comes from the signed-in account.
                        </p>
                    </div>

                    <div class="full-width">
                        <label for="dosage_instructions">
                            Dosage Instructions
                        </label>

                        <textarea
                            id="dosage_instructions"
                            name="dosage_instructions"
                            maxlength="255"
                            placeholder="Enter the authorized prescription instructions"
                        ></textarea>

                        <p class="help">
                            Follow the authorized prescription and clinic
                            procedures. Maximum 255 characters.
                        </p>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit">
                        Record Dispensing
                    </button>

                    <a class="button button-secondary" href="medicine.php">
                        View Inventory
                    </a>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <h2>Recent Dispensing History</h2>
            <p class="section-description">
                The latest 100 dispensing records are shown below.
            </p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date and Time</th>
                        <th>Patient Number</th>
                        <th>Patient Name</th>
                        <th>Medicine</th>
                        <th>Quantity</th>
                        <th>Dosage Instructions</th>
                        <th>Dispensed By</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$history): ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                No dispensing records found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $record): ?>
                            <tr>
                                <td><?= e($record['dispensed_at']) ?></td>

                                <td>
                                    <?= e($record['patient_number']) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $record['first_name'] . ' ' .
                                        $record['last_name']
                                    ) ?>
                                </td>

                                <td><?= e($record['medicine_name']) ?></td>

                                <td>
                                    <?= (int)$record['quantity_dispensed'] ?>
                                    <?= e($record['unit']) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $record['dosage_instructions'] ?: '—'
                                    ) ?>
                                </td>

                                <td>
                                    <?= e($record['dispensed_by'] ?: '—') ?>
                                </td>
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
