<?php
session_start();
require_once 'db.php';

/* -------------------- AUTHENTICATION -------------------- */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

$allowedRoles = array(
    'admin',
    'clinician',
    'pharmacy',
    'receptionist',
    'doctor',
    'nurse',
    'records',
    'laboratory'
);

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. You are not authorized to view reports.');
}

$userName = isset($_SESSION['full_name'])
    ? $_SESSION['full_name']
    : 'Staff Member';

/* -------------------- HELPERS -------------------- */
function e($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

function validDate($value)
{
    if (!is_string($value) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    $parts = explode('-', $value);

    return checkdate(
        (int)$parts[1],
        (int)$parts[2],
        (int)$parts[0]
    );
}

function fetchCount($pdo, $sql)
{
    return (int)$pdo->query($sql)->fetchColumn();
}

/* -------------------- DATE FILTER -------------------- */
$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');

$from = isset($_GET['from']) ? $_GET['from'] : $defaultFrom;
$to = isset($_GET['to']) ? $_GET['to'] : $today;

$error = '';

if (!validDate($from) || !validDate($to) || $from > $to) {
    $from = $defaultFrom;
    $to = $today;
    $error = 'Please select a valid date range. The start date cannot be after the end date.';
}

$startDateTime = $from . ' 00:00:00';
$endDateTime = $to . ' 23:59:59';

/* -------------------- OVERVIEW TOTALS -------------------- */
$totalPatients = fetchCount($pdo, 'SELECT COUNT(*) FROM patients');
$totalMedicines = fetchCount($pdo, 'SELECT COUNT(*) FROM medicines');

$lowStock = fetchCount(
    $pdo,
    'SELECT COUNT(*) FROM medicines
     WHERE quantity_in_stock <= reorder_level'
);

$expiredMedicinesStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM medicines
     WHERE expiry_date IS NOT NULL
       AND expiry_date < :today'
);
$expiredMedicinesStmt->execute(['today' => date('Y-m-d')]);
$expiredMedicines = (int)$expiredMedicinesStmt->fetchColumn();

/* -------------------- APPOINTMENTS -------------------- */
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM appointments
     WHERE appointment_date BETWEEN :start_date AND :end_date'
);
$stmt->execute(array(
    'start_date' => $from,
    'end_date' => $to
));
$appointmentCount = (int)$stmt->fetchColumn();

/* -------------------- CLINICAL VISITS -------------------- */
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM medical_records
     WHERE visit_date BETWEEN :start_date AND :end_date'
);
$stmt->execute(array(
    'start_date' => $from,
    'end_date' => $to
));
$visitCount = (int)$stmt->fetchColumn();

/* -------------------- DISPENSING SUMMARY -------------------- */
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS records_count,
            COALESCE(SUM(quantity_dispensed), 0) AS units_dispensed
     FROM medicine_dispensing
     WHERE dispensed_at BETWEEN :start_time AND :end_time'
);
$stmt->execute(array(
    'start_time' => $startDateTime,
    'end_time' => $endDateTime
));
$dispensingSummary = $stmt->fetch(PDO::FETCH_ASSOC);

$dispensingCount = (int)$dispensingSummary['records_count'];
$unitsDispensed = (int)$dispensingSummary['units_dispensed'];

