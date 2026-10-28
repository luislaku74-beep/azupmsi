
<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_helper.php';

// 1. Require login.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 2. Check staff role.
$role = $_SESSION['role'] ?? '';
$allowedRoles = ['admin', 'receptionist', 'clinician'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied. Your account cannot access patient management.');
}

$userId = (int) $_SESSION['user_id'];
$staffName = $_SESSION['full_name'] ?? 'Staff Member';

// 3. CSRF protection for patient registration.
if (empty($_SESSION['patients_csrf_token'])) {
    $_SESSION['patients_csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';
$search = trim((string) ($_GET['search'] ?? ''));

// 4. Register patient.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['patients_csrf_token'], $token)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $gender = (string) ($_POST['gender'] ?? '');
        $dob = trim((string) ($_POST['date_of_birth'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $nextOfKin = trim((string) ($_POST['next_of_kin'] ?? ''));
        $nextOfKinPhone = trim((string) ($_POST['next_of_kin_phone'] ?? ''));

        if ($firstName === '' || $lastName === '' ||
            !in_array($gender, ['Male', 'Female', 'Other'], true)) {
            $error = 'Enter the patient name and select a valid gender.';
        } elseif (
            $dob !== '' &&
            (
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) ||
                !checkdate(
                    (int) substr($dob, 5, 2),
                    (int) substr($dob, 8, 2),
                    (int) substr($dob, 0, 4)
                ) ||
                $dob > date('Y-m-d')
            )
        ) {
            $error = 'Enter a valid date of birth that is not in the future.';
        } elseif (
            strlen($firstName) > 100 ||
            strlen($lastName) > 100 ||
            strlen($phone) > 30 ||
            strlen($address) > 255 ||
            strlen($nextOfKin) > 150 ||
            strlen($nextOfKinPhone) > 30
        ) {
            $error = 'One or more fields exceed the permitted length.';
        } else {
            $patientNumber = 'AZU-' . date('Ymd') . '-' .
                strtoupper(bin2hex(random_bytes(3)));

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO patients (
                        patient_number,
                        first_name,
                        last_name,
                        gender,
                        date_of_birth,
                        phone,
                        address,
                        next_of_kin,
                        next_of_kin_phone,
                        created_by
                    ) VALUES (
                        :number,
                        :first,
                        :last,
                        :gender,
                        :dob,
                        :phone,
                        :address,
                        :kin,
                        :kin_phone,
                        :created_by
                    )'
                );

                $stmt->execute([
                    'number' => $patientNumber,
                    'first' => $firstName,
                    'last' => $lastName,
                    'gender' => $gender,
                    'dob' => $dob !== '' ? $dob : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'address' => $address !== '' ? $address : null,
                    'kin' => $nextOfKin !== '' ? $nextOfKin : null,
                    'kin_phone' => $nextOfKinPhone !== ''
                        ? $nextOfKinPhone : null,
                    'created_by' => $userId
                ]);

                
                $success = 'Patient registered successfully. Patient number: ' .
                    $patientNumber;

                // Record the successful registration without storing patient details.
                recordAudit(
                    $pdo,
                    'patient',
                    'Patient registered',
                    'success',
                    'A new patient record was created.'
                );


                // Prevent duplicate registration on browser refresh.
                $_SESSION['patients_csrf_token'] = bin2hex(random_bytes(32));

            } catch (PDOException $e) {
                error_log('Patient registration error: ' . $e->getMessage());
                $error = 'The patient could not be registered. Check the database configuration.';
            }
        }
    }
}

