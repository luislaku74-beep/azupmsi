<?php
session_start();
require_once __DIR__ . '/db.php';

// --------------------------------------------------
// 1. SESSION AND ROLE PROTECTION
// --------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
$staffName = $_SESSION['full_name'] ?? 'Staff Member';

$allowedRoles = [
    'admin',
    'receptionist',
    'clinician',
    'doctor',
    'nurse'
];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. Your account cannot access appointments.');
}

// --------------------------------------------------
// 2. CSRF TOKEN
// --------------------------------------------------
if (empty($_SESSION['appointments_csrf_token'])) {
    $_SESSION['appointments_csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$allowedStatuses = ['Scheduled', 'Completed', 'Cancelled', 'No Show'];

// --------------------------------------------------
// 3. CREATE APPOINTMENTS AND UPDATE STATUS
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (
        !hash_equals(
            $_SESSION['appointments_csrf_token'],
            $token
        )
    ) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $patientId = filter_var(
                $_POST['patient_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $appointmentDate = trim(
                (string) ($_POST['appointment_date'] ?? '')
            );

            $appointmentTime = trim(
                (string) ($_POST['appointment_time'] ?? '')
            );

            $doctorName = trim(
                (string) ($_POST['doctor_name'] ?? '')
            );

            $reason = trim(
                (string) ($_POST['reason'] ?? '')
            );

            $validDate = DateTime::createFromFormat(
                '!Y-m-d',
                $appointmentDate
            );

            $dateErrors = DateTime::getLastErrors();

            $dateIsValid = $validDate !== false &&
                ($dateErrors === false ||
                 ($dateErrors['warning_count'] === 0 &&
                  $dateErrors['error_count'] === 0)) &&
                $validDate->format('Y-m-d') === $appointmentDate;

            $validTime = DateTime::createFromFormat(
                '!H:i',
                $appointmentTime
            );

            $timeErrors = DateTime::getLastErrors();

            $timeIsValid = $validTime !== false &&
                ($timeErrors === false ||
                 ($timeErrors['warning_count'] === 0 &&
                  $timeErrors['error_count'] === 0)) &&
                $validTime->format('H:i') === $appointmentTime;

            if (
                !$patientId ||
                !$dateIsValid ||
                !$timeIsValid ||
                $doctorName === '' ||
                strlen($doctorName) > 150 ||
                strlen($reason) > 255
            ) {
                $error = 'Please enter a valid patient, date, time, clinician, and reason.';
            } elseif ($appointmentDate < date('Y-m-d')) {
                $error = 'Appointment date cannot be in the past.';
            } else {
                try {
                    $check = $pdo->prepare(
                        'SELECT id FROM patients WHERE id = :id LIMIT 1'
                    );

                    $check->execute(['id' => $patientId]);

                    if (!$check->fetch()) {
                        $error = 'The selected patient does not exist.';
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO appointments
                                (patient_id, appointment_date,
                                 appointment_time, doctor_name, reason)
                             VALUES
                                (:patient_id, :appointment_date,
                                 :appointment_time, :doctor_name, :reason)'
                        );

                        $stmt->execute([
                            'patient_id' => $patientId,
                            'appointment_date' => $appointmentDate,
                            'appointment_time' => $appointmentTime . ':00',
                            'doctor_name' => $doctorName,
                            'reason' => $reason !== '' ? $reason : null
                        ]);

                        $success = 'Appointment scheduled successfully.';
                        $_SESSION['appointments_csrf_token'] =
                            bin2hex(random_bytes(32));
                    }
                } catch (PDOException $e) {
                    error_log('Appointment creation error: ' . $e->getMessage());
                    $error = 'Could not schedule the appointment. Please check the database configuration.';
                }
            }

        } elseif ($action === 'status') {
            // Only authorized appointment staff reach this point.
            $appointmentId = filter_var(
                $_POST['appointment_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $status = (string) ($_POST['status'] ?? '');

            if (
                !$appointmentId ||
                !in_array($status, $allowedStatuses, true)
            ) {
                $error = 'Invalid appointment ID or status.';
            } else {
                try {
                    $check = $pdo->prepare(
                        'SELECT id FROM appointments WHERE id = :id LIMIT 1'
                    );
                    $check->execute(['id' => $appointmentId]);

                    if (!$check->fetch()) {
                        $error = 'Appointment not found.';
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE appointments
                             SET status = :status
                             WHERE id = :id'
                        );

                        $stmt->execute([
                            'status' => $status,
                            'id' => $appointmentId
                        ]);

                        $success = 'Appointment status updated.';
                        $_SESSION['appointments_csrf_token'] =
                            bin2hex(random_bytes(32));
                    }
                } catch (PDOException $e) {
                    error_log('Appointment status error: ' . $e->getMessage());
                    $error = 'Could not update appointment status.';
                }
            }
        } else {
            $error = 'Invalid request.';
        }
    }
}