/* -------------------- APPOINTMENT STATUS -------------------- */
$stmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS total
     FROM appointments
     WHERE appointment_date BETWEEN :start_date AND :end_date
     GROUP BY status
     ORDER BY total DESC'
);
$stmt->execute(array(
    'start_date' => $from,
    'end_date' => $to
));
$appointmentStatuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- DAILY VISITS -------------------- */
$stmt = $pdo->prepare(
    'SELECT visit_date, COUNT(*) AS total
     FROM medical_records
     WHERE visit_date BETWEEN :start_date AND :end_date
     GROUP BY visit_date
     ORDER BY visit_date DESC
     LIMIT 31'
);
$stmt->execute(array(
    'start_date' => $from,
    'end_date' => $to
));
$dailyVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- MOST DISPENSED MEDICINES -------------------- */
$stmt = $pdo->prepare(
    'SELECT m.medicine_name, m.unit,
            SUM(d.quantity_dispensed) AS total_quantity,
            COUNT(d.id) AS dispensing_records
     FROM medicine_dispensing d
     INNER JOIN medicines m ON m.id = d.medicine_id
     WHERE d.dispensed_at BETWEEN :start_time AND :end_time
     GROUP BY m.id, m.medicine_name, m.unit
     ORDER BY total_quantity DESC, m.medicine_name ASC
     LIMIT 10'
);
$stmt->execute(array(
    'start_time' => $startDateTime,
    'end_time' => $endDateTime
));
$topMedicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- LOW STOCK -------------------- */
$lowStockMedicines = $pdo->query(
    'SELECT medicine_name, quantity_in_stock,
            reorder_level, unit
     FROM medicines
     WHERE quantity_in_stock <= reorder_level
     ORDER BY quantity_in_stock ASC, medicine_name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- EXPIRY ALERTS -------------------- */
$expiryMedicinesStmt = $pdo->prepare(
    'SELECT medicine_name, quantity_in_stock, unit, expiry_date
     FROM medicines
     WHERE expiry_date IS NOT NULL
       AND expiry_date <= :in_90_days
     ORDER BY expiry_date ASC'
);
$expiryMedicinesStmt->execute([
    'in_90_days' => date('Y-m-d', strtotime('+90 days'))
]);
$expiryMedicines = $expiryMedicinesStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- APPOINTMENT DETAILS -------------------- */
$stmt = $pdo->prepare(
    'SELECT a.appointment_date, a.appointment_time,
            a.doctor_name, a.reason, a.status,
            p.patient_number, p.first_name, p.last_name
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     WHERE a.appointment_date BETWEEN :start_date AND :end_date
     ORDER BY a.appointment_date DESC, a.appointment_time DESC
     LIMIT 200'
);
$stmt->execute(array(
    'start_date' => $from,
    'end_date' => $to
));
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports &amp; Analytics | AZU PIMS</title>

<style>
:root {
    --navy: #174a70;
    --navy-dark: #103550;
    --green: #087f5b;
    --green-light: #e5f5ee;
    --blue-light: #eaf2f8;
    --page: #f3f7fa;
    --white: #ffffff;
    --text: #25364a;
    --muted: #6b7b8e;
    --border: #e0e8ef;
    --red: #b42318;
    --red-light: #fff0ee;
    --amber: #946200;
    --amber-light: #fff6dc;
    --shadow: 0 5px 18px rgba(23, 74, 112, 0.07);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: var(--page);
    color: var(--text);
    line-height: 1.5;
}

header {
    color: white;
    padding: 25px 5%;
    background: linear-gradient(120deg, var(--navy-dark), var(--navy));
    border-bottom: 5px solid var(--green);
}

.header-inner {
    max-width: 1500px;
    margin: auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.brand {
    display: flex;
    align-items: center;
    gap: 14px;
}

.logo-mark {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    background: white;
    color: var(--green);
    display: flex;
    justify-content: center;
    align-items: center;
    font-weight: 800;
    font-size: 20px;
    border: 2px solid rgba(255,255,255,.65);
    flex-shrink: 0;
}

header h1 {
    margin: 0 0 4px;
    font-size: 25px;
    line-height: 1.3;
}

header p {
    margin: 0;
    color: #dbe9f3;
    font-size: 14px;
}

.user-info {
    font-size: 13px;
    text-align: right;
    color: #e4edf5;
}

.user-info strong {
    display: block;
    color: white;
    font-size: 15px;
}

nav {
    background: white;
    padding: 0 5%;
    border-bottom: 1px solid var(--border);
    box-shadow: 0 2px 7px rgba(0,0,0,.025);
}

.nav-inner {
    max-width: 1500px;
    margin: auto;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

nav a {
    display: inline-block;
    padding: 15px 12px;
    color: var(--navy);
    text-decoration: none;
    font-size: 13px;
    font-weight: bold;
    border-bottom: 3px solid transparent;
}

nav a:hover {
    color: var(--green);
    background: #f6faf8;
}

nav a.active {
    color: var(--green);
    border-bottom-color: var(--green);
}

.container {
    width: 92%;
    max-width: 1500px;
    margin: 28px auto;
}

.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 22px;
}

.page-heading h2 {
    margin: 0 0 5px;
    font-size: 25px;
    color: var(--navy-dark);
}

.page-heading p {
    margin: 0;
    color: var(--muted);
    font-size: 14px;
}

.period-badge {
    padding: 9px 13px;
    border-radius: 8px;
    background: var(--green-light);
    color: var(--green);
    font-size: 13px;
    font-weight: bold;
}

.panel, .card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow);
}

.panel {
    padding: 23px;
    margin-bottom: 22px;
}

.panel-heading {
    margin-bottom: 17px;
}

.panel h2 {
    color: var(--navy-dark);
    font-size: 18px;
    margin: 0 0 6px;
}

.panel-heading p, .muted {
    color: var(--muted);
    font-size: 13px;
    margin: 0;
}