// 5. Retrieve patient list.
try {
    if ($search !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, patient_number, first_name, last_name,
                    gender, phone, created_at
             FROM patients
             WHERE LOWER(patient_number) LIKE LOWER(:s1)
                OR LOWER(first_name) LIKE LOWER(:s2)
                OR LOWER(last_name) LIKE LOWER(:s3)
             ORDER BY id DESC
             LIMIT 100'
        );

        $term = '%' . $search . '%';
        $stmt->execute([
            's1' => $term,
            's2' => $term,
            's3' => $term
        ]);
    } else {
        $stmt = $pdo->query(
            'SELECT id, patient_number, first_name, last_name,
                    gender, phone, created_at
             FROM patients
             ORDER BY id DESC
             LIMIT 100'
        );
    }

    $patients = $stmt->fetchAll();

    $patientCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM patients'
    )->fetchColumn();

} catch (PDOException $e) {
    error_log('Patient list error: ' . $e->getMessage());
    $patients = [];
    $patientCount = 0;
    $error = $error ?: 'Unable to load patient records. Please try again.';
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Patients | AZU Clinic PIMS</title>

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
    max-width: 1250px;
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
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}

.stat-card {
    display: flex;
    align-items: center;
    gap: 16px;
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
    width: 52px;
    height: 52px;
    flex-shrink: 0;
    border-radius: 12px;
    background: #edf0ff;
    font-size: 25px;
}

.stat-card:nth-child(2) .stat-icon {
    background: var(--orange-light);
}

.stat-card span {
    display: block;
    color: var(--muted);
    font-size: 13px;
}

