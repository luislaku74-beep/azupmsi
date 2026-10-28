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

$allowedRoles = ['admin', 'doctor', 'nurse', 'clinician'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. Your account cannot access medical records.');
}

// --------------------------------------------------
// 2. CSRF TOKEN
// --------------------------------------------------
if (empty($_SESSION['medical_records_csrf_token'])) {
    $_SESSION['medical_records_csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function validDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    $errors = DateTime::getLastErrors();

    return $parsed !== false
        && ($errors === false ||
            ($errors['warning_count'] === 0 &&
             $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

// --------------------------------------------------
// 3. SAVE MEDICAL RECORD
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['medical_records_csrf_token'], $token)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $patientId = filter_var(
            $_POST['patient_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $visitDate = trim((string) ($_POST['visit_date'] ?? ''));
        $symptoms = trim((string) ($_POST['symptoms'] ?? ''));
        $diagnosis = trim((string) ($_POST['diagnosis'] ?? ''));
        $treatment = trim((string) ($_POST['treatment'] ?? ''));
        $prescription = trim(
            (string) ($_POST['prescription_notes'] ?? '')
        );
        $followUp = trim((string) ($_POST['follow_up_date'] ?? ''));

        if (
            !$patientId ||
            !validDate($visitDate) ||
            $visitDate > date('Y-m-d') ||
            $diagnosis === ''
        ) {
            $error = 'Select a patient, enter a valid visit date, and provide a diagnosis.';
        } elseif (
            strlen($symptoms) > 10000 ||
            strlen($diagnosis) > 10000 ||
            strlen($treatment) > 10000 ||
            strlen($prescription) > 10000
        ) {
            $error = 'Each medical-record text field must be no longer than 10,000 characters.';
        } elseif (
            $followUp !== '' &&
            (
                !validDate($followUp) ||
                $followUp < $visitDate
            )
        ) {
            $error = 'Enter a valid follow-up date on or after the visit date.';
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
                        'INSERT INTO medical_records
                        (
                            patient_id,
                            visit_date,
                            symptoms,
                            diagnosis,
                            treatment,
                            prescription_notes,
                            follow_up_date
                        )
                        VALUES
                        (
                            :patient_id,
                            :visit_date,
                            :symptoms,
                            :diagnosis,
                            :treatment,
                            :prescription,
                            :follow_up
                        )'
                    );

                    $stmt->execute([
                        'patient_id' => $patientId,
                        'visit_date' => $visitDate,
                        'symptoms' => $symptoms !== '' ? $symptoms : null,
                        'diagnosis' => $diagnosis,
                        'treatment' => $treatment !== '' ? $treatment : null,
                        'prescription' => $prescription !== '' ? $prescription : null,
                        'follow_up' => $followUp !== '' ? $followUp : null
                    ]);

                    $success = 'Medical record saved successfully.';

                    // Rotate token after successful submission.
                    $_SESSION['medical_records_csrf_token'] =
                        bin2hex(random_bytes(32));
                }
            } catch (PDOException $e) {
                error_log('Medical record save error: ' . $e->getMessage());
                $error = 'Could not save the medical record. Please check the database configuration.';
            }
        }
    }
}

// --------------------------------------------------
// 4. LOAD REGISTERED PATIENTS
// --------------------------------------------------
try {
    $stmt = $pdo->query(
        'SELECT id, patient_number, first_name, last_name
         FROM patients
         ORDER BY first_name, last_name'
    );

    $patientOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Medical records patient list error: ' . $e->getMessage());
    $patientOptions = [];
    $error = $error ?: 'Unable to load the patient list.';
}

// --------------------------------------------------
// 5. SEARCH MEDICAL RECORDS
// --------------------------------------------------
$search = trim((string) ($_GET['search'] ?? ''));

$records = [];
$totalRecords = 0;
$followUpCount = 0;

try {
    $sql = 'SELECT
                mr.id,
                mr.visit_date,
                mr.symptoms,
                mr.diagnosis,
                mr.treatment,
                mr.prescription_notes,
                mr.follow_up_date,
                mr.created_at,
                p.patient_number,
                p.first_name,
                p.last_name
            FROM medical_records mr
            INNER JOIN patients p ON p.id = mr.patient_id';

    if ($search !== '') {
        $sql .= ' WHERE LOWER(p.patient_number) LIKE LOWER(:s1)
                    OR LOWER(p.first_name) LIKE LOWER(:s2)
                    OR LOWER(p.last_name) LIKE LOWER(:s3)
                    OR LOWER(mr.diagnosis) LIKE LOWER(:s4)';
    }

    $sql .= ' ORDER BY mr.visit_date DESC, mr.id DESC LIMIT 200';

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

    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRecords = (int) $pdo->query(
        'SELECT COUNT(*) FROM medical_records'
    )->fetchColumn();

    $followUpStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM medical_records
         WHERE follow_up_date >= :today'
    );
    $followUpStmt->execute(['today' => date('Y-m-d')]);
    $followUpCount = (int) $followUpStmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Medical records search error: ' . $e->getMessage());
    $error = $error ?: 'Unable to load medical records. Please check the database.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Medical Records | AZU Clinic PIMS</title>

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
    --border: #e3e8f1;
    --green: #17633c;
    --red: #a52820;
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
    max-width: 1350px;
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

.field input, .field select, .field textarea, .search-input {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #d7deeb;
    border-radius: 7px;
    background: var(--white);
    color: var(--text);
    font: inherit;
    font-size: 14px;
}

.field textarea {
    min-height: 115px;
    resize: vertical;
    line-height: 1.5;
}