// --------------------------------------------------
// 4. LOAD PATIENT OPTIONS
// --------------------------------------------------
try {
    $patientStmt = $pdo->query(
        'SELECT id, patient_number, first_name, last_name
         FROM patients
         ORDER BY first_name, last_name'
    );

    $patientOptions = $patientStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Patient options error: ' . $e->getMessage());
    $patientOptions = [];
    $error = $error ?: 'Unable to load the patient list.';
}

// --------------------------------------------------
// 5. SEARCH APPOINTMENTS
// --------------------------------------------------
$search = trim((string) ($_GET['search'] ?? ''));

try {
    $sql = 'SELECT a.id, a.appointment_date, a.appointment_time,
                   a.doctor_name, a.reason, a.status,
                   p.patient_number, p.first_name, p.last_name
            FROM appointments a
            INNER JOIN patients p ON p.id = a.patient_id';

    if ($search !== '') {
        $sql .= ' WHERE LOWER(p.patient_number) LIKE LOWER(:s1)
                    OR LOWER(p.first_name) LIKE LOWER(:s2)
                    OR LOWER(p.last_name) LIKE LOWER(:s3)
                    OR LOWER(a.doctor_name) LIKE LOWER(:s4)';
    }

    $sql .= ' ORDER BY a.appointment_date DESC,
                        a.appointment_time DESC
              LIMIT 200';

    $stmt = $pdo->prepare($sql);

    if ($search !== '') {
        $term = '%' . $search . '%';

        $stmt->execute([
            's1' => $term,
            's2' => $term,
            's3' => $term,
            's4' => $term
        ]);
    } else {
        $stmt->execute();
    }

    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalAppointments = (int) $pdo->query(
        'SELECT COUNT(*) FROM appointments'
    )->fetchColumn();

    $scheduledCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM appointments WHERE status = 'Scheduled'"
    )->fetchColumn();

    $completedCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM appointments WHERE status = 'Completed'"
    )->fetchColumn();

} catch (PDOException $e) {
    error_log('Appointment listing error: ' . $e->getMessage());
    $appointments = [];
    $totalAppointments = 0;
    $scheduledCount = 0;
    $completedCount = 0;
    $error = $error ?: 'Unable to load appointments. Please check the database.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Appointments | AZU Clinic PIMS</title>

<style>
:root {
    --navy: #202b83;
    --navy-dark: #17205f;
    --orange: #ed791c;
    --orange-light: #fff1e5;
    --background: #f4f6fb;
    --white: #fff;
    --text: #263247;
    --muted: #738096;
    --border: #e3e8f1;
    --green: #167647;
    --red: #b42318;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family: Arial, Helvetica, sans-serif;
}

a { text-decoration: none; }

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    padding: 17px 5%;
    background: var(--white);
    border-bottom: 4px solid var(--orange);
}

.brand {
    color: var(--navy);
    font-size: 21px;
    font-weight: 800;
}

.brand span {
    display: block;
    margin-top: 5px;
    color: var(--muted);
    font-size: 12px;
    font-weight: normal;
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 17px;
    flex-wrap: wrap;
}

.nav-links a {
    color: var(--navy);
    font-size: 13px;
    font-weight: bold;
}

.nav-links .signout { color: var(--red); }

.container {
    max-width: 1300px;
    margin: 30px auto;
    padding: 0 22px 45px;
}

.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.page-heading h1 {
    margin: 0;
    color: var(--navy);
    font-size: 29px;
}

.page-heading p {
    margin: 8px 0 0;
    color: var(--muted);
    font-size: 14px;
    line-height: 1.6;
}

.staff-label {
    padding: 9px 13px;
    border-radius: 20px;
    background: var(--orange-light);
    color: #a84e0c;
    font-size: 12px;
    font-weight: bold;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}

.stat-card {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 21px;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 4px 18px #202b830a;
}

.stat-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    height: 50px;
    flex-shrink: 0;
    border-radius: 12px;
    background: #edf0ff;
    font-size: 24px;
}

.stat-card:nth-child(2) .stat-icon {
    background: var(--orange-light);
}

.stat-card:nth-child(3) .stat-icon {
    background: #e7f7ef;
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
    font-size: 25px;
}

.panel {
    margin-bottom: 24px;
    padding: 26px;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 4px 18px #202b830a;
}