.filters {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 15px;
}

.filters div {
    min-width: 190px;
    flex: 0 1 220px;
}

label {
    display: block;
    margin-bottom: 7px;
    color: var(--text);
    font-size: 13px;
    font-weight: bold;
}

input[type="date"] {
    width: 100%;
    padding: 11px 12px;
    background: white;
    border: 1px solid #cbd7e2;
    border-radius: 7px;
    color: var(--text);
    font: inherit;
    font-size: 14px;
}

input[type="date"]:focus {
    outline: 2px solid #b5dfce;
    border-color: var(--green);
}

button, .button {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 7px;
    border: 0;
    border-radius: 7px;
    padding: 11px 16px;
    background: var(--green);
    color: white;
    cursor: pointer;
    text-decoration: none;
    font-size: 13px;
    font-weight: bold;
    font-family: inherit;
    min-height: 42px;
}

button:hover, .button:hover {
    background: #066849;
}

.secondary {
    background: var(--navy);
}

.secondary:hover {
    background: var(--navy-dark);
}

.light-button {
    background: var(--blue-light);
    color: var(--navy);
}

.light-button:hover {
    background: #dceaf5;
}

.notice {
    padding: 13px 16px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 14px;
}

.error {
    background: var(--red-light);
    color: var(--red);
    border: 1px solid #ffd1cb;
}

.section-title {
    color: var(--navy-dark);
    font-size: 20px;
    margin: 28px 0 15px;
}

.cards {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.card {
    padding: 20px;
    position: relative;
    overflow: hidden;
}

.card:before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--green);
}

.card.blue:before {
    background: var(--navy);
}

.card.warning:before {
    background: #d79a13;
}

.card.danger:before {
    background: #d64545;
}

.card-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .35px;
    margin-bottom: 9px;
}

.card-value {
    font-size: 31px;
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -.5px;
    color: var(--navy-dark);
    overflow-wrap: anywhere;
}

.card-note {
    color: var(--muted);
    font-size: 12px;
    margin-top: 9px;
}

.report-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px;
}