.field input:focus,
.field select:focus,
.field textarea:focus,
.search-input:focus {
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
    color: var(--green);
}

.error {
    background: #fff0ef;
    border-left: 4px solid var(--red);
    color: var(--red);
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

.table-wrap {
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 9px;
}

table {
    width: 100%;
    min-width: 1150px;
    border-collapse: collapse;
}

th, td {
    padding: 13px 12px;
    text-align: left;
    vertical-align: top;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
    line-height: 1.6;
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

.diagnosis {
    min-width: 160px;
    font-weight: bold;
    color: var(--navy);
}

.record-text {
    min-width: 130px;
    max-width: 250px;
    white-space: normal;
    overflow-wrap: anywhere;
}

.date-badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 6px;
    background: var(--orange-light);
    color: #a84e0c;
    font-weight: bold;
    white-space: nowrap;
}

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
        <a href="appointments.php">Appointments</a>
        <a class="signout" href="logout.php">Sign Out</a>
    </nav>
</header>

<main class="container">

    <section class="page-heading">
        <div>
            <h1>Medical Records</h1>
            <p>Document patient visits, diagnoses, treatments, prescriptions, and follow-up plans.</p>
        </div>

        <div class="staff-label">
            Signed in: <?= h($staffName) ?>
        </div>
    </section>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon">📋</div>
            <div>
                <span>Total medical records</span>
                <strong><?= number_format($totalRecords) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div>
                <span>Registered patients</span>
                <strong><?= number_format(count($patientOptions)) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📅</div>
            <div>
                <span>Upcoming follow-ups</span>
                <strong><?= number_format($followUpCount) ?></strong>
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
                <h2>Record Patient Visit</h2>
                <p>Complete the clinical information for this encounter.</p>
            </div>
        </div>

        <?php if (!$patientOptions): ?>
            <p>No patients are registered yet.
                <a href="patients.php">Register a patient first</a>.
            </p>
        <?php else: ?>
            <form method="post" action="medical_records.php">
                <input type="hidden" name="csrf_token"
                       value="<?= h($_SESSION['medical_records_csrf_token']) ?>">

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
                        <label for="visit_date">Visit date *</label>
                        <input type="date" id="visit_date" name="visit_date"
                               value="<?= h(date('Y-m-d')) ?>"
                               max="<?= h(date('Y-m-d')) ?>" required>
                    </div>

                    <div class="field">
                        <label for="diagnosis">Diagnosis *</label>
                        <textarea id="diagnosis" name="diagnosis"
                                  maxlength="10000" required></textarea>
                    </div>

                    <div class="field">
                        <label for="symptoms">Symptoms</label>
                        <textarea id="symptoms" name="symptoms"
                                  maxlength="10000"></textarea>
                    </div>

                    <div class="field">
                        <label for="treatment">Treatment</label>
                        <textarea id="treatment" name="treatment"
                                  maxlength="10000"></textarea>
                    </div>

                    <div class="field">
                        <label for="prescription_notes">Prescription notes</label>
                        <textarea id="prescription_notes"
                                  name="prescription_notes"
                                  maxlength="10000"></textarea>
                    </div>

                    <div class="field">
                        <label for="follow_up_date">Follow-up date</label>
                        <input type="date" id="follow_up_date"
                               name="follow_up_date">
                    </div>
                </div>

                <div class="form-actions">
                    <button class="button" type="submit">
                        Save Medical Record →
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div class="panel-icon">🔎</div>
            <div>
                <h2>Patient Medical History</h2>
                <p>Search recorded visits by patient details or diagnosis.</p>
            </div>
        </div>

        <form class="search-form" method="get" action="medical_records.php">
            <div class="search-field">
                <label for="search">Patient number, name, or diagnosis</label>
                <input class="search-input" id="search" name="search"
                       value="<?= h($search) ?>"
                       placeholder="Enter patient or diagnosis">
            </div>

            <button class="button button-orange" type="submit">Search</button>
            <a class="button button-light" href="medical_records.php">Clear</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Patient number</th>
                        <th>Patient name</th>
                        <th>Visit date</th>
                        <th>Symptoms</th>
                        <th>Diagnosis</th>
                        <th>Treatment</th>
                        <th>Prescription notes</th>
                        <th>Follow-up</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$records): ?>
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                No matching medical records found.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td class="patient-number">
                                <?= h($record['patient_number']) ?>
                            </td>

                            <td class="patient-name">
                                <?= h(
                                    $record['first_name'] . ' ' .
                                    $record['last_name']
                                ) ?>
                            </td>

                            <td>
                                <span class="date-badge">
                                    <?= h($record['visit_date']) ?>
                                </span>
                            </td>

                            <td class="record-text">
                                <?= nl2br(h($record['symptoms'] ?? '')) ?>
                            </td>

                            <td class="record-text diagnosis">
                                <?= nl2br(h($record['diagnosis'])) ?>
                            </td>

                            <td class="record-text">
                                <?= nl2br(h($record['treatment'] ?? '')) ?>
                            </td>

                            <td class="record-text">
                                <?= nl2br(h($record['prescription_notes'] ?? '')) ?>
                            </td>

                            <td>
                                <?= h($record['follow_up_date'] ?? '') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="table-note">
            Up to 200 matching records are displayed. Medical information
            should only be accessed by authorized clinic personnel.
        </p>
    </section>

    <footer class="footer">
        <strong>Africa Zeal University Clinic</strong><br>
        Patient Information Management System · Authorized staff only
    </footer>

</main>
</body>
</html>