.panel-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 22px;
}

.panel-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 43px;
    height: 43px;
    border-radius: 10px;
    background: var(--orange-light);
    font-size: 22px;
}

.panel-heading h2 {
    margin: 0;
    color: var(--navy);
    font-size: 20px;
}

.panel-heading p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 12px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.field label, .search-field label {
    display: block;
    margin-bottom: 7px;
    color: var(--navy);
    font-size: 13px;
    font-weight: bold;
}

.field input, .field select, .field textarea,
.search-input, .status-form select {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #d7deeb;
    border-radius: 7px;
    background: white;
    color: var(--text);
    font-size: 14px;
}

.field input:focus, .field select:focus,
.search-input:focus, .status-form select:focus {
    outline: 2px solid #dce1ff;
    border-color: var(--navy);
}

.button {
    display: inline-block;
    border: none;
    border-radius: 7px;
    padding: 11px 15px;
    background: var(--navy);
    color: white;
    font-size: 12px;
    font-weight: bold;
    cursor: pointer;
    transition: background .2s, transform .2s;
    white-space: nowrap;
}

.button:hover {
    background: var(--orange);
    transform: translateY(-1px);
}

.button-orange { background: var(--orange); }

.button-light {
    background: #edf0ff;
    color: var(--navy);
}

.button-light:hover {
    background: #dfe4ff;
    color: var(--navy);
}

.form-actions { margin-top: 22px; }