.stat-card strong {
    display: block;
    margin-top: 6px;
    color: var(--navy);
    font-size: 26px;
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

.field label {
    display: block;
    margin-bottom: 7px;
    color: var(--navy);
    font-size: 13px;
    font-weight: bold;
}

.field input, .field select {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #d7deeb;
    border-radius: 7px;
    background: white;
    color: var(--text);
    font-size: 14px;
}

.field input:focus, .field select:focus,
.search-input:focus {
    outline: 2px solid #dce1ff;
    border-color: var(--navy);
}

.button {
    display: inline-block;
    border: none;
    border-radius: 7px;
    padding: 12px 18px;
    background: var(--navy);
    color: white;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
    transition: background .2s, transform .2s;
}

.button:hover {
    background: var(--orange);
    transform: translateY(-1px);
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

.search-field label {
    display: block;
    margin-bottom: 7px;
    color: var(--navy);
    font-size: 13px;
    font-weight: bold;
}

.search-input {
    width: 100%;
    padding: 12px;
    border: 1px solid #d7deeb;
    border-radius: 7px;
    font-size: 14px;
}

.button-orange { background: var(--orange); }

.button-light {
    background: #edf0ff;
    color: var(--navy);
}

.button-light:hover {
    background: #dfe4ff;
}

.table-wrap {
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 9px;
}

table {
    width: 100%;
    min-width: 760px;
    border-collapse: collapse;
}

th, td {
    padding: 14px 13px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
}

th {
    background: #f7f8fc;
    color: var(--navy);
    font-size: 12px;
    white-space: nowrap;
}

tbody tr:hover { background: #fafbff; }
tbody tr:last-child td { border-bottom: none; }

.patient-number {
    color: var(--navy);
    font-weight: bold;
    white-space: nowrap;
}

.patient-name {
    font-weight: bold;
    color: var(--text);
}

.gender-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #edf0ff;
    color: var(--navy);
    font-size: 11px;
    font-weight: bold;
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

@media (max-width: 650px) {
    .topbar { padding: 16px 20px; }
    .container { padding: 0 14px 30px; }
    .page-heading h1 { font-size: 25px; }
    .stats, .form-grid { grid-template-columns: 1fr; }
    .panel { padding: 18px; }
    .search-form .button { flex: 1; }
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
        <a href="appointments.php">Appointments</a>
        <a class="signout" href="logout.php">Sign Out</a>
    </nav>
</header>

<main class="container">

    <section class="page-heading">
        <div>
            <h1>Patient Management</h1>
            <p>Register patients and securely access clinic patient information.</p>
        </div>
        <div class="staff-label">
            Signed in: <?= h($staffName) ?>
        </div>
    </section>

    <section class="stats">
        <div class="stat-card">
            <div class="stat-icon">🩺</div>
            <div>
                <span>Total registered patients</span>
                <strong><?= number_format($patientCount) ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">🔎</div>
            <div>
                <span>Patients shown below</span>
                <strong><?= number_format(count($patients)) ?></strong>
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
                <h2>Register New Patient</h2>
                <p>Fields marked * are required.</p>
            </div>
        </div>

        <form method="post" action="patients.php">
            <input type="hidden" name="csrf_token"
                   value="<?= h($_SESSION['patients_csrf_token']) ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="first_name">First name *</label>
                    <input id="first_name" name="first_name"
                           maxlength="100" required>
                </div>

                <div class="field">
                    <label for="last_name">Last name *</label>
                    <input id="last_name" name="last_name"
                           maxlength="100" required>
                </div>

                <div class="field">
                    <label for="gender">Gender *</label>
                    <select id="gender" name="gender" required>
                        <option value="">Select gender</option>
                        <option value="Female">Female</option>
                        <option value="Male">Male</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="field">
                    <label for="date_of_birth">Date of birth</label>
                    <input type="date" id="date_of_birth"
                           name="date_of_birth"
                           max="<?= h(date('Y-m-d')) ?>">
                </div>

                <div class="field">
                    <label for="phone">Phone number</label>
                    <input id="phone" name="phone" maxlength="30">
                </div>

                <div class="field">
                    <label for="address">Address</label>
                    <input id="address" name="address" maxlength="255">
                </div>

                <div class="field">
                    <label for="next_of_kin">Next of kin</label>
                    <input id="next_of_kin" name="next_of_kin" maxlength="150">
                </div>

                <div class="field">
                    <label for="next_of_kin_phone">Next-of-kin phone</label>
                    <input id="next_of_kin_phone"
                           name="next_of_kin_phone" maxlength="30">
                </div>
            </div>

            <div class="form-actions">
                <button class="button" type="submit">
                    Register Patient →
                </button>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div class="panel-icon">🔎</div>
            <div>
                <h2>Registered Patients</h2>
                <p>Search the latest 100 matching patient records.</p>
            </div>
        </div>

        <form class="search-form" method="get" action="patients.php">
            <div class="search-field">
                <label for="search">Patient name or patient number</label>
                <input class="search-input" id="search" name="search"
                       value="<?= h($search) ?>"
                       placeholder="e.g. AZU-20261025-ABC123 or patient name">
            </div>

            <button class="button button-orange" type="submit">Search</button>
            <a class="button button-light" href="patients.php">Clear</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Patient Number</th>
                        <th>Patient Name</th>
                        <th>Gender</th>
                        <th>Phone</th>
                        <th>Registration Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$patients): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                No matching patients found.
                            </div>
                        </td>
                    </tr>
                
<?php else: ?>
    <?php foreach ($patients as $patient): ?>
        <tr>
            <td class="patient-number">
                <?= h($patient['patient_number']) ?>
            </td>

            <td class="patient-name">
                <?= h($patient['first_name'] . ' ' . $patient['last_name']) ?>
            </td>

            <td>
                <span class="gender-badge">
                    <?= h($patient['gender']) ?>
                </span>
            </td>

            <td><?= h($patient['phone'] ?? '') ?></td>

            <td><?= h($patient['created_at'] ?? '') ?></td>

            <td>
                <a class="button" href="edit_patient.php?id=<?= (int) $patient['id'] ?>">
                    Edit
                </a>
            </td>
        </tr>
    <?php endforeach; ?>

                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="table-note">
            For patient privacy, sign out when you have finished using the system.
        </p>
    </section>

    <footer class="footer">
        <strong>Africa Zeal University Clinic</strong><br>
        Patient Information Management System · Authorized staff only
    </footer>

</main>
</body>
</html>