.report-grid .panel {
    min-width: 0;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

th, td {
    padding: 12px 11px;
    text-align: left;
    border-bottom: 1px solid #e8edf2;
    vertical-align: middle;
}

th {
    color: #42566d;
    background: #f0f5f8;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .35px;
    white-space: nowrap;
}

tbody tr:hover {
    background: #f8fbfd;
}

tbody tr:last-child td {
    border-bottom: 0;
}

.empty {
    text-align: center;
    padding: 22px 12px;
    color: var(--muted);
}

.status {
    display: inline-block;
    border-radius: 20px;
    padding: 5px 9px;
    font-size: 11px;
    font-weight: bold;
    background: var(--amber-light);
    color: var(--amber);
    white-space: nowrap;
}

.status.danger {
    background: var(--red-light);
    color: var(--red);
}

.status.good {
    background: var(--green-light);
    color: var(--green);
}

.status.blue {
    background: var(--blue-light);
    color: var(--navy);
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.report-footnote {
    margin: 8px 0 0;
    color: var(--muted);
    font-size: 12px;
}

footer {
    margin-top: 35px;
    padding: 24px 15px;
    text-align: center;
    color: var(--muted);
    font-size: 12px;
    border-top: 1px solid var(--border);
    background: white;
}

footer strong {
    color: var(--navy);
}

@media (max-width: 1050px) {
    .cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 760px) {
    .report-grid {
        grid-template-columns: 1fr;
    }

    .container {
        width: 94%;
        margin-top: 20px;
    }

    .panel {
        padding: 17px;
    }

    .header-inner {
        align-items: flex-start;
    }

    .user-info {
        text-align: left;
    }

    nav {
        padding: 0 3%;
    }

    nav a {
        padding: 12px 8px;
    }
}

@media (max-width: 480px) {
    .cards {
        grid-template-columns: 1fr;
    }

    header {
        padding: 20px 4%;
    }

    header h1 {
        font-size: 20px;
    }

    .logo-mark {
        width: 46px;
        height: 46px;
        font-size: 17px;
    }

    .filters div {
        flex-basis: 100%;
        min-width: 0;
    }

    .filters button, .filters .button {
        width: 100%;
    }

    .card-value {
        font-size: 28px;
    }
}

@media print {
    @page {
        size: landscape;
        margin: 12mm;
    }

    body {
        background: white;
        color: #222;
        font-size: 10px;
    }

    header {
        padding: 12px;
        background: white !important;
        color: #174a70 !important;
        border-bottom: 2px solid #087f5b;
    }

    header p, .user-info {
        color: #444 !important;
    }

    nav, .filters, .no-print, footer {
        display: none !important;
    }

    .container {
        width: 100%;
        max-width: none;
        margin: 12px 0;
    }

    .panel, .card {
        box-shadow: none;
        break-inside: avoid;
        border: 1px solid #ccc;
    }

    .cards {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
    }

    .card {
        padding: 12px;
    }

    .card-value {
        font-size: 20px;
    }

    .report-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .panel {
        padding: 12px;
        margin-bottom: 12px;
    }

    th, td {
        padding: 7px;
    }

    table {
        font-size: 10px;
    }

    .section-title {
        margin-top: 15px;
    }
}
</style>
</head>

<body>

<header>
    <div class="header-inner">
        <div class="brand">
            <div class="logo-mark" aria-label="AZU">AZU</div>
            <div>
                <h1>Africa Zeal University Clinic</h1>
                <p>Patient Information Management System</p>
            </div>
        </div>

        <div class="user-info">
            Signed in as
            <strong><?= e($userName) ?></strong>
            <?= e(ucfirst($role)) ?>
        </div>
    </div>
</header>

<nav>
    <div class="nav-inner">
        <a href="dashboard.php">Dashboard</a>
        <a href="patients.php">Patients</a>
        <a href="appointments.php">Appointments</a>
        <a href="medical_records.php">Medical Records</a>
        <a href="medicine.php">Medicine Inventory</a>
        <a href="dispense.php">Dispensing</a>
        <a href="reports.php" class="active">Reports &amp; Analytics</a>
    </div>
</nav>

<main class="container">

    <div class="page-heading">
        <div>
            <h2>Reports &amp; Analytics</h2>
            <p>Monitor clinic activity, patient visits, appointments and medicine inventory.</p>
        </div>

        <div class="period-badge">
            Report period: <?= e($from) ?> to <?= e($to) ?>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-heading">
            <h2>Generate a Report</h2>
            <p>Select the reporting period. Inventory alerts and registered-patient totals show current database figures.</p>
        </div>

        <form method="get" action="reports.php" class="filters">
            <div>
                <label for="from">Start Date</label>
                <input
                    type="date"
                    id="from"
                    name="from"
                    value="<?= e($from) ?>"
                    max="<?= e($today) ?>"
                    required
                >
            </div>

            <div>
                <label for="to">End Date</label>
                <input
                    type="date"
                    id="to"
                    name="to"
                    value="<?= e($to) ?>"
                    max="<?= e($today) ?>"
                    required
                >
            </div>

            <button type="submit">Generate Report</button>

            <a class="button secondary" href="reports.php">This Month</a>

            <button
                type="button"
                class="light-button no-print"
                onclick="window.print()"
            >Print Report</button>
        </form>
    </section>

    <h2 class="section-title">Clinic Overview</h2>

    <div class="cards">
        <div class="card">
            <div class="card-label">Registered Patients</div>
            <div class="card-value"><?= number_format($totalPatients) ?></div>
            <div class="card-note">All-time total</div>
        </div>

        <div class="card blue">
            <div class="card-label">Appointments</div>
            <div class="card-value"><?= number_format($appointmentCount) ?></div>
            <div class="card-note">Within selected period</div>
        </div>

        <div class="card">
            <div class="card-label">Clinical Visits</div>
            <div class="card-value"><?= number_format($visitCount) ?></div>
            <div class="card-note">Within selected period</div>
        </div>

        <div class="card blue">
            <div class="card-label">Medicine Types</div>
            <div class="card-value"><?= number_format($totalMedicines) ?></div>
            <div class="card-note">All-time inventory count</div>
        </div>

        <div class="card">
            <div class="card-label">Dispensing Records</div>
            <div class="card-value"><?= number_format($dispensingCount) ?></div>
            <div class="card-note">Within selected period</div>
        </div>

        <div class="card blue">
            <div class="card-label">Units Dispensed</div>
            <div class="card-value"><?= number_format($unitsDispensed) ?></div>
            <div class="card-note">Within selected period</div>
        </div>

        <div class="card warning">
            <div class="card-label">Low-Stock Medicines</div>
            <div class="card-value"><?= number_format($lowStock) ?></div>
            <div class="card-note">At or below reorder level</div>
        </div>

        <div class="card danger">
            <div class="card-label">Expired Medicines</div>
            <div class="card-value"><?= number_format($expiredMedicines) ?></div>
            <div class="card-note">Require review</div>
        </div>
    </div>

    <div class="report-grid">
        <section class="panel">
            <div class="panel-heading">
                <h2>Appointment Status Summary</h2>
                <p>Appointment totals grouped by status.</p>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$appointmentStatuses): ?>
                        <tr>
                            <td colspan="2" class="empty">No appointments for this period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($appointmentStatuses as $row): ?>
                            <tr>
                                <td><?= e($row['status'] ? $row['status'] : 'Unspecified') ?></td>
                                <td><strong><?= number_format((int)$row['total']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <h2>Clinical Visits by Date</h2>
                <p>Daily patient visits recorded by clinical staff.</p>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Visit Date</th>
                            <th>Number of Visits</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$dailyVisits): ?>
                        <tr>
                            <td colspan="2" class="empty">No clinical visits for this period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dailyVisits as $row): ?>
                            <tr>
                                <td><?= e($row['visit_date']) ?></td>
                                <td><strong><?= number_format((int)$row['total']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="report-footnote">Up to 31 dates are displayed.</p>
        </section>
    </div>

    <section class="panel">
        <div class="panel-heading">
            <h2>Appointment Details</h2>
            <p>
                Appointments between <?= e($from) ?> and <?= e($to) ?>.
                Up to 200 records are shown.
            </p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Patient Number</th>
                        <th>Patient Name</th>
                        <th>Doctor</th>
                        <th>Reason</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$appointments): ?>
                    <tr>
                        <td colspan="7" class="empty">No appointments found for this period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($appointments as $row): ?>
                        <tr>
                            <td><?= e($row['appointment_date']) ?></td>
                            <td><?= e($row['appointment_time']) ?></td>
                            <td><?= e($row['patient_number']) ?></td>
                            <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td><?= e($row['doctor_name']) ?></td>
                            <td><?= e($row['reason']) ?></td>
                            <td>
                                <span class="status blue"><?= e($row['status']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="report-grid">
        <section class="panel">
            <div class="panel-heading">
                <h2>Most Dispensed Medicines</h2>
                <p>Top medicines by quantity dispensed during the selected period.</p>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Quantity Dispensed</th>
                            <th>Records</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$topMedicines): ?>
                        <tr>
                            <td colspan="3" class="empty">No dispensing records for this period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($topMedicines as $row): ?>
                            <tr>
                                <td><?= e($row['medicine_name']) ?></td>
                                <td>
                                    <strong><?= number_format((int)$row['total_quantity']) ?></strong>
                                    <?= e($row['unit']) ?>
                                </td>
                                <td><?= number_format((int)$row['dispensing_records']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <h2>Low-Stock Alerts</h2>
                <p>Medicines that have reached or fallen below their reorder level.</p>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Current Stock</th>
                            <th>Reorder Level</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$lowStockMedicines): ?>
                        <tr>
                            <td colspan="3" class="empty">No low-stock medicines.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($lowStockMedicines as $row): ?>
                            <tr>
                                <td><?= e($row['medicine_name']) ?></td>
                                <td>
                                    <span class="status danger">
                                        <?= (int)$row['quantity_in_stock'] ?>
                                        <?= e($row['unit']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= (int)$row['reorder_level'] ?>
                                    <?= e($row['unit']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-heading">
            <h2>Expired and Soon-to-Expire Medicines</h2>
            <p>Medicines that have expired or are due to expire within the next 90 days.</p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Stock Remaining</th>
                        <th>Expiry Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$expiryMedicines): ?>
                    <tr>
                        <td colspan="4" class="empty">No medicines in this expiry range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expiryMedicines as $row): ?>
                        <?php
                        $isExpired = $row['expiry_date'] < $today;
                        ?>
                        <tr>
                            <td><?= e($row['medicine_name']) ?></td>
                            <td>
                                <?= (int)$row['quantity_in_stock'] ?>
                                <?= e($row['unit']) ?>
                            </td>
                            <td><?= e($row['expiry_date']) ?></td>
                            <td>
                                <?php if ($isExpired): ?>
                                    <span class="status danger">Expired</span>
                                <?php else: ?>
                                    <span class="status">Expiring Soon</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="muted">
        Report period: <?= e($from) ?> to <?= e($to) ?>.
        Registered-patient totals and inventory alerts reflect the current database.
        Appointment details are limited to 200 records and daily visit results to 31 dates.
    </p>

    <div class="actions no-print" style="margin-top:20px;">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <a href="dashboard.php" class="button secondary">Back to Dashboard</a>
    </div>

</main>

<footer>
    <strong>Africa Zeal University Clinic</strong><br>
    AZU PIMS &mdash; Reports &amp; Analytics
</footer>

</body>
</html>