.message {
    margin-bottom: 20px;
    padding: 14px 16px;
    border-radius: 8px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.success {
    background: #e7f7ef;
    border-left: 4px solid var(--green);
    color: #17633c;
}

.error {
    background: #fff0ef;
    border-left: 4px solid var(--red);
    color: #a52820;
}

.search-form {
    display: flex;
    align-items: end;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.search-field {
    flex: 1;
    min-width: 220px;
}

.search-input { display: block; }

.table-wrap {
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 9px;
}

table {
    width: 100%;
    min-width: 1100px;
    border-collapse: collapse;
}

th, td {
    padding: 13px 12px;
    text-align: left;
    vertical-align: middle;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
}

th {
    background: #f7f8fc;
    color: var(--navy);
    font-size: 11px;
    white-space: nowrap;
}

tbody tr:hover { background: #fafbff; }
tbody tr:last-child td { border-bottom: none; }

.patient-number {
    color: var(--navy);
    font-weight: bold;
    white-space: nowrap;
}

.patient-name { font-weight: bold; }

.status-badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 20px;
    background: #edf0ff;
    color: var(--navy);
    font-size: 11px;
    font-weight: bold;
    white-space: nowrap;
}

.status-completed {
    background: #e7f7ef;
    color: #17633c;
}

.status-cancelled, .status-no-show {
    background: #fff0ef;
    color: #a52820;
}

.status-form {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 205px;
}

.status-form select {
    min-width: 115px;
    padding: 9px 7px;
    font-size: 12px;
}

.status-form .button { padding: 10px; }

.empty-state {
    padding: 32px 15px;
    text-align: center;
    color: var(--muted);
}

.table-note {
    margin-top: 12px;
    color: var(--muted);
    font-size: 12px;
}

.footer {
    margin-top: 35px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
    color: var(--muted);
    text-align: center;
    font-size: 12px;
    line-height: 1.8;
}

.footer strong { color: var(--navy); }

@media (max-width: 800px) {
    .stats { grid-template-columns: 1fr; }
}

@media (max-width: 650px) {
    .topbar { padding: 16px 20px; }
    .container { padding: 0 14px 30px; }
    .page-heading h1 { font-size: 25px; }
    .form-grid { grid-template-columns: 1fr; }
    .panel { padding: 18px; }
    .search-form .button { flex: 1; text-align: center; }
}
</style>
</head>

<body>

<header class="topbar">
    <div class="brand">
        AZU Clinic PIMS
        <span>Patient Information Management System</span>
    </div>

    <nav class="nav-links">
        <a href="dashboard.php">← Dashboard</a>
        <a href="patients.php">Patients</a>
        <a class="signout" href="logout.php">Sign Out</a>
    </nav>
</header>

<main class="container">

    <section class="page-heading">
        <div>
            <h1>Appointment Management</h1>
            <p>Schedule clinic visits, review appointments, and update their status.</p>
        </div>
        <div class="staff-label">
            Signed in: <?= h($staffName) ?>
        </div>
    </section>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon">📅</div>
            <div>
                <span>Total appointments</span>
                <strong><?= number_format($totalAppointments) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div>
                <span>Scheduled</span>
                <strong><?= number_format($scheduledCount) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">✓</div>
            <div>
                <span>Completed</span>
                <strong><?= number_format($completedCount) ?></strong>
            </div>
        </div>
    </section>

    <?php if ($success !== ''): ?>
        <div class="message success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-heading">
            <div class="panel-icon">＋</div>
            <div>
                <h2>Schedule an Appointment</h2>
                <p>Choose a registered patient and provide the appointment details.</p>
            </div>
        </div>

        <?php if (!$patientOptions): ?>
            <p>No patients are registered yet.
                <a href="patients.php">Register a patient first</a>.
            </p>
        <?php else: ?>
            <form method="post" action="appointments.php">
                <input type="hidden" name="csrf_token"
                       value="<?= h($_SESSION['appointments_csrf_token']) ?>">
                <input type="hidden" name="action" value="create">

                <div class="form-grid">
                    <div class="field">
                        <label for="patient_id">Patient *</label>
                        <select id="patient_id" name="patient_id" required>
                            <option value="">Select patient</option>
                            <?php foreach ($patientOptions as $p): ?>
                                <option value="<?= (int) $p['id'] ?>">
                                    <?= h(
                                        $p['patient_number'] . ' — ' .
                                        $p['first_name'] . ' ' . $p['last_name']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="appointment_date">Appointment date *</label>
                        <input type="date" id="appointment_date"
                               name="appointment_date"
                               min="<?= h(date('Y-m-d')) ?>" required>
                    </div>

                    <div class="field">
                        <label for="appointment_time">Appointment time *</label>
                        <input type="time" id="appointment_time"
                               name="appointment_time" required>
                    </div>

                    <div class="field">
                        <label for="doctor_name">Doctor or clinician *</label>
                        <input id="doctor_name" name="doctor_name"
                               maxlength="150" required>
                    </div>

                    <div class="field">
                        <label for="reason">Reason for appointment</label>
                        <input id="reason" name="reason" maxlength="255">
                    </div>
                </div>

                <div class="form-actions">
                    <button class="button" type="submit">
                        Schedule Appointment →
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div class="panel-icon">🔎</div>
            <div>
                <h2>Appointment Records</h2>
                <p>Search appointments and update their current status.</p>
            </div>
        </div>

        <form class="search-form" method="get" action="appointments.php">
            <div class="search-field">
                <label for="search">Patient or clinician</label>
                <input class="search-input" id="search" name="search"
                       value="<?= h($search) ?>"
                       placeholder="Patient name, patient number, or doctor">
            </div>

            <button class="button button-orange" type="submit">Search</button>
            <a class="button button-light" href="appointments.php">Clear</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Patient number</th>
                        <th>Patient name</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Doctor / Clinician</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Update status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$appointments): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                No matching appointments found.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($appointments as $a): ?>
                        <?php
                        $statusClass = '';
                        if ($a['status'] === 'Completed') {
                            $statusClass = 'status-completed';
                        } elseif ($a['status'] === 'Cancelled') {
                            $statusClass = 'status-cancelled';
                        } elseif ($a['status'] === 'No Show') {
                            $statusClass = 'status-no-show';
                        }
                        ?>
                        <tr>
                            <td class="patient-number">
                                <?= h($a['patient_number']) ?>
                            </td>
                            <td class="patient-name">
                                <?= h($a['first_name'] . ' ' . $a['last_name']) ?>
                            </td>
                            <td><?= h($a['appointment_date']) ?></td>
                            <td><?= h(substr((string) $a['appointment_time'], 0, 5)) ?></td>
                            <td><?= h($a['doctor_name']) ?></td>
                            <td><?= h($a['reason'] ?? '') ?></td>
                            <td>
                                <span class="status-badge <?= h($statusClass) ?>">
                                    <?= h($a['status'] ?? 'Scheduled') ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" action="appointments.php"
                                      class="status-form">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= h($_SESSION['appointments_csrf_token']) ?>">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="appointment_id"
                                           value="<?= (int) $a['id'] ?>">

                                    <select name="status"
                                            aria-label="Appointment status">
                                        <?php foreach ($allowedStatuses as $s): ?>
                                            <option value="<?= h($s) ?>"
                                                <?= ($a['status'] ?? 'Scheduled') === $s
                                                    ? 'selected' : '' ?>>
                                                <?= h($s) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button class="button" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="table-note">
            Up to 200 matching appointments are displayed.
            Sign out when you finish using the clinic system.
        </p>
    </section>

    <footer class="footer">
        <strong>Africa Zeal University Clinic</strong><br>
        Patient Information Management System · Authorized staff only
    </footer>

</main>
</body>
</html>
